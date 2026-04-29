"""Pull AI crawler hits from the WP REST endpoint.

Aggregates yesterday's hits + a 30-day daily series (one call per day, cached
in docs/data/crawlers-YYYY-MM-DD.json so historical days don't re-fetch).
"""
from __future__ import annotations

from collections import Counter
from datetime import timedelta
from typing import Any

import requests

from shared import DATA_DIR, DateWindows, env, iso, read_json, write_json


def _fetch_day(date_iso: str) -> list[dict]:
    endpoint = env("WP_LOG_ENDPOINT", required=True)
    token = env("WP_LOG_TOKEN", required=True)
    r = requests.get(
        endpoint,
        params={"date": date_iso},
        headers={"Authorization": f"Bearer {token}"},
        timeout=30,
    )
    r.raise_for_status()
    return r.json().get("rows", [])


def _cached_day(date_iso: str, fetch_live: bool) -> list[dict]:
    cache = DATA_DIR / f"crawlers-{date_iso}.json"
    if cache.exists() and not fetch_live:
        return read_json(cache, []) or []
    rows = _fetch_day(date_iso)
    write_json(cache, rows)
    return rows


def fetch(windows: DateWindows) -> dict[str, Any]:
    today_iso = iso(windows.crawlers_day)
    rows_today = _cached_day(today_iso, fetch_live=True)

    # Build 30-day series — re-fetch the most recent 2 days (might have late-arriving rows),
    # use cache for older days.
    daily_counts: list[dict] = []
    for i in range(29, -1, -1):
        d = windows.report_for - timedelta(days=i)
        d_iso = iso(d)
        rows = _cached_day(d_iso, fetch_live=(i <= 1))
        by_bot = Counter(r["bot_name"] for r in rows)
        daily_counts.append({"date": d_iso, "total": sum(by_bot.values()), **dict(by_bot)})

    bot_totals = Counter(r["bot_name"] for r in rows_today)
    uri_totals = Counter(r["uri"] for r in rows_today).most_common(10)

    return {
        "data_date": today_iso,
        "total_today": len(rows_today),
        "by_bot_today": dict(bot_totals.most_common()),
        "top_uris": [{"uri": u, "hits": n} for u, n in uri_totals],
        "daily": daily_counts,
        "raw_sample": rows_today[:50],
    }


if __name__ == "__main__":
    import json
    from shared import today_tpe

    print(json.dumps(fetch(DateWindows(today_tpe())), indent=2, ensure_ascii=False))
