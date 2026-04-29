"""Fetch Search Console metrics via the Search Analytics API.

Returns a dict with:
  - daily: [{date, clicks, impressions, ctr, position}, ...]   (last 30 days)
  - top_queries: top 10 queries on gsc_day
  - top_pages: top 10 pages on gsc_day
  - data_date: ISO string of the day the "today" numbers refer to (D-2)
"""
from __future__ import annotations

from typing import Any

import google.auth
from googleapiclient.discovery import build

from shared import DateWindows, env, iso

SCOPES = ["https://www.googleapis.com/auth/webmasters.readonly"]


def _client():
    creds, _ = google.auth.load_credentials_from_file(
        env("GOOGLE_APPLICATION_CREDENTIALS", required=True), scopes=SCOPES
    )
    return build("searchconsole", "v1", credentials=creds, cache_discovery=False)


def _query(svc, site: str, body: dict) -> list[dict]:
    return (
        svc.searchanalytics().query(siteUrl=site, body=body).execute().get("rows", [])
    )


def fetch(windows: DateWindows) -> dict[str, Any]:
    site = env("GSC_SITE_URL", required=True)
    svc = _client()

    daily_rows = _query(
        svc,
        site,
        {
            "startDate": iso(windows.trend_start),
            "endDate": iso(windows.gsc_day),
            "dimensions": ["date"],
            "rowLimit": 200,
        },
    )
    daily = [
        {
            "date": r["keys"][0],
            "clicks": int(r.get("clicks", 0)),
            "impressions": int(r.get("impressions", 0)),
            "ctr": round(float(r.get("ctr", 0)) * 100, 2),
            "position": round(float(r.get("position", 0)), 2),
        }
        for r in daily_rows
    ]

    queries = _query(
        svc,
        site,
        {
            "startDate": iso(windows.gsc_day),
            "endDate": iso(windows.gsc_day),
            "dimensions": ["query"],
            "rowLimit": 10,
        },
    )
    pages = _query(
        svc,
        site,
        {
            "startDate": iso(windows.gsc_day),
            "endDate": iso(windows.gsc_day),
            "dimensions": ["page"],
            "rowLimit": 10,
        },
    )

    def shape(rows: list[dict], key: str) -> list[dict]:
        return [
            {
                key: r["keys"][0],
                "clicks": int(r.get("clicks", 0)),
                "impressions": int(r.get("impressions", 0)),
                "ctr": round(float(r.get("ctr", 0)) * 100, 2),
                "position": round(float(r.get("position", 0)), 2),
            }
            for r in rows
        ]

    return {
        "data_date": iso(windows.gsc_day),
        "daily": daily,
        "top_queries": shape(queries, "query"),
        "top_pages": shape(pages, "page"),
    }


if __name__ == "__main__":
    import json
    from shared import today_tpe

    print(json.dumps(fetch(DateWindows(today_tpe())), indent=2, ensure_ascii=False))
