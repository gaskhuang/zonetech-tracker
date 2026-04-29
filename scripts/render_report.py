"""Top-level orchestrator: fetch all sources, render HTML, write to docs/."""
from __future__ import annotations

import json
import shutil
import sys
import traceback
from datetime import datetime

from jinja2 import Environment, FileSystemLoader, select_autoescape

from shared import DATA_DIR, DOCS, REPORTS_DIR, ROOT, DateWindows, iso, today_tpe, write_json


def _safe(label: str, fn, *args, **kwargs):
    """Run a fetch, catch errors, return (data, error_str_or_None)."""
    try:
        return fn(*args, **kwargs), None
    except Exception as exc:  # noqa: BLE001
        traceback.print_exc()
        return None, f"{type(exc).__name__}: {exc}"


def main() -> int:
    sys.path.insert(0, str(ROOT / "scripts"))
    import fetch_gsc
    import fetch_ga4
    import fetch_crawlers
    import check_aeo

    today = today_tpe()
    windows = DateWindows(today)

    gsc, gsc_err = _safe("gsc", fetch_gsc.fetch, windows)
    ga4, ga4_err = _safe("ga4", fetch_ga4.fetch, windows)
    crawlers, crawlers_err = _safe("crawlers", fetch_crawlers.fetch, windows)
    aeo, aeo_err = _safe("aeo", check_aeo.fetch)

    payload = {
        "report_date": iso(today),
        "generated_at": datetime.utcnow().isoformat(timespec="seconds") + "Z",
        "windows": {
            "gsc_day": iso(windows.gsc_day),
            "ga4_day": iso(windows.ga4_day),
            "crawlers_day": iso(windows.crawlers_day),
        },
        "gsc": gsc,
        "ga4": ga4,
        "crawlers": crawlers,
        "aeo": aeo,
        "errors": {
            "gsc": gsc_err,
            "ga4": ga4_err,
            "crawlers": crawlers_err,
            "aeo": aeo_err,
        },
    }

    DATA_DIR.mkdir(parents=True, exist_ok=True)
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    write_json(DATA_DIR / f"{iso(today)}.json", payload)

    env = Environment(
        loader=FileSystemLoader(str(ROOT / "templates")),
        autoescape=select_autoescape(["html"]),
    )
    env.filters["tojson_data"] = lambda v: json.dumps(v, ensure_ascii=False, default=str)
    tpl = env.get_template("report.html.j2")
    html = tpl.render(**payload)

    out_dated = REPORTS_DIR / f"{iso(today)}.html"
    out_dated.write_text(html, encoding="utf-8")
    shutil.copyfile(out_dated, DOCS / "index.html")

    print(f"wrote {out_dated} and docs/index.html")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
