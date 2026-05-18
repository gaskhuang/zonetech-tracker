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

# WP system paths and common spam-probe paths that should not appear in Top URIs.
# AI bots sometimes probe these; they carry no content signal.
NOISE_PATHS: frozenset[str] = frozenset(
    {
        "/wp-signup.php",
        "/wp-login.php",
        "/wp-cron.php",
        "/xmlrpc.php",
        "/wp-admin/admin-ajax.php",
        "/wp-json/",
        "/feed/",
        "/wp-comments-post.php",
        "/wp-trackback.php",
    }
)


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

    # Build 180-day series — re-fetch the most recent 2 days (might have late-arriving rows),
    # use cache for older days. Older days that are missing entirely return [] gracefully.
    daily_counts: list[dict] = []
    for i in range(179, -1, -1):
        d = windows.report_for - timedelta(days=i)
        d_iso = iso(d)
        try:
            rows = _cached_day(d_iso, fetch_live=(i <= 1))
        except Exception:
            rows = []
        by_bot = Counter(r["bot_name"] for r in rows)
        daily_counts.append({"date": d_iso, "total": sum(by_bot.values()), **dict(by_bot)})

    bot_totals = Counter(r["bot_name"] for r in rows_today)

    # Filter WP system / spam-probe paths before computing top URIs.
    uri_totals = Counter(
        r["uri"]
        for r in rows_today
        if not any(r.get("uri", "").startswith(p) for p in NOISE_PATHS)
    ).most_common(10)

    # 7-day average (D-1 through D-7 relative to crawlers_day) for a stable
    # comparison baseline. Bots are inherently noisy day-to-day; a 7-day avg
    # prevents the summary card from showing misleading ±60% swings.
    today_total = len(rows_today)
    daily_totals = {entry["date"]: entry["total"] for entry in daily_counts}
    recent_7 = [
        daily_totals.get(iso(windows.crawlers_day - timedelta(days=i)), 0)
        for i in range(1, 8)
    ]
    avg_7d = sum(recent_7) / 7 if recent_7 else 0
    pct_vs_7d = (
        round((today_total - avg_7d) / avg_7d * 100, 1) if avg_7d else None
    )

    return {
        "data_date": today_iso,
        "total_today": today_total,
        "avg_7d": round(avg_7d, 1),
        "pct_vs_7d": pct_vs_7d,
        "by_bot_today": dict(bot_totals.most_common()),
        "top_uris": [{"uri": u, "hits": n} for u, n in uri_totals],
        "daily": daily_counts,
        "raw_sample": rows_today[:50],
    }


if __name__ == "__main__":
    import json
    from shared import today_tpe

    print(json.dumps(fetch(DateWindows(today_tpe())), indent=2, ensure_ascii=False))
