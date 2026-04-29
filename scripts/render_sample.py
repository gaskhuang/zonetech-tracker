"""Render a sample report with synthetic data — for previewing the dashboard
before the real pipeline is connected. Run once locally:

    python scripts/render_sample.py
"""
from __future__ import annotations

import json
import random
import shutil
from datetime import date, timedelta
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs"
REPORTS = DOCS / "reports"


def gen():
    today = date.today()
    days = [today - timedelta(days=29 - i) for i in range(30)]
    rng = random.Random(42)

    gsc_daily = [
        {
            "date": d.isoformat(),
            "clicks": int(20 + i * 1.5 + rng.gauss(0, 5)),
            "impressions": int(800 + i * 40 + rng.gauss(0, 80)),
            "ctr": round(rng.uniform(2.1, 4.8), 2),
            "position": round(rng.uniform(8, 22), 1),
        }
        for i, d in enumerate(days)
    ]
    ga4_daily = [
        {
            "date": d.isoformat(),
            "activeUsers": float(int(80 + i * 4 + rng.gauss(0, 12))),
            "sessions": float(int(110 + i * 5 + rng.gauss(0, 15))),
            "screenPageViews": float(int(180 + i * 8 + rng.gauss(0, 25))),
        }
        for i, d in enumerate(days)
    ]
    bots = ["GPTBot", "ClaudeBot", "PerplexityBot", "Google-Extended", "OAI-SearchBot", "CCBot", "Amazonbot"]
    crawler_daily = []
    for d in days:
        row = {"date": d.isoformat(), "total": 0}
        for bot in bots:
            n = max(0, int(rng.gauss(8, 6)))
            if n:
                row[bot] = n
                row["total"] += n
        crawler_daily.append(row)
    today_row = crawler_daily[-1]

    return {
        "report_date": today.isoformat(),
        "generated_at": "sample preview",
        "windows": {
            "gsc_day": (today - timedelta(days=2)).isoformat(),
            "ga4_day": (today - timedelta(days=1)).isoformat(),
            "crawlers_day": (today - timedelta(days=1)).isoformat(),
        },
        "gsc": {
            "data_date": (today - timedelta(days=2)).isoformat(),
            "daily": gsc_daily,
            "top_queries": [
                {"query": "蓋斯克科技", "clicks": 14, "impressions": 312, "ctr": 4.49, "position": 6.2},
                {"query": "ai 爬蟲偵測", "clicks": 9, "impressions": 188, "ctr": 4.79, "position": 8.5},
                {"query": "wordpress gptbot", "clicks": 6, "impressions": 144, "ctr": 4.17, "position": 11.3},
                {"query": "gsc api 教學", "clicks": 4, "impressions": 95, "ctr": 4.21, "position": 12.0},
                {"query": "claude 爬蟲", "clicks": 3, "impressions": 62, "ctr": 4.84, "position": 14.7},
            ],
            "top_pages": [
                {"page": "https://zonetech.tw/blog/ai-crawler/", "clicks": 22, "impressions": 460, "ctr": 4.78, "position": 8.1},
                {"page": "https://zonetech.tw/", "clicks": 12, "impressions": 320, "ctr": 3.75, "position": 12.0},
                {"page": "https://zonetech.tw/blog/gsc-setup/", "clicks": 7, "impressions": 180, "ctr": 3.89, "position": 14.5},
            ],
        },
        "ga4": {
            "data_date": (today - timedelta(days=1)).isoformat(),
            "daily": ga4_daily,
            "top_pages": [
                {"pagePath": "/blog/ai-crawler/", "screenPageViews": 142, "activeUsers": 98, "averageSessionDuration": 124.5},
                {"pagePath": "/", "screenPageViews": 88, "activeUsers": 71, "averageSessionDuration": 45.2},
                {"pagePath": "/blog/gsc-setup/", "screenPageViews": 41, "activeUsers": 33, "averageSessionDuration": 88.1},
            ],
            "top_sources": [
                {"sessionSource": "google", "sessions": 95, "activeUsers": 78},
                {"sessionSource": "(direct)", "sessions": 42, "activeUsers": 35},
                {"sessionSource": "facebook.com", "sessions": 18, "activeUsers": 14},
            ],
        },
        "crawlers": {
            "data_date": (today - timedelta(days=1)).isoformat(),
            "total_today": today_row["total"],
            "by_bot_today": {b: today_row.get(b, 0) for b in bots if today_row.get(b)},
            "top_uris": [
                {"uri": "/blog/ai-crawler/", "hits": 14},
                {"uri": "/sitemap.xml", "hits": 9},
                {"uri": "/", "hits": 7},
                {"uri": "/blog/gsc-setup/", "hits": 5},
                {"uri": "/robots.txt", "hits": 4},
            ],
            "daily": crawler_daily,
            "raw_sample": [],
        },
        "aeo": {
            "source": "fallback",
            "score": 78,
            "checks": {
                "robots_txt_present": True,
                "llms_txt_present": False,
                "sitemap_xml_present": True,
                "home_reachable": True,
                "json_ld_present": True,
                "bots_allowed": {
                    "GPTBot": True, "ClaudeBot": True, "Google-Extended": True,
                    "PerplexityBot": True, "CCBot": True, "Applebot-Extended": True,
                    "Amazonbot": True, "Bytespider": False,
                },
            },
        },
        "errors": {"gsc": None, "ga4": None, "crawlers": None, "aeo": None},
    }


def main():
    env = Environment(loader=FileSystemLoader(str(ROOT / "templates")), autoescape=select_autoescape(["html"]))
    env.filters["tojson_data"] = lambda v: json.dumps(v, ensure_ascii=False, default=str)
    tpl = env.get_template("report.html.j2")
    html = tpl.render(**gen())
    DOCS.mkdir(exist_ok=True)
    REPORTS.mkdir(exist_ok=True)
    (DOCS / "index.html").write_text(html, encoding="utf-8")
    sample = REPORTS / "sample.html"
    sample.write_text(html, encoding="utf-8")
    print(f"wrote docs/index.html and {sample}")


if __name__ == "__main__":
    main()
