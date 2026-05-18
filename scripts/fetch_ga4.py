"""Fetch GA4 metrics via the Data API v1beta."""
from __future__ import annotations

from typing import Any

import google.auth
from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import (
    DateRange,
    Dimension,
    Metric,
    OrderBy,
    RunReportRequest,
)

from shared import DateWindows, env, iso

SCOPES = ["https://www.googleapis.com/auth/analytics.readonly"]


def _client():
    creds, _ = google.auth.load_credentials_from_file(
        env("GOOGLE_APPLICATION_CREDENTIALS", required=True), scopes=SCOPES
    )
    return BetaAnalyticsDataClient(credentials=creds)


def _run(client, prop: str, req_kwargs: dict) -> list[dict]:
    req = RunReportRequest(property=f"properties/{prop}", **req_kwargs)
    resp = client.run_report(req)
    rows = []
    for r in resp.rows:
        rows.append(
            {
                **{d.name: v.value for d, v in zip(resp.dimension_headers, r.dimension_values)},
                **{m.name: float(v.value or 0) for m, v in zip(resp.metric_headers, r.metric_values)},
            }
        )
    return rows


def fetch(windows: DateWindows) -> dict[str, Any]:
    prop = env("GA4_PROPERTY_ID", required=True)
    client = _client()

    daily = _run(
        client,
        prop,
        {
            "date_ranges": [DateRange(start_date=iso(windows.trend_start), end_date=iso(windows.ga4_day))],
            "dimensions": [Dimension(name="date")],
            "metrics": [
                Metric(name="activeUsers"),
                Metric(name="sessions"),
                Metric(name="screenPageViews"),
            ],
            "order_bys": [OrderBy(dimension=OrderBy.DimensionOrderBy(dimension_name="date"))],
        },
    )
    for d in daily:
        d["date"] = f"{d['date'][:4]}-{d['date'][4:6]}-{d['date'][6:]}"

    # Detect the latest date that actually has GA4 data (mirrors fetch_gsc.py pattern).
    # Even with ga4_day = D-1, the API sometimes lags — use the last row's date so
    # top_pages / top_sources never query an empty date.
    latest_date = daily[-1]["date"] if daily else iso(windows.ga4_day)

    pages = _run(
        client,
        prop,
        {
            "date_ranges": [DateRange(start_date=latest_date, end_date=latest_date)],
            "dimensions": [Dimension(name="pagePath")],
            "metrics": [
                Metric(name="screenPageViews"),
                Metric(name="activeUsers"),
                Metric(name="averageSessionDuration"),
            ],
            "order_bys": [OrderBy(metric=OrderBy.MetricOrderBy(metric_name="screenPageViews"), desc=True)],
            "limit": 10,
        },
    )

    sources = _run(
        client,
        prop,
        {
            "date_ranges": [DateRange(start_date=latest_date, end_date=latest_date)],
            "dimensions": [Dimension(name="sessionSource")],
            "metrics": [Metric(name="sessions"), Metric(name="activeUsers")],
            "order_bys": [OrderBy(metric=OrderBy.MetricOrderBy(metric_name="sessions"), desc=True)],
            "limit": 10,
        },
    )

    return {
        "data_date": latest_date,  # actual last date with data, not the planned window date
        "daily": daily,
        "top_pages": pages,
        "top_sources": sources,
    }


if __name__ == "__main__":
    import json
    from shared import today_tpe

    print(json.dumps(fetch(DateWindows(today_tpe())), indent=2, ensure_ascii=False, default=str))
