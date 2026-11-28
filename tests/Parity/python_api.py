"""Dumps the public API of Python curl_cffi as JSON: the options every call
accepts, the public members of each class, the exceptions, the enums and the
exported names. ApiParityTest.php then checks that the PHP port has each one.

It reads curl_cffi's source with `ast`, so nothing from the package is
imported or run:

    pip install "curl_cffi==$(cat tests/Parity/CURL_CFFI_VERSION)"
    python -I tests/Parity/python_api.py > tests/Parity/python_api.json

or, for an unpacked wheel: python -I tests/Parity/python_api.py path/to/curl_cffi
"""

import ast
import importlib.metadata
import importlib.util
import json
import os
import re
import sys


def package_dir():
    if len(sys.argv) > 1:
        return sys.argv[1]
    spec = importlib.util.find_spec("curl_cffi")  # finds, does not import
    if spec is None or not spec.submodule_search_locations:
        sys.exit("curl_cffi is not installed")
    return list(spec.submodule_search_locations)[0]


def package_version(root):
    parent = os.path.dirname(os.path.abspath(root))
    for name in sorted(os.listdir(parent)):
        m = re.match(r"curl_cffi-([^-]+)\.dist-info$", name)
        if m:
            return m.group(1)
    return importlib.metadata.version("curl_cffi")


ROOT = package_dir()
MODULES = {}


def module(rel):
    if rel not in MODULES:
        with open(os.path.join(ROOT, rel), encoding="utf-8") as f:
            MODULES[rel] = ast.parse(f.read(), rel)
    return MODULES[rel]


def walk_classes(tree):
    """Every class in a module, including ones under `if TYPE_CHECKING:`."""
    for node in ast.walk(tree):
        if isinstance(node, ast.ClassDef):
            yield node


def find_class(rel, name):
    for node in walk_classes(module(rel)):
        if node.name == name:
            return node
    raise KeyError(f"{rel}: class {name} not found")


def find_function(rel, name):
    for node in module(rel).body:
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name == name:
            return node
    raise KeyError(f"{rel}: function {name} not found")


def method(cls, name):
    for node in cls.body:
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name == name:
            return node
    raise KeyError(f"{cls.name}.{name} not found")


def base_names(cls):
    out = []
    for b in cls.bases:
        if isinstance(b, ast.Subscript):
            b = b.value
        if isinstance(b, ast.Name):
            out.append(b.id)
        elif isinstance(b, ast.Attribute):
            out.append(b.attr)
    return out


def typed_dict_fields(rel, name):
    """Fields of a TypedDict, its TypedDict bases first. Imported ones come from session.py."""
    try:
        cls = find_class(rel, name)
    except KeyError:
        rel = "requests/session.py"
        cls = find_class(rel, name)
    fields = []
    for base in base_names(cls):
        if base not in ("TypedDict", "Generic"):
            fields += typed_dict_fields(rel, base)
    for node in cls.body:
        if isinstance(node, ast.AnnAssign) and isinstance(node.target, ast.Name):
            fields.append(node.target.id)
    return fields


def unpacked_name(annotation):
    """`Unpack[RequestParams]` or `Unpack[BaseSessionParams[R]]` -> the TypedDict name."""
    if isinstance(annotation, ast.Subscript) and getattr(annotation.value, "id", None) == "Unpack":
        inner = annotation.slice
        if isinstance(inner, ast.Subscript):
            inner = inner.value
        if isinstance(inner, ast.Name):
            return inner.id
    return None


def params(rel, fn, skip=()):
    """Parameter names of a function, with **kwargs: Unpack[TypedDict] expanded."""
    a = fn.args
    names = [x.arg for x in a.posonlyargs + a.args + a.kwonlyargs]
    if a.vararg:
        names.append("*" + a.vararg.arg)
    if a.kwarg:
        td = unpacked_name(a.kwarg.annotation)
        names += typed_dict_fields(rel, td) if td else ["**" + a.kwarg.arg]
    out = []
    for n in names:
        if n not in ("self", "cls") and n not in skip and n not in out:
            out.append(n)
    return out


def is_property(fn):
    return any(
        (isinstance(d, ast.Name) and d.id in ("property", "cached_property"))
        or (isinstance(d, ast.Attribute) and d.attr in ("setter", "getter"))
        for d in fn.decorator_list
    )


def members(rel, name, seen=None):
    """Public methods, properties and attributes of a class and its bases in the same module."""
    cls = find_class(rel, name)
    out = {}
    for base in base_names(cls):
        try:
            out.update(members(rel, base))
        except KeyError:
            pass
    for node in cls.body:
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            if node.name.startswith("_"):
                if node.name == "__init__":
                    for sub in ast.walk(node):
                        if (
                            isinstance(sub, (ast.Assign, ast.AnnAssign))
                            and not isinstance(sub, ast.AugAssign)
                        ):
                            targets = sub.targets if isinstance(sub, ast.Assign) else [sub.target]
                            for t in targets:
                                if (
                                    isinstance(t, ast.Attribute)
                                    and isinstance(t.value, ast.Name)
                                    and t.value.id == "self"
                                    and not t.attr.startswith("_")
                                ):
                                    out.setdefault(t.attr, "attribute")
                continue
            if is_property(node):
                out[node.name] = "property"
            else:
                out[node.name] = "async method" if isinstance(node, ast.AsyncFunctionDef) else "method"
        elif isinstance(node, ast.AnnAssign) and isinstance(node.target, ast.Name):
            if not node.target.id.startswith("_") and "ClassVar" not in ast.unparse(node.annotation):
                out.setdefault(node.target.id, "field")
    return dict(sorted(out.items()))


def literal(node):
    """Value of a constant expression such as `10000 + 1` or `1 << 4`, else None."""
    try:
        return ast.literal_eval(node)
    except ValueError:
        pass
    if isinstance(node, ast.BinOp):
        left, right = literal(node.left), literal(node.right)
        if isinstance(left, int) and isinstance(right, int):
            ops = {ast.Add: left + right, ast.Sub: left - right, ast.BitOr: left | right}
            if isinstance(node.op, ast.LShift):
                return left << right
            return ops.get(type(node.op))
    return None


def enum_members(rel, name):
    cls = find_class(rel, name)
    out = {}
    for node in cls.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            key = node.targets[0].id
            if key.startswith("_"):
                continue
            value = literal(node.value)
            if value is None and isinstance(node.value, ast.Name) and node.value.id in out:
                value = out[node.value.id]  # alias of an earlier member
            out[key] = value
    return out


def exceptions():
    out = {}
    for rel, names in [
        ("requests/errors.py", None),
        ("requests/exceptions.py", None),
        ("requests/websockets.py", ["WebSocketError", "WebSocketClosed", "WebSocketTimeout"]),
        ("fingerprints.py", ["FingerprintUpdateError"]),
        ("curl.py", ["CurlError"]),
    ]:
        for cls in walk_classes(module(rel)):
            if names is not None and cls.name not in names:
                continue
            bases = base_names(cls)
            if names is None and not any(b.endswith(("Error", "Exception", "Warning")) or b == "CurlError" for b in bases):
                continue
            out[cls.name] = bases
    return dict(sorted(out.items()))


def exports(rel):
    for node in module(rel).body:
        if isinstance(node, ast.Assign) and getattr(node.targets[0], "id", None) == "__all__":
            return ast.literal_eval(node.value)
    return []


def cli_reference():
    """Subcommands of the curl-cffi command, and the options, positionals and
    defaults each one takes, from the argparse calls in curl_cffi/cli."""
    methods = None
    for node in module("cli/parse.py").body:
        if isinstance(node, ast.Assign) and getattr(node.targets[0], "id", None) == "SUPPORTED_METHODS":
            methods = sorted(ast.literal_eval(node.value))
    helpers = {
        "_add_common_flags": find_function("cli/__init__.py", "_add_common_flags"),
        "_add_request_positionals": find_function("cli/__init__.py", "_add_request_positionals"),
    }
    commands, arguments, defaults = [], {}, {}

    def calls_in(fn):
        calls = [n for n in ast.walk(fn) if isinstance(n, ast.Call)]
        return sorted(calls, key=lambda n: (n.lineno, n.col_offset))

    def add_arguments(fn, into):
        for call in calls_in(fn):
            if not (isinstance(call.func, ast.Attribute) and call.func.attr == "add_argument"):
                continue
            names = [a.value for a in call.args if isinstance(a, ast.Constant)]
            kw = {k.arg: k.value for k in call.keywords}
            if not names[0].startswith("-"):
                into.append(literal(kw["metavar"]) if "metavar" in kw else names[0])
                continue
            boolean = "action" in kw and ast.unparse(kw["action"]).endswith("BooleanOptionalAction")
            for n in names:
                into.append(n)
                if boolean and n.startswith("--"):
                    into.append("--no-" + n[2:])
            if "default" in kw:
                defaults[names[0]] = literal(kw["default"])

    def walk(fn):
        assigned = {
            id(n.value): n.targets[0].id
            for n in ast.walk(fn)
            if isinstance(n, ast.Assign) and isinstance(n.targets[0], ast.Name)
        }
        parsers = {}  # variable -> subcommand
        for call in calls_in(fn):
            f = call.func
            if isinstance(f, ast.Attribute) and f.attr == "add_parser":
                first = call.args[0]
                if isinstance(first, ast.Constant):
                    names = [first.value]
                else:  # the loop over SUPPORTED_METHODS, lower then upper case
                    names = [n for m in methods for n in (m.lower(), m)]
                commands.extend(names)
                key = names[0] if len(names) == 1 else "<verb>"
                arguments.setdefault(key, ["-h", "--help"])  # argparse's own help option
                if id(call) in assigned:
                    parsers[assigned[id(call)]] = key
            elif isinstance(f, ast.Name) and f.id in helpers and call.args and isinstance(call.args[0], ast.Name):
                add_arguments(helpers[f.id], arguments[parsers[call.args[0].id]])
            elif isinstance(f, ast.Name) and f.id == "add_pro_parsers":
                walk(find_function("cli/pro.py", "add_pro_parsers"))
            elif isinstance(f, ast.Attribute) and f.attr == "add_argument" and isinstance(f.value, ast.Name):
                target = parsers.get(f.value.id)
                if target is not None:
                    one = ast.Module(body=[ast.Expr(call)], type_ignores=[])
                    add_arguments(one, arguments[target])

    walk(find_function("cli/__init__.py", "build_parser"))
    return {"commands": commands, "arguments": arguments, "defaults": defaults}


session_cls = find_class("requests/session.py", "Session")
async_cls = find_class("requests/session.py", "AsyncSession")
ws_cls = find_class("requests/websockets.py", "WebSocket")
aws_cls = find_class("requests/websockets.py", "AsyncWebSocket")
URL_ARGS = ("method", "url")

reference = {
    "curl_cffi_version": package_version(ROOT),
    "options": {
        "Session.__init__": params("requests/session.py", method(session_cls, "__init__")),
        "AsyncSession.__init__": params("requests/session.py", method(async_cls, "__init__")),
        "Session.request": params("requests/session.py", method(session_cls, "request"), URL_ARGS),
        "AsyncSession.request": params("requests/session.py", method(async_cls, "request"), URL_ARGS),
        "Session.stream": params("requests/session.py", method(session_cls, "stream"), URL_ARGS),
        "AsyncSession.stream": params("requests/session.py", method(async_cls, "stream"), URL_ARGS),
        "requests.request": params("requests/__init__.py", find_function("requests/__init__.py", "request"), URL_ARGS),
        "Session.ws_connect": params("requests/session.py", method(session_cls, "ws_connect"), ("url",)),
        "AsyncSession.ws_connect": params("requests/session.py", method(async_cls, "ws_connect"), ("url",)),
        "WebSocket.__init__": params("requests/websockets.py", method(ws_cls, "__init__")),
        "WebSocket.connect": params("requests/websockets.py", method(ws_cls, "connect"), ("url",)),
        "WebSocket.run_forever": params("requests/websockets.py", method(ws_cls, "run_forever"), ("url",)),
        "AsyncWebSocket.__init__": params("requests/websockets.py", method(aws_cls, "__init__")),
    },
    "classes": {
        "Session": members("requests/session.py", "Session"),
        "AsyncSession": members("requests/session.py", "AsyncSession"),
        "RetryStrategy": members("requests/session.py", "RetryStrategy"),
        "Request": members("requests/models.py", "Request"),
        "Response": members("requests/models.py", "Response"),
        "Headers": members("requests/headers.py", "Headers"),
        "Cookies": members("requests/cookies.py", "Cookies"),
        "WebSocket": members("requests/websockets.py", "WebSocket"),
        "AsyncWebSocket": members("requests/websockets.py", "AsyncWebSocket"),
        "WebSocketRetryStrategy": members("requests/websockets.py", "WebSocketRetryStrategy"),
        "ExtraFingerprints": members("requests/impersonate.py", "ExtraFingerprints"),
        "CacheBackend": members("requests/cache.py", "CacheBackend"),
        "FileCacheBackend": members("requests/cache.py", "FileCacheBackend"),
        "Curl": members("curl.py", "Curl"),
        "CurlMime": members("curl.py", "CurlMime"),
        "CurlShare": members("curl.py", "CurlShare"),
        "CurlWsFrame": members("curl.py", "CurlWsFrame"),
        "AsyncCurl": members("aio.py", "AsyncCurl"),
        "Fingerprint": members("fingerprints.py", "Fingerprint"),
        "FingerprintManager": members("fingerprints.py", "FingerprintManager"),
    },
    "functions": {
        "requests": [
            n.name for n in module("requests/__init__.py").body
            if isinstance(n, ast.FunctionDef) and not n.name.startswith("_")
        ],
    },
    "exceptions": exceptions(),
    "enums": {
        **{
            c.name: enum_members("const.py", c.name)
            for c in module("const.py").body
            if isinstance(c, ast.ClassDef)
        },
        "WsCloseCode": enum_members("requests/websockets.py", "WsCloseCode"),
        "BrowserType": enum_members("requests/impersonate.py", "BrowserType"),
    },
    "exports": {
        "curl_cffi": exports("__init__.py"),
        "curl_cffi.requests": exports("requests/__init__.py"),
    },
    "cli": cli_reference(),
}

json.dump(reference, sys.stdout, indent=1, sort_keys=False)
sys.stdout.write("\n")
