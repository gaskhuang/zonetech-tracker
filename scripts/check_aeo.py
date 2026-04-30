"""AEO (AI-engine Optimization) friendliness check.

Calls the public aeo-crawler-check API, with a fallback that fetches
robots.txt / llms.txt / sitemap.xml directly so the report still works
if the third-party service is down.
"""
from __future__ import annotations

from typing import Any
from urllib.parse import urljoin

import requests

from shared import env

AEO_API = "https://aeo.washinmura.jp/api/v1/crawl-check"
TIMEOUT = 15


def _site_url() -> str:
    raw = env("SITE_URL") or env("GSC_SITE_URL", required=True)
    if raw.startswith("sc-domain:"):
        return f"https://{raw.split(':', 1)[1]}/"
    return raw if raw.endswith("/") else raw + "/"


def _try_aeo_api(url: str) -> dict | None:
    try:
        r = requests.post(AEO_API, json={"url": url}, timeout=TIMEOUT)
        if r.ok:
            return r.json()
    except requests.RequestException:
        pass
    return None


def _fetch_text(url: str) -> tuple[int, str]:
    try:
        r = requests.get(url, timeout=TIMEOUT, headers={"User-Agent": "zonetech-tracker/0.1"})
        return r.status_code, (r.text if r.ok else "")
    except requests.RequestException:
        return 0, ""


def _bot_allowed(robots_body: str, bot: str) -> bool:
    """Proper robots.txt parser. A bot is allowed unless an applicable User-agent
    block contains a 'Disallow:' line that exactly matches '/' or empty.

    Most-specific User-agent block wins; fall back to '*' block.
    """
    if not robots_body:
        return True

    blocks: dict[str, list[tuple[str, str]]] = {}
    current_uas: list[str] = []
    for raw in robots_body.splitlines():
        line = raw.split("#", 1)[0].strip()
        if not line:
            current_uas = []
            continue
        if ":" not in line:
            continue
        field, _, value = line.partition(":")
        field = field.strip().lower()
        value = value.strip()
        if field == "user-agent":
            current_uas.append(value)
            blocks.setdefault(value, [])
        elif field in ("allow", "disallow") and current_uas:
            for ua in current_uas:
                blocks.setdefault(ua, []).append((field, value))

    rules = blocks.get(bot)
    if rules is None:
        rules = blocks.get("*", [])

    # Bot is disallowed only if there's an explicit "Disallow: /" rule and no
    # overriding "Allow: /...". Specific path disallows (like /wp-admin/) don't
    # count as a site-wide block.
    has_full_disallow = any(f == "disallow" and v == "/" for f, v in rules)
    has_allow_root    = any(f == "allow"    and v == "/" for f, v in rules)
    return not has_full_disallow or has_allow_root


def _fallback_check(url: str) -> dict:
    robots_status, robots_body = _fetch_text(urljoin(url, "/robots.txt"))
    llms_status, llms_body = _fetch_text(urljoin(url, "/llms.txt"))
    sitemap_status, _ = _fetch_text(urljoin(url, "/sitemap.xml"))
    home_status, home_body = _fetch_text(url)

    bots_examined = [
        "GPTBot",
        "ClaudeBot",
        "Google-Extended",
        "PerplexityBot",
        "CCBot",
        "Applebot-Extended",
        "Amazonbot",
        "Bytespider",
    ]

    bot_rules = {bot: _bot_allowed(robots_body, bot) for bot in bots_examined}

    has_jsonld = home_status == 200 and 'application/ld+json' in home_body.lower()

    checks = {
        "robots_txt_present": robots_status == 200,
        "llms_txt_present": llms_status == 200,
        "sitemap_xml_present": sitemap_status == 200,
        "home_reachable": home_status == 200,
        "json_ld_present": has_jsonld,
        "bots_allowed": bot_rules,
    }

    score = 0
    score += 15 if checks["robots_txt_present"] else 0
    score += 20 if checks["llms_txt_present"] else 0
    score += 15 if checks["sitemap_xml_present"] else 0
    score += 10 if checks["home_reachable"] else 0
    score += 15 if checks["json_ld_present"] else 0
    allowed_count = sum(1 for v in bot_rules.values() if v)
    score += int((allowed_count / len(bot_rules)) * 25)

    return {"source": "fallback", "score": score, "checks": checks}


def fetch() -> dict[str, Any]:
    url = _site_url()
    data = _try_aeo_api(url)
    if data:
        data["source"] = "aeo.washinmura.jp"
        return data
    return _fallback_check(url)


if __name__ == "__main__":
    import json

    print(json.dumps(fetch(), indent=2, ensure_ascii=False))
