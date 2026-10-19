#!/usr/bin/env python3
"""Shared HTTP and JSON helpers for the ArcGIS importer scripts.

@module arcgis_common
@description Strict ArcGIS REST GET (HTTP status, content type, ArcGIS ``error``
    object and ``exceededTransferLimit`` checks; retries on transport errors and
    malformed JSON; SHA-256 of the raw bytes), layer URL validation and
    deterministic JSON writing.
@input  ArcGIS REST ``/query`` endpoints
@output Parsed JSON objects with raw-response SHA-256 digests; deterministic JSON files
@dependencies Python 3.10+ standard library only

Usage:  imported by scripts/fetch_arcgis_boundaries.py and scripts/fetch_arcgis_addresses.py.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Callable

ACCEPT = "application/json, application/geo+json"
Fetch = Callable[[dict], tuple]


def query_url(layer_url: Any, where: str = "layer_url") -> str:
    """Validate an ArcGIS layer URL (http/https only, never file:) and return its /query endpoint."""
    if not isinstance(layer_url, str) or not re.match(r"^https?://[^/\s]+/\S+$", layer_url.strip()):
        raise RuntimeError(f"{where} must be an http(s) ArcGIS layer URL, got {layer_url!r}")
    base = layer_url.strip().rstrip("/")
    return base if base.endswith("/query") else base + "/query"


def http_json(url: str, params: dict, *, user_agent: str, opener=urllib.request.urlopen,
              timeout: float = 45.0, attempts: int = 3, sleep=time.sleep) -> tuple[dict, str]:
    """GET ``url`` with ``params``, returning (parsed JSON object, sha256 of the raw bytes).

    Transport errors and malformed JSON are retried with backoff and then reported.
    A non-200 status, a non-JSON content type, an ArcGIS ``error`` object or a truncated
    (``exceededTransferLimit``) response raises immediately: those are never silently accepted.
    """
    full = url + "?" + urllib.parse.urlencode(params)
    last_error: Exception | None = None
    for attempt in range(attempts):
        try:
            req = urllib.request.Request(full, headers={"User-Agent": user_agent, "Accept": ACCEPT})
            with opener(req, timeout=timeout) as response:
                status = getattr(response, "status", 200)
                headers = getattr(response, "headers", None)
                ctype = headers.get("Content-Type", "") if headers else ""
                raw = response.read()
            if status != 200:
                raise RuntimeError(f"HTTP {status} from {full}")
            if ctype and "json" not in ctype.lower() and "text/plain" not in ctype.lower():
                raise RuntimeError(f"Unexpected content type {ctype!r} from {full}")
            data = json.loads(raw)
            if not isinstance(data, dict):
                raise RuntimeError(f"ArcGIS returned non-object JSON ({type(data).__name__})")
            if data.get("error"):
                raise RuntimeError(f"ArcGIS returned error: {data['error']}")
            if data.get("exceededTransferLimit") or (data.get("properties") or {}).get("exceededTransferLimit"):
                raise RuntimeError(f"ArcGIS response truncated (exceededTransferLimit) for {full}")
            return data, hashlib.sha256(raw).hexdigest()
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, UnicodeDecodeError) as ex:
            last_error = ex
            if attempt < attempts - 1:
                sleep(2 ** attempt)
    raise RuntimeError(f"Official GIS query unavailable: {full}: {last_error}")


def make_fetch(layer_url: str, user_agent: str, timeout: float = 45.0) -> Fetch:
    """Bind http_json to one layer's /query endpoint: fetch(params) -> (data, sha256)."""
    url = query_url(layer_url)
    return lambda params: http_json(url, params, user_agent=user_agent, timeout=timeout)


def write_json(path: Path, data: dict, compact: bool = True) -> str:
    """Deterministic JSON (sorted keys). GeoJSON is compact; manifests are indented."""
    path.parent.mkdir(parents=True, exist_ok=True)
    if compact:
        content = json.dumps(data, sort_keys=True, ensure_ascii=False, separators=(",", ":")) + "\n"
    else:
        content = json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n"
    path.write_text(content, encoding="utf-8")
    return hashlib.sha256(content.encode("utf-8")).hexdigest()
