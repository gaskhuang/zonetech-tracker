# 複製手冊：幫任何 WordPress 網站建立每日追蹤儀表板

> **目標**：60 分鐘內，幫任何網站架好「每天 03:00 自動產 GSC + GA4 + AEO + AI 爬蟲報告 → 推上 GitHub Pages」的系統。完全免費。

---

## 📋 需要準備的東西（網站擁有者要先有）

| 項目 | 怎麼取得 | 為什麼需要 |
|---|---|---|
| 網站已驗證 GSC 屬性 | https://search.google.com/search-console | 拿搜尋資料 |
| 網站已連線 GA4 | https://analytics.google.com | 拿訪客資料 |
| GitHub 帳號 | https://github.com/signup | 跑自動化 + 放報告 |
| Google Cloud 專案 | 如果沒有，建一個（免費）| 建 OAuth 用戶端 |
| **WordPress 自架網站** + 上傳檔案能力（SFTP / cPanel）| — | 裝 mu-plugin 抓 AI 爬蟲訪問 |

> 如果是**靜態網站**或**沒有伺服器存取權**（像純 GitHub Pages 部落格），AI 爬蟲那部分做不到，**只能做 GSC + GA4 + AEO 三塊**。其他都一樣可行。

---

## 🛠 工具需求（操作者本機）

```bash
# macOS
brew install gh git python@3.12 gcloud

# 確認都裝了
gh --version
git --version
python3 --version
gcloud --version
```

操作者不一定要是網站擁有者本人，但需要：
- 網站擁有者的 Google 帳號**有空 5 分鐘按一次「允許」**
- 網站擁有者授權建 GitHub repo + 設 secrets

---

## 🚀 完整流程（10 大步驟）

### Step 1 — Clone 模板

```bash
# 用 zonetech-tracker 當模板
git clone https://github.com/gaskhuang/zonetech-tracker.git CLIENT-tracker
cd CLIENT-tracker
rm -rf .git docs/data/*.json docs/reports/*.html
git init -b main
```

把 `CLIENT-tracker` 換成客戶網站對應的名字（例如 `acme-tracker`）。

---

### Step 2 — 建 GitHub repo + 開 Pages

```bash
gh repo create CLIENT-tracker --public --source=. --remote=origin
git add .
git commit -m "init"
git push -u origin main

# 開 GitHub Pages
gh api -X POST /repos/USERNAME/CLIENT-tracker/pages --input - <<EOF
{"source":{"branch":"main","path":"/docs"}}
EOF
```

確認後可從 `https://USERNAME.github.io/CLIENT-tracker/` 看到（先是 placeholder 頁面）。

---

### Step 3 — 建 OAuth Client（給機器讀 GSC + GA4 用）

進客戶的 Google Cloud Console：

```
https://console.cloud.google.com/apis/credentials?project=PROJECT_ID
```

如果還沒有專案：先到 https://console.cloud.google.com 新建一個。

啟用必要 API：

```bash
gcloud config set project PROJECT_ID
gcloud services enable searchconsole.googleapis.com analyticsdata.googleapis.com
```

建 OAuth Client：

1. 上方 **「+ 建立憑證 → OAuth 用戶端 ID」**
2. 應用程式類型：**「桌面應用程式」**（Desktop app）— **這個一定要選對**
3. 名稱：`tracker-desktop`
4. 建立 → 跳出視窗有「下載 JSON」按鈕 → 下載到 `~/Downloads/`

> ⚠️ **不要選 Web Application**。Desktop type 才有 `installed` wrapper、才能用 localhost callback。

驗證下載對的類型：

```bash
python3 -c "
import json
d = json.load(open('FILE_PATH.json'))
key = list(d.keys())[0]
print('OK' if key == 'installed' else 'WRONG TYPE')
"
```

---

### Step 4 — 跑 OAuth Flow 拿 Refresh Token

寫一個一次性的 OAuth runner：

```bash
python3 -m venv .venv
.venv/bin/pip install google-auth-oauthlib

cat > /tmp/oauth_runner.py <<'EOF'
import json, sys
from pathlib import Path
from google_auth_oauthlib.flow import InstalledAppFlow

SCOPES = [
    "https://www.googleapis.com/auth/webmasters.readonly",
    "https://www.googleapis.com/auth/analytics.readonly",
]
flow = InstalledAppFlow.from_client_secrets_file(sys.argv[1], SCOPES)
creds = flow.run_local_server(port=0, open_browser=True)
cfg = json.load(open(sys.argv[1]))
inner = cfg.get("installed", cfg.get("web", {}))
out = {
    "type": "authorized_user",
    "client_id": inner["client_id"],
    "client_secret": inner["client_secret"],
    "refresh_token": creds.refresh_token,
    "token_uri": "https://oauth2.googleapis.com/token",
}
Path(sys.argv[2]).write_text(json.dumps(out, indent=2))
print(f"saved → {sys.argv[2]}")
EOF

# 跑！會自動跳 Chrome 讓網站擁有者按「允許」
.venv/bin/python /tmp/oauth_runner.py ~/Downloads/client_secret_*.json /tmp/oauth-creds.json
```

**網站擁有者要做的事**：
- Chrome 跳出分頁 → 選自己的 Google 帳號（有 GSC + GA4 權限那個）
- 看到「這個應用程式未經 Google 驗證」**警告 → 點「進階 → 前往 (不安全)」**（因為這個 OAuth Client 是測試模式）
- 看到 scope 清單 → **全部勾選 → 允許**
- 看到「Done — you can close this tab」就完成

成功後 `/tmp/oauth-creds.json` 裡會有 refresh token。**這個 token 永久有效，不會過期**（除非使用者撤銷）。

---

### Step 5 — 找 GA4 Property ID + GSC 屬性 URL

#### GA4 Property ID

打開 https://analytics.google.com 任意報表，看網址列：
```
analytics.google.com/analytics/web/#/aXXXXXXXX p YYYYYYYYY /reports/...
                                  ↑ Account     ↑ Property ID（這個！）
```
9 位數字就是 `GA4_PROPERTY_ID`。

#### GSC 屬性 URL（domain vs URL-prefix 一定要查清楚）

```bash
GOOGLE_APPLICATION_CREDENTIALS=/tmp/oauth-creds.json \
.venv/bin/python <<'EOF'
import google.auth
from googleapiclient.discovery import build
creds, _ = google.auth.load_credentials_from_file(
    "/tmp/oauth-creds.json",
    scopes=["https://www.googleapis.com/auth/webmasters.readonly"],
)
svc = build("searchconsole", "v1", credentials=creds, cache_discovery=False)
for s in svc.sites().list().execute().get("siteEntry", []):
    print(s["permissionLevel"], "\t", s["siteUrl"])
EOF
```

找到 `permissionLevel` 是 `siteOwner`、且 URL 對得上客戶網站的那一筆。**完整字串就是 `GSC_SITE_URL`**。可能長這樣：
- `sc-domain:example.com`（網域屬性）
- `https://example.com/`（URL-prefix 屬性）

**用錯類型 → API 會回 403，先確認**。

---

### Step 6 — 產 WP Token + 客製化 mu-plugin

```bash
# 產 token
WP_TOKEN=$(python3 -c "import secrets; print(secrets.token_urlsafe(24))")
echo "$WP_TOKEN"  # 留一份給 GitHub Secrets

# 把 token 寫進 plugin 檔
cp wp-plugin/ai-crawler-logger.php /tmp/ai-crawler-logger.php
sed -i '' "s|REPLACE_WITH_32_CHAR_RANDOM_TOKEN|${WP_TOKEN}|" /tmp/ai-crawler-logger.php
```

---

### Step 7 — 上傳 mu-plugin 到客戶 WordPress

把 `/tmp/ai-crawler-logger.php` 上傳到 `/wp-content/mu-plugins/ai-crawler-logger.php`：

| 主機類型 | 怎麼做 |
|---|---|
| Cloudways / Kinsta / WP Engine | SSH + scp |
| 共享主機（cPanel）| 主機商面板 → 檔案管理員 → 上傳 |
| 自己 VPS | `scp /tmp/ai-crawler-logger.php user@host:/var/www/html/wp-content/mu-plugins/` |
| WordPress.com 商業版 | SFTP 憑證在後台「外掛 → 新增外掛 → SFTP 憑證」 |

> **沒有 `mu-plugins/` 資料夾就建一個**（**目錄名一定是 `mu-plugins`**，不是 `must-use-plugins`）

驗證：

```bash
curl -H "Authorization: Bearer $WP_TOKEN" https://CLIENT.com/wp-json/aitracker/v1/health
# 應該回 {"ok":true,"total_rows":0,...}

# 模擬一次 GPTBot 訪問
curl -A "GPTBot/1.0" https://CLIENT.com/

# 應該記到一筆
curl -H "Authorization: Bearer $WP_TOKEN" https://CLIENT.com/wp-json/aitracker/v1/logs
```

#### ⚠️ 全頁快取會漏記

如果客戶有裝這些其中之一，**要設定 bypass cache for AI bots**：
- WP Rocket → 設定 → 進階規則 → 永遠不要快取 (User Agents) → 加 `GPTBot|ClaudeBot|PerplexityBot|Google-Extended|OAI-SearchBot|Bytespider|CCBot|Amazonbot`
- LiteSpeed Cache → Cache → Excludes → User Agents → 同上
- W3 Total Cache → 類似設定
- Cloudflare APO → 在 Cloudflare Cache Rules 加例外

否則 bot 命中快取就不會進 PHP，plugin 完全感知不到。

---

### Step 8 — 設 6 個 GitHub Secrets

```bash
REPO=USERNAME/CLIENT-tracker

# OAuth user credentials（從本機檔案，不會經過終端輸出）
gh secret set GCP_SA_KEY -R "$REPO" < /tmp/oauth-creds.json

# 純文字
echo "PROPERTY_ID_HERE"           | gh secret set GA4_PROPERTY_ID -R "$REPO"
echo "https://CLIENT.com/"        | gh secret set GSC_SITE_URL    -R "$REPO"
echo "https://CLIENT.com/"        | gh secret set SITE_URL        -R "$REPO"
echo "https://CLIENT.com/wp-json/aitracker/v1/logs" | gh secret set WP_LOG_ENDPOINT -R "$REPO"
echo "$WP_TOKEN"                  | gh secret set WP_LOG_TOKEN    -R "$REPO"

# 確認
gh secret list -R "$REPO"
```

> Secret 名稱用 `GCP_SA_KEY` 雖然有點誤導（內容是 OAuth user 不是 SA），但 Python 程式用 `google.auth.load_credentials_from_file` 同時支援兩種，名稱沒換以省去改 workflow YAML。

---

### Step 9 — 觸發第一次 workflow

```bash
gh workflow run daily.yml -R "$REPO"

# 等 90 秒
sleep 90

# 檢查結果
gh run list -R "$REPO" --workflow=daily.yml --limit=1

# 看線上頁面
open "https://USERNAME.github.io/CLIENT-tracker/"
```

成功後線上頁面會立刻換成真實資料，包含 30 天趨勢圖跟 top tables。

---

### Step 10 — 交付

給客戶這份：

> Hi，每日網站追蹤系統已上線：
> - **儀表板**：https://USERNAME.github.io/CLIENT-tracker/
> - 每天 03:00 台北時間自動更新
> - 涵蓋 GSC、GA4、AI 爬蟲訪問、AEO 友善度體檢
> - 歷史報告：點儀表板下方「歷史報告」連結
> - 更動程式碼：repo 在 https://github.com/USERNAME/CLIENT-tracker

---

## 🐞 常見問題排錯

### Workflow 失敗：`fetch_gsc.py` 回 403 forbidden

**原因**：`GSC_SITE_URL` 跟 OAuth 帳號實際擁有的屬性不一致。

**修復**：跑 Step 5 的 sites.list() 重新確認，更新 `GSC_SITE_URL` secret。

---

### Workflow 失敗：`refresh_token` 不見了

**原因**：OAuth Client 是「Web」類型不是「Desktop」，或第一次跑 flow 時忘記同意 offline access。

**修復**：刪掉舊 OAuth Client，重做 Step 3-4，這次選 Desktop。

---

### Workflow 失敗：`Workspace 政策阻擋這個應用程式`

**原因**：客戶是 Google Workspace 用戶，admin policy 擋了第三方 OAuth app。

**修復**：要求 Workspace admin 在 admin.google.com → Security → API controls → 把該 OAuth Client ID 加入信任清單。或者改用客戶私人 Gmail 帳號（如果該帳號有 GSC + GA4 權限）。

---

### Workflow 失敗：`fetch_crawlers.py` 回 404

**原因**：WP mu-plugin 還沒上傳到客戶伺服器。

**修復**：跑 Step 7。或暫時忍受 — 報告會顯示「無資料」但其他三塊正常。

---

### Workflow 失敗：`fetch_crawlers.py` 回 401

**原因**：`WP_LOG_TOKEN` GitHub secret 跟 plugin 裡寫死的 token 不一致。

**修復**：重新產 token、重新更新 plugin 檔上傳、同步更新 `WP_LOG_TOKEN` secret。

---

### 報告數字看起來怪怪的（GSC 點擊一直是 0）

**原因**：GSC 資料延遲 2-3 天，剛建的網站或 GSC 屬性可能根本還沒有資料。

**修復**：等 3-5 天讓 Google 索引追上。或檢查 GSC 網頁版有沒有資料 — 沒資料的話程式也不會變出資料。

---

### `docs/index.html` 過幾天沒更新

**原因**：GitHub Actions 預設 60 天沒推送會停 cron。或 OAuth refresh token 被使用者撤銷了。

**修復**：手動觸發 `gh workflow run daily.yml` 一次重新「啟用」cron。如果 token 被撤銷就重做 Step 4。

---

## 🎁 這個系統可以擴充什麼

- **自動診斷**：流量陡降警示、CTR 機會偵測、排名退步追蹤
- **AEO 修復套件**：自動產 `llms.txt`、`Article` JSON-LD、robots.txt 修正版
- **AI 內容建議**：接 Claude API 看 dropping queries、給拯救建議
- **多網站合併儀表板**：給代理商/SEO 公司看多個客戶的網站
- **Slack/LINE/Email 通知**：每日報告產生後推到 webhook
- **競爭對手追蹤**：拉同關鍵字下競爭對手 GSC 排名（需要他們的授權）

---

## 💰 成本

| 項目 | 費用 |
|---|---|
| GitHub Public Repo | $0 |
| GitHub Pages | $0 |
| GitHub Actions（public repo）| $0 — 無限 |
| Google Search Console API | $0 — 免費 |
| Google Analytics Data API | $0 — 每天 50K request 免費 |
| aeo.washinmura.jp API | $0 — 公開 |
| GCP 專案維護 | $0 — OAuth client 免費 |
| **總計** | **$0 / 月 / 客戶** |

唯一可能花錢的擴充：接 Claude API 做 AI 建議層，每個網站每月大約 NT$50-150。

---

## ⏱ 工作流程總時間

對熟悉的操作者：

| 階段 | 時間 |
|---|---|
| Step 1-2 (clone + push)        | 5 分鐘 |
| Step 3-4 (OAuth)               | 5 分鐘 |
| Step 5 (找 IDs)                | 2 分鐘 |
| Step 6-7 (WP plugin)           | 10-30 分鐘（看主機商）|
| Step 8-9 (secrets + 第一次跑) | 5 分鐘 |
| 驗收 + 客製化                  | 10 分鐘 |
| **總計**                       | **40-60 分鐘** |

---

## 📌 Checklist（操作時打勾）

```
□ 客戶的 GSC 已驗證 + GA4 已連線
□ 拿到客戶的 Google 帳號做 OAuth（要本人按允許）
□ Step 1: clone + 改名 repo
□ Step 2: gh repo create + 開 Pages
□ Step 3: 建 OAuth Client (Desktop type 一定要對！)
□ Step 4: 跑 OAuth flow 拿 refresh_token
□ Step 5: 拿到 GA4 Property ID + 確認 GSC site URL
□ Step 6: 產 WP token + 客製 plugin
□ Step 7: 上傳 plugin 到 wp-content/mu-plugins/
□ Step 7.5: 確認快取 plugin 對 AI bot 是 bypass
□ Step 8: 設 6 個 GitHub Secrets
□ Step 9: 觸發 workflow + 確認綠燈
□ Step 10: 把 URL 交給客戶
□ 兩週後回看：報告連續產出、有真實 AI 爬蟲資料
```
