#!/usr/bin/env python3
"""Black-box smoke test of the sign-in/session lifecycle against a running dev stack.

@module smoke_session
@description Solves a real PoW, requests a link, reads it from Mailpit, checks that GET does not sign in,
    confirms by POST (as if behind an HTTPS tunnel), verifies cookie flags, restarts the app container to
    prove sessions are durable in PostgreSQL, then logs out and proves the old cookie is dead.
@dependencies Python 3 stdlib, docker compose (for the restart step)
Usage: python3 scripts/smoke_session.py [--base http://localhost:8087] [--mailpit http://localhost:8025]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost:8087")
    ap.add_argument("--mailpit", default="http://localhost:8025")
    ap.add_argument("--compose-args", default="", help="extra args for docker compose (e.g. --env-file .env.demo)")
    a = ap.parse_args()
    # Cookies are handled by hand: behind the (simulated) HTTPS proxy they are Secure, and a cookie jar
    # would refuse to send them to this plain-http test URL.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):  # keep 3xx responses (and their Set-Cookie) visible
            return None

    opener = urllib.request.build_opener(NoRedirect())
    headers = {"Origin": a.base, "X-Forwarded-Proto": "https", "Accept": "application/json"}

    def remember(resp_headers):
        for c in resp_headers.get_all("Set-Cookie") or []:
            if c.lower().startswith("nhm_session="):
                value = c.split(";")[0]
                if value.endswith("=deleted") or "max-age=0" in c.lower():
                    headers.pop("Cookie", None)
                else:
                    headers["Cookie"] = value

    def req(method, path, body=None, extra=None, raw=False):
        data = None if body is None else (json.dumps(body).encode() if isinstance(body, dict) else body)
        h = {**headers, **(extra or {})}
        if isinstance(body, dict):
            h["Content-Type"] = "application/json"
        r = urllib.request.Request(a.base + path, data=data, method=method, headers=h)
        try:
            resp = opener.open(r, timeout=30)
        except urllib.error.HTTPError as e:
            resp = e
        content = resp.read().decode()
        remember(resp.headers)
        return resp.status, resp.headers, (content if raw else (json.loads(content) if content.strip().startswith("{") else content))

    checks = []

    def check(name, ok, detail=""):
        checks.append((name, bool(ok)))
        print(("PASS " if ok else "FAIL ") + name + (f" — {detail}" if detail and not ok else ""))

    email = f"smoke-{int(time.time())}@example.test"
    _, _, ch = req("GET", "/api/pow?purpose=login")
    c = ch["challenge"]
    nonce = 0
    while True:
        d = hashlib.sha256(f"login:{c['id']}:{c['salt']}:{nonce}".encode()).digest()
        bits = 0
        for byte in d:
            if byte == 0:
                bits += 8
                continue
            bits += 8 - byte.bit_length()
            break
        if bits >= c["difficulty"]:
            break
        nonce += 1
    st, _, body = req("POST", "/api/auth/request-link", {"email": email, "pow": {"id": c["id"], "nonce": str(nonce)}})
    check("request-link accepted (202) with real PoW", st == 202, f"{st} {body}")

    link = None
    for _ in range(30):
        s = json.loads(urllib.request.urlopen(f"{a.mailpit}/api/v1/search?query={urllib.parse.quote('to:' + email)}").read())
        if s.get("messages"):
            full = json.loads(urllib.request.urlopen(f"{a.mailpit}/api/v1/message/{s['messages'][0]['ID']}").read())
            m = re.search(r"/auth/confirm\?t=([A-Za-z0-9_-]{43})", full["Text"])
            link = m.group(1) if m else None
            break
        time.sleep(0.5)
    check("email delivered to Mailpit with one-time link", link)

    st, _, page = req("GET", f"/auth/confirm?t={link}", raw=True)
    st2, _, _ = req("GET", "/api/me")
    check("GET confirm renders but does not sign in", st == 200 and st2 == 401)
    csrf = re.search(r'name="_csrf" value="([^"]+)"', page).group(1)
    form = urllib.parse.urlencode({"t": link, "_csrf": csrf}).encode()
    st, hdrs, _ = req("POST", "/auth/confirm", form, {"Content-Type": "application/x-www-form-urlencoded"}, raw=True)
    cookies = hdrs.get_all("Set-Cookie") or []
    sess = next((x for x in cookies if x.lower().startswith("nhm_session=")), "")
    check("POST confirm signs in (303 → /me)", st in (200, 303), str(st))
    check("session cookie is Secure behind HTTPS proxy", "secure" in sess.lower(), sess)
    check("session cookie is HttpOnly", "httponly" in sess.lower(), sess)
    check("session cookie is SameSite=Lax", "samesite=lax" in sess.lower(), sess)

    st, _, me = req("GET", "/api/me")
    check("signed-in /api/me works", st == 200 and me.get("user", {}).get("email") == email, str(st))

    subprocess.run(f"docker compose {a.compose_args} restart app".split(), check=True, capture_output=True)
    for _ in range(60):
        try:
            if urllib.request.urlopen(a.base + "/healthz", timeout=5).status == 200:
                break
        except Exception:  # noqa: BLE001
            time.sleep(1)
    st, _, me = req("GET", "/api/me")
    check("session survives app container restart (PostgreSQL sessions)", st == 200, str(st))

    _, _, page = req("GET", "/me", raw=True)
    stolen = headers.get("Cookie")
    token = re.search(r'name="csrf-token" content="([^"]+)"', page).group(1)
    st, _, _ = req("POST", "/api/auth/logout", None, {"X-CSRF-Token": token})
    check("logout succeeds", st == 200, str(st))
    headers["Cookie"] = stolen  # replay the pre-logout cookie
    st, _, body = req("GET", "/api/me")
    check("old cookie is rejected after logout (server-side revocation)", st == 401, f"{st} {body}")

    st, _, page = req("GET", f"/auth/confirm?t={link}", raw=True)
    check("used link cannot be reused", st == 410 and "already used" in page, str(st))

    failed = [n for n, ok in checks if not ok]
    print(f"\n{len(checks) - len(failed)}/{len(checks)} checks passed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
