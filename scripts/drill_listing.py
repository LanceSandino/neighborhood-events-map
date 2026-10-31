#!/usr/bin/env python3
"""
@module scripts/drill_listing
@description Resident steps of the rollback drill (bin/drill-event-upgrade.sh) against a disposable stack: sign in
    with a real proof-of-work and the one-time link from the stack's Mailpit, then edit a listing (PATCH) or list a
    new self-entered home (POST /api/me/homes) with a pin placed inside the approved boundary, away from every other
    home. Works against the 0.9 and the 0.11 API (the same endpoints). Prints one JSON line with the outcome.
@dependencies Python 3 standard library only
Usage:
  python3 scripts/drill_listing.py --base http://localhost:8198 --mailpit http://localhost:8197 --email demo-resident-01@example.invalid edit --home-id 1 --note "Drill edit"
  python3 scripts/drill_listing.py ... claim --house 901 --street "Moonbeam Dr" --note "Listed in the drill"
"""
import argparse
import hashlib
import http.cookiejar
import json
import math
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def leading_zero_bits(digest: bytes) -> int:
    bits = 0
    for byte in digest:
        if byte == 0:
            bits += 8
            continue
        return bits + 8 - byte.bit_length()
    return bits


def solve(purpose: str, challenge: dict) -> str:
    nonce = 0
    while leading_zero_bits(hashlib.sha256(f"{purpose}:{challenge['id']}:{challenge['salt']}:{nonce}".encode()).digest()) < challenge["difficulty"]:
        nonce += 1
    return str(nonce)


def inside(lon: float, lat: float, ring: list) -> bool:
    """Even-odd point-in-polygon on one ring of [lon, lat] points."""
    hit = False
    for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1]):
        if (y1 > lat) != (y2 > lat) and lon < (x2 - x1) * (lat - y1) / (y2 - y1) + x1:
            hit = not hit
    return hit


def metres(a: tuple, b: tuple) -> float:
    lat = math.radians((a[1] + b[1]) / 2)
    return math.hypot((a[0] - b[0]) * 111320 * math.cos(lat), (a[1] - b[1]) * 110540)


def pick_pin(ring: list, homes: list, steps: int = 40) -> tuple:
    """The grid point inside the ring farthest from every home ([lon, lat] pairs); deterministic."""
    xs = [p[0] for p in ring]
    ys = [p[1] for p in ring]
    best, best_d = None, -1.0
    for i in range(1, steps):
        for j in range(1, steps):
            p = (min(xs) + (max(xs) - min(xs)) * i / steps, min(ys) + (max(ys) - min(ys)) * j / steps)
            if not inside(p[0], p[1], ring):
                continue
            d = min((metres(p, h) for h in homes), default=1e9)
            if d > best_d:
                best, best_d = p, d
    if best is None:
        raise ValueError("no point inside the boundary")
    return best


class Client:
    def __init__(self, base: str):
        self.base = base.rstrip("/")
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ""

    def req(self, method: str, path: str, body=None, form=None):
        headers = {"Accept": "application/json", "Origin": self.base}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        elif form is not None:
            data = urllib.parse.urlencode(form).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        if method != "GET" and self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        r = urllib.request.Request(self.base + path, data=data, method=method, headers=headers)
        try:
            resp = self.opener.open(r, timeout=30)
        except urllib.error.HTTPError as e:
            resp = e
        text = resp.read().decode()
        try:
            return resp.status, json.loads(text) if text.strip().startswith(("{", "[")) else text
        except json.JSONDecodeError:
            return resp.status, text

    @staticmethod
    def _mail_ids(mailpit: str, email: str) -> list:
        found = json.loads(urllib.request.urlopen(f"{mailpit}/api/v1/search?query={urllib.parse.quote('to:' + email)}", timeout=10).read())
        return [m["ID"] for m in found.get("messages", [])]

    def sign_in(self, mailpit: str, email: str) -> None:
        seen = set(self._mail_ids(mailpit, email))
        _, ch = self.req("GET", "/api/pow?purpose=login")
        c = ch["challenge"]
        st, body = self.req("POST", "/api/auth/request-link", {"email": email, "pow": {"id": c["id"], "nonce": solve("login", c)}})
        if st != 202:
            raise RuntimeError(f"request-link answered {st}: {body}")
        token = None
        for _ in range(60):
            fresh = [i for i in self._mail_ids(mailpit, email) if i not in seen]
            if fresh:
                full = json.loads(urllib.request.urlopen(f"{mailpit}/api/v1/message/{fresh[0]}", timeout=10).read())
                hit = re.search(r"/auth/confirm\?t=([A-Za-z0-9_-]{43})", full["Text"])
                token = hit.group(1) if hit else None
                break
            time.sleep(0.5)
        if not token:
            raise RuntimeError("no sign-in email in Mailpit")
        _, page = self.req("GET", f"/auth/confirm?t={token}")
        form_csrf = re.search(r'name="_csrf" value="([^"]+)"', page).group(1)
        self.req("POST", "/auth/confirm", form={"t": token, "_csrf": form_csrf})
        _, me_page = self.req("GET", "/me")
        self.csrf = re.search(r'name="csrf-token" content="([^"]+)"', me_page).group(1)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True)
    ap.add_argument("--mailpit", required=True)
    ap.add_argument("--email", required=True)
    sub = ap.add_subparsers(dest="cmd", required=True)
    e = sub.add_parser("edit")
    e.add_argument("--home-id", type=int, required=True)
    e.add_argument("--note", required=True)
    c = sub.add_parser("claim")
    c.add_argument("--house", required=True)
    c.add_argument("--street", required=True)
    c.add_argument("--note", required=True)
    a = ap.parse_args()

    client = Client(a.base)
    client.sign_in(a.mailpit, a.email)
    if a.cmd == "edit":
        st, body = client.req("PATCH", f"/api/me/homes/{a.home_id}", {"note": a.note})
        print(json.dumps({"step": "edit", "status": st, "note": (body.get("home") or {}).get("listing", {}).get("note") if isinstance(body, dict) else None}))
        return 0 if st == 200 else 1
    _, community = client.req("GET", "/api/community")
    _, homes = client.req("GET", "/api/homes")
    ring = community["map"]["boundary"]["coordinates"][0] if community["map"]["boundary"]["type"] == "Polygon" else community["map"]["boundary"]["coordinates"][0][0]
    lon, lat = pick_pin(ring, [(h["lon"], h["lat"]) for h in homes["homes"]])
    st, body = client.req("POST", "/api/me/homes", {"address": {"house_number": a.house, "street": a.street, "lat": round(lat, 6), "lon": round(lon, 6)},
                                                    "listing": {"candy": True, "start": "17:30", "end": "20:00", "note": a.note}})
    print(json.dumps({"step": "claim", "status": st, "address_id": body.get("address_id") if isinstance(body, dict) else None}))
    return 0 if st == 201 else 1


if __name__ == "__main__":
    sys.exit(main())
