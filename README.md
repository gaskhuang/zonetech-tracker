# 蓋斯克科技 · GSC + GA4 + AI 爬蟲每日追蹤

每天 03:00（台北時間）自動抓取 zonetech.tw 的 Search Console、GA4、AI 爬蟲訪問與 AEO 友善度，產出單檔 HTML 推到 GitHub Pages。

📊 **線上報告**：`https://<your-github-username>.github.io/<repo-name>/`（推上去後可看）

---

## 設定流程（第一次跑要做這些）

### Phase 0 — Google Cloud / GSC / GA4

1. 進 https://console.cloud.google.com 建立新專案（建議名 `zonetech-tracker`）
2. **API 和服務 → 程式庫**：啟用
   - `Google Search Console API`
   - `Google Analytics Data API`
3. **API 和服務 → 憑證 → 建立憑證 → 服務帳戶**：名稱 `tracker-bot`，建立完不用給角色
4. 進入該 SA → **金鑰 → 新增金鑰 → JSON** → 下載 .json（**這份檔案晚點貼到 GitHub Secrets**）
5. 複製 SA email，類似 `tracker-bot@zonetech-tracker.iam.gserviceaccount.com`
6. 加到 GSC：https://search.google.com/search-console → 你的屬性 → **設定 → 使用者和權限 → 新增使用者** → 貼 SA email，權限「受限」即可
7. 加到 GA4：https://analytics.google.com → **系統管理 → 資源存取管理 → 新增使用者** → 貼 SA email，角色「檢視者」
8. 記下 GA4 **Property ID**（9 位數字，在資源詳情）
9. 確認 GSC 屬性類型：**網域屬性**寫成 `sc-domain:zonetech.tw`、**網址前置屬性**寫成 `https://zonetech.tw/`

### Phase 1 — WordPress mu-plugin（記錄 AI 爬蟲訪問）

1. 產生一組 32 字元 token：
   ```bash
   python -c "import secrets; print(secrets.token_urlsafe(24))"
   ```
2. 編輯 `wp-plugin/ai-crawler-logger.php`，把 `REPLACE_WITH_32_CHAR_RANDOM_TOKEN` 換成你產出的 token
3. 透過 SFTP / cPanel 把這個檔案上傳到伺服器：
   ```
   /wp-content/mu-plugins/ai-crawler-logger.php
   ```
   （沒有 `mu-plugins/` 資料夾就自己建一個。mu-plugins 會自動啟用，不需要在後台啟用 plugin）
4. 第一次訪問網站後，資料表 `wp_ai_crawler_log` 會自動建立
5. 測試 endpoint（換成你的 token）：
   ```bash
   curl -H "Authorization: Bearer YOUR_TOKEN" \
     "https://zonetech.tw/wp-json/aitracker/v1/health"
   ```
   應該回 `{"ok": true, "total_rows": 0, ...}`

6. 模擬一次 AI 爬蟲訪問驗證：
   ```bash
   curl -A "GPTBot/1.0" https://zonetech.tw/
   curl -H "Authorization: Bearer YOUR_TOKEN" \
     "https://zonetech.tw/wp-json/aitracker/v1/logs"
   ```
   應該看到剛才 GPTBot 的紀錄

> ⚠️ 如果 zonetech.tw 有裝 **WP Rocket / LiteSpeed Cache / W3TC / Cloudflare APO**，bot 命中快取就不會進 PHP，這個 plugin 會漏記。請去快取設定加一條規則：「以下 User-Agent 不快取」並列出 GPTBot, ClaudeBot, PerplexityBot, Google-Extended, OAI-SearchBot 等。

### Phase 2 — 建立 GitHub repo

1. 在 GitHub 建一個 **public** repo（推薦名稱 `zonetech-tracker`）
2. 在這個資料夾把 code 推上去：
   ```bash
   cd "/Users/gask/Documents/claude-code/ga&gsc追蹤"
   git remote add origin https://github.com/<your-username>/zonetech-tracker.git
   git add .
   git commit -m "init"
   git push -u origin main
   ```
3. GitHub repo → **Settings → Pages**：
   - Source: `Deploy from a branch`
   - Branch: `main` / Folder: `/docs`
   - 儲存後等 1 分鐘，就能看到 `https://<username>.github.io/zonetech-tracker/`

### Phase 3 — 設定 GitHub Secrets

GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**，加入這些：

| Secret 名 | 值 |
|---|---|
| `GCP_SA_KEY` | Phase 0 下載的 SA JSON 檔**整份內容**（從 `{` 到 `}`） |
| `GA4_PROPERTY_ID` | Phase 0 步驟 8 的 9 位數字 |
| `GSC_SITE_URL` | `sc-domain:zonetech.tw` 或 `https://zonetech.tw/` |
| `SITE_URL` | `https://zonetech.tw/` |
| `WP_LOG_ENDPOINT` | `https://zonetech.tw/wp-json/aitracker/v1/logs` |
| `WP_LOG_TOKEN` | Phase 1 步驟 1 產生的 token |

### Phase 4 — 跑第一次報告

GitHub repo → **Actions → Daily Tracker → Run workflow**（手動觸發）

執行成功後：
- `docs/index.html` 會被更新成完整的儀表板
- `docs/reports/YYYY-MM-DD.html` 會留下歷史
- `https://<username>.github.io/<repo>/` 重新整理就能看

之後每天 03:00 台北時間自動跑。

---

## 本地測試

```bash
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

export GOOGLE_APPLICATION_CREDENTIALS=/path/to/sa.json
export GA4_PROPERTY_ID=123456789
export GSC_SITE_URL='sc-domain:zonetech.tw'
export SITE_URL='https://zonetech.tw/'
export WP_LOG_ENDPOINT='https://zonetech.tw/wp-json/aitracker/v1/logs'
export WP_LOG_TOKEN='your-token'

python scripts/render_report.py
open docs/index.html
```

只測單一資料源：`python scripts/fetch_gsc.py`、`python scripts/fetch_ga4.py`、`python scripts/fetch_crawlers.py`、`python scripts/check_aeo.py`

---

## 資料時效

- **GSC**：報告中顯示的「今日點擊」實際是 **D-2** 的資料（GSC API 有 2-3 天延遲）
- **GA4**：D-1（前一天）
- **AI 爬蟲**：D-1（前一天，依 plugin 紀錄即時）

報告 header 會清楚標註各資料源的實際資料日。

---

## 檔案結構

```
.github/workflows/daily.yml   # GitHub Actions cron
scripts/                      # Python 抓取與渲染
  shared.py                   #   時區/路徑/環境變數
  fetch_gsc.py                #   GSC API
  fetch_ga4.py                #   GA4 Data API
  fetch_crawlers.py           #   WP REST endpoint
  check_aeo.py                #   aeo.washinmura.jp + fallback
  render_report.py            #   主入口
templates/report.html.j2      # Jinja2 模板（內嵌 Chart.js）
wp-plugin/ai-crawler-logger.php  # WordPress mu-plugin
docs/                         # GitHub Pages root
  .nojekyll                   #   必要
  index.html                  #   最新一份
  reports/YYYY-MM-DD.html     #   歷史
  data/YYYY-MM-DD.json        #   原始資料
```
