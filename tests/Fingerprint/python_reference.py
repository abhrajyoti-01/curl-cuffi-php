"""Writes Python curl_cffi's fingerprints for the targets FingerprintTest checks,
plus one request with the custom ja3 / akamai / extra_fp in custom.json.

CI-only parity check: `pip install curl_cffi && python python_reference.py out.json`.
The PHP library itself never uses Python.
"""
import json
import os
import sys

from curl_cffi import requests

TARGETS = ["chrome", "chrome131", "edge101", "safari", "safari_ios", "firefox", "tor"]
URL = "https://tls.peet.ws/api/clean"

out = {}
for target in TARGETS:
    r = requests.get(URL, impersonate=target, timeout=30)
    out[target] = r.json()
    print(target, out[target].get("ja4"), out[target].get("akamai_hash"))

with open(os.path.join(os.path.dirname(__file__), "custom.json")) as f:
    custom = json.load(f)
r = requests.get(URL, ja3=custom["ja3"], akamai=custom["akamai"], extra_fp=custom["extra_fp"], timeout=30)
out["custom"] = r.json()
print("custom", out["custom"].get("ja3_hash"), out["custom"].get("ja4"), out["custom"].get("akamai_hash"))

with open(sys.argv[1], "w") as f:
    json.dump(out, f, indent=2)
