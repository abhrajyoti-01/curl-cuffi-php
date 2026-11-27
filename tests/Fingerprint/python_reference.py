"""Writes Python curl_cffi's fingerprints for the targets FingerprintTest checks.

CI-only parity check: `pip install curl_cffi && python python_reference.py out.json`.
The PHP library itself never uses Python.
"""
import json
import sys

from curl_cffi import requests

TARGETS = ["chrome", "chrome131", "edge101", "safari", "safari_ios", "firefox", "tor"]
URL = "https://tls.peet.ws/api/clean"

out = {}
for target in TARGETS:
    r = requests.get(URL, impersonate=target, timeout=30)
    out[target] = r.json()
    print(target, out[target].get("ja4"), out[target].get("akamai_hash"))

with open(sys.argv[1], "w") as f:
    json.dump(out, f, indent=2)
