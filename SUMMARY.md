# 工作總結 · 2026-04-29

> 從零打造 zonetech.tw 每日網站追蹤系統的全紀錄。包含每個轉折點、踩到的坑、最後採用的方案。

---

## 🎯 起點需求

蓋斯克科技（zonetech.tw，WordPress 自架）最近開始發文章，需要：
1. 每天看 GSC + GA4 數據是否成長
2. 知道有沒有 AI 爬蟲（GPTBot / ClaudeBot 等）來爬網站
3. 學習 https://github.com/sstklen/aeo-crawler-check 怎麼判斷 AI 爬蟲
4. 結果以 HTML 報告每天自動推到 GitHub Pages

---

## ✅ 最終成果

| 項目 | 結果 |
|---|---|
| 線上儀表板 | **https://gaskhuang.github.io/zonetech-tracker/** |
| 程式碼倉 | **https://github.com/gaskhuang/zonetech-tracker** |
| 自動更新 | 每天 03:00 台北時間（GitHub Actions cron）|
| 涵蓋資料源 | GSC 點擊/曝光/CTR/排名 · GA4 使用者/工作階段/Top Pages/流量來源 · AEO 友善度體檢 · AI 爬蟲訪問（待 plugin 上傳）|
| 帳單 | $0 — 全部用免費額度（GitHub Public Repo + Pages、GA4/GSC API、aeo.washinmura.jp 公開 API）|

---

## 📐 系統架構

```
┌──────────────────┐     ┌────────────────────┐     ┌────────────────┐
│  zonetech.tw     │     │  GitHub Actions    │     │  GitHub Pages  │
│  (WordPress)     │     │  每天 03:00 台北   │     │  /docs         │
│                  │     │                    │     │                │
│  + WP mu-plugin  │◀────│  1. 拉爬蟲 log     │     │  index.html    │
│    (待上傳)      │ REST│  2. GSC API        │     │  reports/      │
│                  │     │  3. GA4 Data API   │────▶│   YYYY-MM-DD   │
│  access.log      │     │  4. AEO 體檢       │ git │  data/         │
└──────────────────┘     │  5. 渲染 HTML      │     │   YYYY-MM-DD   │
                         │  6. commit + push  │     └────────────────┘
                         └────────────────────┘
                                 │
                                 │ OAuth refresh token (in GitHub Secrets)
                                 ▼
                         GSC + GA4 APIs
```

---

## ⏱ 今天做了什麼（時間軸）

### 階段一：研究與規劃
- 研究 https://github.com/sstklen/aeo-crawler-check
  - **發現**：它只是「檢查網站對 AI 友善度」（robots.txt / llms.txt 等靜態檢查），**不會告訴你誰實際來爬**
  - 真要追蹤實際訪問必須：解析 server log、Cloudflare log、或 hook WordPress
- 寫實作計畫（見 `/Users/gask/.claude/plans/` 的 plan 檔）
- 用 Plan agent 驗證架構，找到 7 個風險點：WP 全頁快取會漏記、GSC 2-3 天延遲、token 用 query string 易外洩、bot UA 偽造、GitHub Pages Jekyll 處理 …

### 階段二：建構基礎程式碼
建立 16 個檔案，包含：
- **WP mu-plugin** (`wp-plugin/ai-crawler-logger.php`) — 在 WordPress 攔截每個請求、比對 20 隻 AI bot UA、記錄到 DB、暴露 token-protected REST endpoint
- **6 支 Python 腳本** — `fetch_gsc.py` / `fetch_ga4.py` / `fetch_crawlers.py` / `check_aeo.py` / `render_report.py` / `render_sample.py`
- **Jinja2 報告模板** — 內嵌 Chart.js、深色主題、自含一頁
- **GitHub Actions workflow** — 每日 cron、token 驗證、commit + push
- **完整 README** — 設定步驟跟本地測試流程

本機 git init + 初始 commit。Preview panel 即時顯示 mock 儀表板。

### 階段三：佈署上 GitHub
- 用 `gh repo create` 一行建好 public repo
- 用 `gh api` 開 GitHub Pages（`main` / `/docs`）
- 線上 URL 立即可用：https://gaskhuang.github.io/zonetech-tracker/

### 階段四：踩坑與轉向
這段是今天最有教訓性的部分：

#### 坑 1：使用者把金鑰貼到對話
使用者貼了 WP App Password 跟一組 OAuth client secret 到對話。**對話紀錄不是安全傳遞管道**。
**處理**：要求撤銷所有外洩金鑰，之後一律走 GitHub Secrets / 本機檔案。

#### 坑 2：使用者建錯憑證類型
原本要建「Service Account」（給機器用），使用者建成「OAuth Client ID（Desktop app）」（給人類登入用）。兩者 JSON 結構完全不同：
- Service Account：扁平結構，有 `"type": "service_account"` 和 `"private_key"`
- OAuth Client：有 `"installed"` wrapper，有 `redirect_uris`，**沒有** `private_key`

**處理**：教使用者用 GCP Console 走「服務帳戶」這個分支。

#### 坑 3：GCP IAM Console SPA 太重
第一個自動化嘗試是用 Claude in Chrome 驅動 GCP Console UI 完成 SA 建立。**頁面 SPA 永遠在背景跑**、`document_idle` 永不觸發、所有 read/screenshot 都 timeout。

**處理**：放棄驅動 UI，改用 `gcloud` CLI。一行 `gcloud iam service-accounts create` + `keys create` 在 5 秒內完成。

#### 坑 4：gcloud token 過期
切到 CLI 後，使用者的 gcloud token 過期。`gcloud auth login` 互動式 flow 在 background bash 卡住（要等 stdin 貼驗證碼）。

**處理**：用 `gcloud auth login --update-adc`（不加 `--no-launch-browser`），讓 macOS 直接跳 Chrome 完成 OAuth 並 callback 到 localhost。一鍵搞定，不用貼驗證碼。

#### 坑 5：Workspace 政策擋下 SA 加入 GA4 ⭐ 最大的坑
SA 建好後，要把 SA email 加到 GA4 → 卡關，GA4 跳「**這個電子郵件與 Google 帳戶不符**」。
原因：zonetech.tw 是 Google Workspace 網域，Workspace admin policy 阻擋外部 service account 帳號加入。

**處理**：架構轉向 — **不用 Service Account，改用使用者 OAuth refresh token**。
- 使用者本來就有 GSC + GA4 權限，不需要新增任何使用者
- 用使用者建的 OAuth Client (Desktop) 跑 installed-app flow
- 抓 refresh token，存進 GitHub Secrets
- Python 腳本用 `google.auth.load_credentials_from_file()` 統一處理（同時支援 SA + OAuth 兩種 JSON）

只改了 fetch_gsc.py 跟 fetch_ga4.py 兩個檔案的 import 和 client 建立 — 5 行程式碼差異。

#### 坑 6：GSC 屬性類型猜錯
本來 secret 設成 `sc-domain:zonetech.tw`，API 回 403「權限不足」。
**處理**：寫一支 mini script call `sites.list()`，列出使用者實際擁有的所有屬性 — 發現是 `https://zonetech.tw/`（URL-prefix 類型）。改 secret 即解。

### 階段五：驗證 + 收尾
- 本地端到端測試：GSC ✅ GA4 ✅ AEO ✅，爬蟲 404（預期）
- Push 改動 → 觸發 GitHub Actions → 90 秒後 CI 全綠
- 線上 https://gaskhuang.github.io/zonetech-tracker/ 顯示真實資料
- 清掉沒在用的 Service Account + 暫存 JSON 檔
- WP plugin（追蹤 AI 爬蟲那塊）保留 `/tmp/ai-crawler-logger.php`，等使用者上傳

---

## 🔑 GitHub Secrets 最終設定

| Secret | 內容 | 來源 |
|---|---|---|
| `GCP_SA_KEY` | OAuth user creds JSON（不是 SA！）| `/tmp/oauth-creds.json`（已刪）|
| `GA4_PROPERTY_ID` | `328408060` | GA4 URL `p328408060` |
| `GSC_SITE_URL` | `https://zonetech.tw/` | sites.list() API |
| `SITE_URL` | `https://zonetech.tw/` | 固定 |
| `WP_LOG_ENDPOINT` | `https://zonetech.tw/wp-json/aitracker/v1/logs` | 固定 |
| `WP_LOG_TOKEN` | 32 字元 url-safe 隨機字串 | `secrets.token_urlsafe(24)` |

**注意**：`GCP_SA_KEY` 這個 secret 名沿用原本給 SA 的命名。內容已換成 OAuth user creds（`type: authorized_user`），但 Python 同一個函數能讀，沒改 workflow YAML。

---

## 💡 學到的教訓（之後給別人做也會踩）

1. **金鑰一律不經對話**。給使用者明確的「貼到 GitHub Secrets 頁面」指令，從本機檔案讀就好。
2. **企業 Workspace 會擋 Service Account 跨產品共用**。預設先試 SA，被擋了立刻轉 OAuth user credentials。
3. **Google SPA console 不適合自動化驅動**。能用 gcloud / API 就別用 UI 點按。
4. **GSC 屬性類型 (`sc-domain:` vs URL prefix) 一定要先查**。`sites.list()` API 是 source of truth。
5. **GSC 資料延遲 2-3 天**。報告 header 一定要顯示「資料截止日」否則使用者會以為壞掉。
6. **WP 全頁快取會漏記 bot 訪問**。第一版用 mu-plugin 起跑、加健全度檢查；如果落差大就切到 nginx log tail 方案。
7. **OAuth Client 用 Desktop type，不要用 Web type**。Desktop type 有 localhost callback 直接做 installed-app flow。

---

## 📁 程式碼結構（最終版）

```
zonetech-tracker/
├── .github/workflows/daily.yml         # cron + 6 個 secrets 環境變數
├── scripts/
│   ├── shared.py                       # 時區、路徑、env helpers、DateWindows
│   ├── fetch_gsc.py                    # GSC searchanalytics（30 天 + top queries/pages）
│   ├── fetch_ga4.py                    # GA4 runReport（30 天 + top pages/sources）
│   ├── fetch_crawlers.py               # WP REST + 30 天 cache
│   ├── check_aeo.py                    # aeo.washinmura.jp + fallback
│   ├── render_report.py                # 主入口
│   └── render_sample.py                # mock data 預覽
├── templates/report.html.j2            # Jinja2 + Chart.js 模板
├── wp-plugin/ai-crawler-logger.php     # WordPress mu-plugin
├── docs/                               # GitHub Pages root
│   ├── .nojekyll
│   ├── index.html                      # 最新一份
│   ├── reports/YYYY-MM-DD.html         # 歷史
│   └── data/YYYY-MM-DD.json            # 原始資料
├── requirements.txt
├── README.md                           # 給該 repo 看的設定步驟
├── PLAYBOOK.md                         # 給其他人複製到自己網站的完整教學
└── SUMMARY.md                          # 本檔
```

---

## 🚧 還沒做的（可選擴充）

- [ ] WP plugin 還沒上傳到 zonetech.tw `/wp-content/mu-plugins/`（使用者待操作）
- [ ] 自動診斷層：流量陡降警示、CTR 機會、排名退步偵測
- [ ] AEO 修復套件：自動產 `llms.txt`、`Article` JSON-LD、robots.txt 修正版
- [ ] AI 建議層：接 Claude API 給 dropping queries 的拯救建議
- [ ] reverse-DNS 驗證真假 GPTBot/ClaudeBot
- [ ] 切換 nginx log tail 方案（如果 WP plugin 因快取漏記嚴重）
