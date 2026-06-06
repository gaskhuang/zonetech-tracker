"""Shared helpers: dates, paths, environment."""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs"
DATA_DIR = DOCS / "data"
REPORTS_DIR = DOCS / "reports"

TPE = timezone(timedelta(hours=8))


def today_tpe() -> date:
    return datetime.now(TPE).date()


def iso(d: date) -> str:
    return d.isoformat()


def env(name: str, default: str | None = None, *, required: bool = False) -> str:
    val = os.environ.get(name, default)
    if required and not val:
        raise SystemExit(f"missing required env var: {name}")
    return val or ""


def write_json(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, default=str))


def read_json(path: Path, default=None):
    if not path.exists():
        return default
    return json.loads(path.read_text())


@dataclass
class DateWindows:
    """The day each data source reports for, given report date `report_for`.

    GSC: 2-3 day lag, so use D-3.
    GA4: near real-time, use D-1.
    Crawlers: WP plugin captures real-time, use D-1.
    """

    report_for: date

    @property
    def gsc_day(self) -> date:
        return self.report_for - timedelta(days=1)

    @property
    def ga4_day(self) -> date:
        # Report runs at 03:00 TPE (= 19:00 UTC previous day).
        # "Today TPE" is an incomplete UTC day → GA4 returns near-zero rows.
        # Use D-1 (same logic as crawlers_day) to get a complete GA4 day.
        return self.report_for - timedelta(days=1)

    @property
    def crawlers_day(self) -> date:
        # WP plugin stores `ts` in UTC, so we report the most recent COMPLETE
        # UTC day. At workflow run time (03:00 TPE = 19:00 UTC previous day),
        # "today TPE" maps to a UTC day that has barely started — querying it
        # returns ~0 hits. Yesterday TPE maps to a complete UTC day.
        return self.report_for - timedelta(days=1)

    @property
    def trend_start(self) -> date:
        return self.report_for - timedelta(days=179)
