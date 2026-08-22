#!/usr/bin/env python3
"""Extract publicly listed navigation entries from a saved 862100.com HTML page."""

import json
import re
from pathlib import Path
from urllib.parse import urlparse

from bs4 import BeautifulSoup


SOURCE = Path("/home/ubuntu/browser_html/862100_com_page_1787305521472.html")
OUTPUT = Path("/home/ubuntu/site-navigator/research/862100-candidates-raw.json")
CATEGORIES = {
    "动漫网站",
    "高清影院",
    "电影资讯",
    "电影搜索",
    "影片下载",
    "影视资源",
}


def is_external_url(href):
    parsed = urlparse(href)
    return parsed.scheme in {"http", "https"} and bool(parsed.netloc) and parsed.netloc != "www.862100.com"


def text_or_empty(node):
    return " ".join(node.get_text(" ", strip=True).split()) if node else ""


def main():
    soup = BeautifulSoup(SOURCE.read_text(encoding="utf-8"), "html.parser")
    candidates = []

    for element in soup.select("div.xe-widget"):
        category_heading = element.find_previous("h4")
        current_category = text_or_empty(category_heading)
        if current_category not in CATEGORIES:
            continue

        onclick = element.get("onclick") or ""
        match = re.search(r"window\.open\(['\"]([^'\"]+)", onclick)
        href = match.group(1).strip() if match else (element.get("data-original-title") or "").strip()
        if not is_external_url(href):
            continue

        title_node = element.select_one(".xe-user-name strong")
        title = text_or_empty(title_node)
        description = text_or_empty(element.select_one("p.overflowClip_2"))

        candidates.append({
            "category": current_category,
            "title": title,
            "description": description,
            "url": href,
        })

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(candidates, ensure_ascii=False, indent=2), encoding="utf-8")
    summary = {}
    for item in candidates:
        summary[item["category"]] = summary.get(item["category"], 0) + 1
    print(json.dumps({"count": len(candidates), "by_category": summary, "output": str(OUTPUT)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
