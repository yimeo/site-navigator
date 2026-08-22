#!/usr/bin/env python3
"""Normalize and de-duplicate candidates extracted from 862100.com's public homepage."""

import json
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import urlparse


SOURCE = Path("/home/ubuntu/site-navigator/research/862100-candidates-raw.json")
OUTPUT = Path("/home/ubuntu/site-navigator/research/862100-candidates-clean.json")
SUMMARY = Path("/home/ubuntu/site-navigator/research/862100-candidates-summary.json")


def normalized_host(url):
    host = (urlparse(url).hostname or "").lower().rstrip(".")
    return host[4:] if host.startswith("www.") else host


def clean_text(value, limit):
    return " ".join((value or "").split())[:limit]


def main():
    raw = json.loads(SOURCE.read_text(encoding="utf-8"))
    seen_hosts = set()
    clean = []
    skipped = []

    for item in raw:
        url = (item.get("url") or "").strip()
        parsed = urlparse(url)
        host = normalized_host(url)
        if parsed.scheme not in {"http", "https"} or not host:
            skipped.append({"reason": "invalid_url", "item": item})
            continue
        if host in seen_hosts:
            skipped.append({"reason": "duplicate_domain", "item": item})
            continue
        seen_hosts.add(host)
        clean.append({
            "category": clean_text(item.get("category"), 80),
            "title": clean_text(item.get("title"), 120) or host,
            "description": clean_text(item.get("description"), 500),
            "primary_url": url,
            "host": host,
        })

    by_category = Counter(item["category"] for item in clean)
    output = {
        "source_count": len(raw),
        "accepted_count": len(clean),
        "skipped_count": len(skipped),
        "by_category": dict(sorted(by_category.items())),
        "skip_reasons": dict(sorted(Counter(item["reason"] for item in skipped).items())),
    }
    OUTPUT.write_text(json.dumps(clean, ensure_ascii=False, indent=2), encoding="utf-8")
    SUMMARY.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(output, ensure_ascii=False))


if __name__ == "__main__":
    main()
