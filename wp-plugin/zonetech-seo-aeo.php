<?php
/**
 * Plugin Name: Zonetech SEO+AEO 優化器
 * Description: 針對 Cloudflare 統計前 15 大爬蟲做全面優化：robots.txt、LocalBusiness JSON-LD、OG 圖大圖、twitter card、llms.txt 覆寫。
 * Version: 1.0.0
 * Author: zonetech tracker
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// =====================================================================
// CONFIG
// =====================================================================

// 首頁 OG 圖：已選用媒體庫的 1376×768 WiFi 主視覺（符合 Meta 1080px+ 要求）
// 若之後換圖，在此改 URL 即可。
define( 'ZTK_OG_IMAGE_URL',    'https://zonetech.tw/wp-content/uploads/2026/04/wifi-hero-2.webp' );
define( 'ZTK_OG_IMAGE_WIDTH',  1376 );
define( 'ZTK_OG_IMAGE_HEIGHT', 768 );
define( 'ZTK_FB_APP_ID',       '1295712369329177' );

// ── 301 重定向：舊業務/不相關頁面 → 首頁 ──────────────────────────────
// 包含：4K剪輯、勒索病毒、電腦維修、筆電租賃、攝影展 等舊業務頁面
define( 'ZTK_REDIRECT_PATHS', [
    // 4K 剪輯相關
    '/blogs/4k-computer-clip-outfit',
    '/4k',
    '/blog/category/showcase/4k-editing-system',
    '/blog/2022/08/28/2022adobe-academv01',   // Adobe/Metaverse 講座
    // 勒索病毒/NAS 病毒
    '/blog/2021/04/22/nas-7zvirus',
    // 電腦維修
    '/category/showcase/computer-repair',
    '/pcfix',
    '/blog/2018/12/02/fixpc-2',
    '/blog/2018/12/21/fixpc-2-2',
    '/blog/2020/03/25/win7towin10',
    // 筆電/電腦租賃頁面保留（用戶決定繼續提供此服務）
    // 攝影展/舊媒體活動
    '/blog/2022/10/10/2022年台北南港國際攝影器材暨影像展-10-13四10-16日',
    '/blog/2020/11/18/2020photography_and_media_equipment_exhibition',
    // 舊碎片頁
    '/blog/2020/10/18/elementor-11437',
    '/blog/2018/03/17/smb_news2',
    '/blog/2018/03/22/smb_news1',
    '/blog/2018/05/16/smb_news4',
    '/blog/2020/11/24/11552-2',
    '/blog/2020/11/24/newx300',
] );

// ── Noindex：舊業務 tag 頁面（slug 為 URL decode 後的值）──────────────
define( 'ZTK_NOINDEX_TAG_SLUGS', [
    // 4K 剪輯
    '4k剪輯', '4k剪輯硬體', '4k電腦', '3d動畫主機',
    '剪輯電腦', '剪接電腦', '動態設計', '視覺特效', '影像設計輸出', '影音工作室',
    // 勒索病毒
    '勒索病毒', '勒索病毒2021', '勒索病毒副檔名', '勒索病毒原因',
    '勒索病毒攻擊', '勒索病毒檢測', '勒索病毒處理', '勒索病毒解毒',
    '2020中勒索病毒的前兆',
    // 電腦維修
    '電腦', '室內裝修',
    // 筆電/電腦租賃 tag 保留
    // 攝影/媒體/直播
    '直播課程', '網路直播', '印刷輸出', 'metaverse',
    // NAS 維修（與主業無關）
    'qnap維修', 'qnap-rma', 'qnap保固', 'qnap服務中心',
] );

// ── Noindex paths（頁面類，不做重定向）──────────────────────────────────
define( 'ZTK_NOINDEX_PATHS', [] ); // 目前全改用重定向，此保留空陣列供擴充


// =====================================================================
// 0. 自動把 App ID 寫入 Yoast Social 設定 + filter 雙重保險
// =====================================================================

// Yoast filter：直接覆寫輸出值（最可靠，不管資料庫存什麼）
add_filter( 'wpseo_opengraph_fb_app_id', function() { return ZTK_FB_APP_ID; } );

// 同時更新資料庫（讓 Yoast 後台顯示正確值）
add_action( 'plugins_loaded', 'ztk_sync_yoast_fb_app_id' );
function ztk_sync_yoast_fb_app_id() {
    if ( ! defined( 'WPSEO_VERSION' ) ) { return; }
    $social = get_option( 'wpseo_social', [] );
    if ( ( $social['facebook_app_id'] ?? '' ) === ZTK_FB_APP_ID ) { return; }
    $social['facebook_app_id'] = ZTK_FB_APP_ID;
    update_option( 'wpseo_social', $social );
}


// =====================================================================
// 0b. 舊業務頁面 301 重定向到首頁（比 noindex 更快從 Google/AI 索引消失）
//     同時修復 /category/showcase/computer-repair/ 的無限重定向迴圈
// =====================================================================
add_action( 'init', 'ztk_redirect_old_pages', 1 );
function ztk_redirect_old_pages() {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) { return; }
    $path = rtrim( urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ), '/' );

    foreach ( ZTK_REDIRECT_PATHS as $from ) {
        $from = rtrim( $from, '/' );
        if ( $path === $from || str_starts_with( $path, $from . '/' ) ) {
            wp_redirect( 'https://zonetech.tw/', 301 );
            exit;
        }
    }
}


// =====================================================================
// 0b-2. 剝除 JetEngine ?nocache= 參數 → 301 重定向到乾淨 URL
//        JetEngine AJAX 分頁會把時間戳記當 nocache 參數加進 URL，
//        每個時間戳都被 Google 當成獨立頁面爬取 → GSC「替代頁面」數量暴增。
//        解法：伺服器端 301 到無 nocache 的乾淨 URL，讓 Google 將舊記錄更新。
//        robots.txt 同時加 Disallow: /*?*nocache=* 阻止後續爬取。
// =====================================================================
add_action( 'init', 'ztk_strip_nocache_redirect', 2 );
function ztk_strip_nocache_redirect() {
    // 只處理前端頁面請求，排除 AJAX / REST API / Admin / Cron / XML-RPC
    if ( wp_doing_ajax() || is_admin()
         || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
         || ( defined( 'DOING_CRON' )   && DOING_CRON )
         || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
        return;
    }
    if ( ! isset( $_GET['nocache'] ) ) { return; }

    // 移除 nocache，保留 pagenum / jsf / 其他合法分頁參數
    $clean_uri = remove_query_arg( 'nocache', $_SERVER['REQUEST_URI'] ?? '/' );
    wp_redirect( 'https://' . ( $_SERVER['HTTP_HOST'] ?? 'zonetech.tw' ) . $clean_uri, 301 );
    exit;
}


// =====================================================================
// 0c. Noindex 舊業務/不相關 tag 頁面
// =====================================================================

function ztk_is_noindex_tag(): bool {
    // 用 URL path 比對（不依賴 WP tag slug，對中文 tag 更可靠）
    // 只取 path 部分（排除 query string），並做精確邊界比對，避免 /tag/電腦 誤觸 /tag/電腦維修
    $path = urldecode( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
    foreach ( ZTK_NOINDEX_TAG_SLUGS as $slug ) {
        if ( $path === '/tag/' . $slug || str_starts_with( $path, '/tag/' . $slug . '/' ) ) {
            return true;
        }
        $enc = urlencode( $slug );
        if ( $path === '/tag/' . $enc || str_starts_with( $path, '/tag/' . $enc . '/' ) ) {
            return true;
        }
    }
    // 也試 WP API
    if ( is_tag() ) {
        $tag = get_queried_object();
        if ( $tag && in_array( $tag->slug, ZTK_NOINDEX_TAG_SLUGS, true ) ) {
            return true;
        }
    }
    return false;
}

add_action( 'wp_head', 'ztk_noindex_old_pages', 1 );
function ztk_noindex_old_pages() {
    if ( ztk_is_noindex_tag() ) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
    }
}

// Yoast robots filter — 讓 Yoast 也輸出 noindex（避免 Yoast 覆蓋我們的設定）
add_filter( 'wpseo_robots', 'ztk_yoast_noindex_filter' );
function ztk_yoast_noindex_filter( $robots ) {
    if ( ztk_is_noindex_tag() ) {
        return 'noindex, nofollow';
    }
    return $robots;
}


// =====================================================================
// 1. robots.txt — 明確歡迎 15 個主要爬蟲
//    用 init 直接攔截 /robots.txt 請求（相容 Kinsta/Cloudflare 靜態快取環境）
//    同時掛 robots_txt filter 作為 fallback
// =====================================================================
function ztk_robots_extra(): string {
    return <<<'EOT'

# =============================================
# 搜尋引擎爬蟲
# =============================================

# Google Search + Discover
# Disallow: /*?*nocache=* — JetEngine AJAX 快取破壞參數，節省 crawl budget
User-agent: Googlebot
Allow: /
Disallow: /*?*nocache=*

# Google Ads 品質審核
User-agent: AdsBot-Google
Allow: /
Disallow: /*?*nocache=*

# Google AI（Gemini / AI Overviews 訓練）
User-agent: Google-Extended
Allow: /

# Microsoft Bing
User-agent: Bingbot
Allow: /
Disallow: /*?*nocache=*

# Apple Spotlight / Siri
User-agent: Applebot
Allow: /

User-agent: Applebot-Extended
Allow: /

# Baidu（百度）
User-agent: Baiduspider
Allow: /

# Yandex
User-agent: YandexBot
Allow: /

# =============================================
# AI 訓練與即時爬蟲（AEO 最大化策略 — 全部允許）
# =============================================

# OpenAI ChatGPT / SearchGPT
User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ChatGPT-User
Allow: /

# Anthropic Claude
User-agent: ClaudeBot
Allow: /

User-agent: Claude-SearchBot
Allow: /

User-agent: Claude-User
Allow: /

User-agent: anthropic-ai
Allow: /

# Perplexity
User-agent: PerplexityBot
Allow: /

User-agent: Perplexity-User
Allow: /

# Amazon Alexa AI
User-agent: Amazonbot
Allow: /

# ByteDance（TikTok AI）
User-agent: Bytespider
Allow: /

# xAI Grok
User-agent: GrokBot
Allow: /

User-agent: xAI
Allow: /

User-agent: x.AI
Allow: /

# DuckDuckGo DuckAssist
User-agent: DuckAssistBot
Allow: /

# Meta AI
User-agent: Meta-ExternalAgent
Allow: /

User-agent: meta-externalagent
Allow: /

User-agent: FacebookBot
Allow: /

User-agent: facebookexternalhit
Allow: /

User-agent: meta-webindexer
Allow: /

User-agent: meta-externalads
Allow: /

# You.com
User-agent: YouBot
Allow: /

# Diffbot
User-agent: Diffbot
Allow: /

# Common Crawl（學術 AI 訓練資料）
User-agent: CCBot
Allow: /

# AI2 — Allen Institute for AI
User-agent: AI2Bot
Allow: /

# Cohere AI
User-agent: cohere-ai
Allow: /

# =============================================
# 社群 / SEO 工具
# =============================================

User-agent: Pinterestbot
Allow: /

User-agent: SemrushBot
Allow: /

User-agent: AhrefsBot
Allow: /

# =============================================
# Sitemap
# =============================================
Sitemap: https://zonetech.tw/sitemaps.xml
Sitemap: https://zonetech.tw/sitemap_index.xml
EOT;
}

// init hook — 直接輸出（相容 Kinsta/Cloudflare 靜態快取攔截情況）
add_action( 'init', 'ztk_serve_robots_txt', 1 );
function ztk_serve_robots_txt() {
    $req_path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    if ( $req_path !== '/robots.txt' ) { return; }
    // User-agent: * 封鎖管理區與查詢字串（AI/搜尋引擎爬蟲已在下方明確允許）
    $base = "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /login/\nDisallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /private/\nDisallow: /temp/\nDisallow: /*?*\nDisallow: /search?";
    header( 'Content-Type: text/plain; charset=utf-8' );
    echo $base . ztk_robots_extra();
    exit;
}

// robots_txt filter — fallback（WordPress virtual robots.txt）
add_filter( 'robots_txt', function( $output ) { return $output . ztk_robots_extra(); }, 20 );


// =====================================================================
// 1b. LCP Hero 圖預載（首頁 Swiper 第一張背景圖）
//     fetchpriority=high 讓瀏覽器立即下載，改善 LCP 評分
// =====================================================================
add_action( 'wp_head', function() {
    if ( ! is_front_page() ) { return; }
    echo '<link rel="preload" as="image" href="https://zonetech.tw/wp-content/uploads/2026/04/wifi-hero-2.webp" fetchpriority="high">' . "\n";
}, 1 );

// =====================================================================
// 1b-2. 首屏圖片排除 LiteSpeed JS 延遲載入
//     LiteSpeed 把 LOGO / hero 等首屏圖換成 base64 佔位符 + data-src，
//     導致「文字先渲染、圖片後彈出」的閃爍感。
//     用 LiteSpeed filter 把首屏關鍵圖排除，讓它們正常即時載入。
// =====================================================================
add_filter( 'litespeed_media_lazy_img_excludes', function ( $excludes ) {
    if ( ! is_array( $excludes ) ) { $excludes = []; }
    $excludes[] = 'zonetech-logo';      // 頁首 LOGO
    $excludes[] = 'wifi-hero';          // 首頁 hero 大圖
    return $excludes;
} );
// 同時排除 LOGO 圖片的 data-src 還原（保險）
add_filter( 'litespeed_media_lazy_uri_excludes', function ( $excludes ) {
    if ( ! is_array( $excludes ) ) { $excludes = []; }
    $excludes[] = 'zonetech-logo';
    $excludes[] = 'wifi-hero';
    return $excludes;
} );

// =====================================================================
// 1c. YouTube iframe Lazy Load
//     所有 iframe 加上 loading="lazy"（若尚未設定），減少初始 JS 解析時間
//     適用 Elementor video widget / oEmbed / 任何 iframe 來源
// =====================================================================
function ztk_add_iframe_lazy( string $buffer ): string {
    // ── 1. YouTube iframe → loading="lazy" ──────────────────────────────
    $buffer = preg_replace_callback(
        '/<iframe\b([^>]*)>/i',
        function ( $m ) {
            // 已有 loading 屬性就不重複加
            if ( preg_match( '/\bloading\s*=/i', $m[1] ) ) {
                return $m[0];
            }
            return '<iframe' . $m[1] . ' loading="lazy">';
        },
        $buffer
    );

    // ── 2. Swiper CSS async → sync ───────────────────────────────────────
    // LiteSpeed 把 Swiper CSS 改成 media="print" onload="this.media='all'"
    // 導致 Swiper JS 初始化時 CSS 尚未套用 → 計算錯誤寬高 → slider 跑版
    // 攔截 <link id="swiper-css"> 與 <link id="e-swiper-css">，還原為同步載入
    $buffer = preg_replace_callback(
        '/<link\b[^>]*\bid=["\'](?:swiper-css|e-swiper-css)["\'][^>]*>/i',
        function ( $m ) {
            $tag = $m[0];
            // media="print" → media="all"
            $tag = preg_replace( '/\bmedia=["\']print["\']/i', 'media="all"', $tag );
            // 移除 onload="this.media='all'"
            $tag = preg_replace( '/\bonload=["\'][^"\']*["\']/i', '', $tag );
            return $tag;
        },
        $buffer
    );

    return $buffer;
}
add_action( 'template_redirect', function () {
    if ( is_admin() || wp_doing_ajax() || is_feed() ) { return; }
    ob_start( 'ztk_add_iframe_lazy' );
}, 0 );

// =====================================================================
// 1d. 前台移除多餘 CSS（Phase 1-B：減少 render-blocking）
//     dashicons：WP 後台圖示字型，未登入訪客前台用不到（admin bar 才需要）
// =====================================================================
add_action( 'wp_enqueue_scripts', function () {
    if ( is_user_logged_in() ) { return; }   // 登入者的 admin bar 需要 dashicons
    wp_dequeue_style( 'dashicons' );
}, 100 );

// =====================================================================
// 1e. 啟用 Elementor 效能實驗（Phase 1-A：合併 widget CSS）
//     只在 admin 端 run-once，且不覆蓋使用者已設定的選項。
//     「Improved CSS Loading」相容性高，安全自動啟用。
//     「Inline Font Icons」有圖示相容性風險，保留給使用者手動於後台開啟。
// =====================================================================
add_action( 'admin_init', function () {
    if ( get_option( 'ztk_el_exp_v1' ) ) { return; }
    if ( ! class_exists( '\Elementor\Plugin' ) ) { return; }
    $opt = 'elementor_experiment-e_optimized_css_loading';
    if ( false === get_option( $opt, false ) ) {
        update_option( $opt, 'active' );
    }
    update_option( 'ztk_el_exp_v1', '1' );
}, 20 );

// =====================================================================
// 2. 首頁 OG Image 換大圖（1376×768，取代舊的 633×405）
//    嘗試 Yoast / RankMath filter，並在 wp_head priority=1 輸出確保第一出現
// =====================================================================

// Yoast SEO
add_filter( 'wpseo_opengraph_image',       'ztk_og_image_url',  10 );
add_filter( 'wpseo_opengraph_image_size',  'ztk_og_image_size', 10 );

function ztk_og_image_url( $img ) {
    return is_front_page() ? ZTK_OG_IMAGE_URL : $img;
}
function ztk_og_image_size( $size ) {
    if ( is_front_page() ) {
        return [ 'width' => ZTK_OG_IMAGE_WIDTH, 'height' => ZTK_OG_IMAGE_HEIGHT ];
    }
    return $size;
}

// 保險起見：priority=1 確保我們的 og:image + fb:app_id 先出現
add_action( 'wp_head', 'ztk_og_image_head', 1 );
function ztk_og_image_head() {
    $app_id = esc_attr( ZTK_FB_APP_ID );
    echo '<meta property="fb:app_id" content="' . $app_id . '" />' . "\n";

    if ( ! is_front_page() ) { return; }
    $url = esc_url( ZTK_OG_IMAGE_URL );
    $w   = (int) ZTK_OG_IMAGE_WIDTH;
    $h   = (int) ZTK_OG_IMAGE_HEIGHT;
    echo <<<HTML
<meta property="og:image" content="{$url}" />
<meta property="og:image:secure_url" content="{$url}" />
<meta property="og:image:width" content="{$w}" />
<meta property="og:image:height" content="{$h}" />
<meta property="og:image:type" content="image/webp" />

HTML;
}


// =====================================================================
// 3. twitter:card 改為 summary_large_image（讓 X/Twitter 顯示大圖）
// =====================================================================

// Yoast
add_filter( 'wpseo_twitter_card_type', function( $card ) {
    return 'summary_large_image';
} );

// RankMath（以防萬一）
add_filter( 'rank_math/twitter/card_type', function() {
    return 'summary_large_image';
} );

// 直接在 head 輸出覆寫
add_action( 'wp_head', function() {
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url( ZTK_OG_IMAGE_URL ) . '" />' . "\n";
}, 1 );


// =====================================================================
// 4. Organization + WebSite JSON-LD（首頁）
//    強化 sameAs 連結（FB / IG / YouTube）讓 AI 交叉驗證身份
//    WebSite schema 標明站台基本資料；SearchAction 雖然 Google 2024-11 已停用
//    sitelinks search box，但 AI 引擎仍可用此找到站內搜尋端點
// =====================================================================
add_action( 'wp_head', 'ztk_localbusiness_jsonld', 5 );
function ztk_localbusiness_jsonld() {
    if ( ! is_front_page() ) { return; }

    $schema = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            // ── Organization ──────────────────────────────────────────
            [
                '@type'         => 'Organization',
                '@id'           => 'https://zonetech.tw/#organization',
                'name'          => '蓋斯克科技',
                'alternateName' => [ 'ZONETECH', '蓋斯克' ],
                'url'           => 'https://zonetech.tw',
                'logo'          => [
                    '@type'  => 'ImageObject',
                    'url'    => ZTK_OG_IMAGE_URL,
                    'width'  => ZTK_OG_IMAGE_WIDTH,
                    'height' => ZTK_OG_IMAGE_HEIGHT,
                ],
                'image'         => ZTK_OG_IMAGE_URL,
                'description'   => '專注台灣大型場域企業級無線網路建置，服務智慧工廠、大型倉儲（1000坪以上）、辦公商辦、商務中心。主要服務：企業級WiFi規劃、多WAN頻寬整合、跨樓層網路建置。',
                'foundingDate'  => '2015',
                'areaServed'    => [ [ '@type' => 'Country', 'name' => '台灣' ] ],
                'knowsAbout'    => [
                    '企業 WiFi 建置', '工廠 WiFi', '倉儲 WiFi', 'AGV 漫遊',
                    '802.11r Fast BSS Transition', '盤點槍無線連線', 'Wi-Fi 6 / 6E',
                    '多 WAN 頻寬整合', '企業防火牆', 'Cisco / UniFi / Aruba',
                ],
                'sameAs'        => [
                    'https://www.facebook.com/zonetech.tw',
                    'https://www.instagram.com/zonetech.tw/',
                    'https://www.youtube.com/@zonetech',
                    // 後續補：LinkedIn 公司頁、Google Business Profile、Wikidata
                ],
                'hasOfferCatalog' => [
                    '@type'           => 'OfferCatalog',
                    'name'            => '企業網路解決方案',
                    'itemListElement' => [
                        [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => '工廠WiFi建置（智慧工廠、AGV自動化倉儲、大型廠區）' ] ],
                        [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => '倉儲WiFi建置（物流中心、冷鏈倉庫、1000坪以上大型倉儲）' ] ],
                        [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => '辦公室網路規劃（200坪以上商辦空間）' ] ],
                        [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => '商務中心多租戶網路（分區VLAN隔離）' ] ],
                        [ '@type' => 'Offer', 'itemOffered' => [ '@type' => 'Service', 'name' => '企業防火牆/雙WAN頻寬整合建置' ] ],
                    ],
                ],
                'contactPoint'  => [
                    '@type'             => 'ContactPoint',
                    'contactType'       => 'customer service',
                    'areaServed'        => 'TW',
                    'availableLanguage' => [ 'Chinese', 'zh-TW' ],
                    'url'               => 'https://zonetech.tw/contact/',
                ],
            ],
            // ── WebSite + SearchAction（讓 Google SERP 顯示站內搜尋框）─
            [
                '@type'           => 'WebSite',
                '@id'             => 'https://zonetech.tw/#website',
                'url'             => 'https://zonetech.tw/',
                'name'            => '蓋斯克科技-ZONETECH',
                'description'     => '企業級大型場域無線網路建置｜工廠WiFi｜倉儲WiFi｜辦公室網路規劃',
                'inLanguage'      => 'zh-TW',
                'publisher'       => [ '@id' => 'https://zonetech.tw/#organization' ],
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => [
                        '@type'       => 'EntryPoint',
                        'urlTemplate' => 'https://zonetech.tw/?s={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ];

    echo '<script type="application/ld+json">' . ztk_safe_jsonld( $schema ) . '</script>' . "\n";
}


/**
 * 將 schema 陣列輸出為 JSON-LD 安全字串
 * 使用 JSON_HEX_* 防止 </script> 注入；保持 UTF-8 可讀；wp_json_encode 自動 fallback
 */
function ztk_safe_jsonld( array $schema ): string {
    return wp_json_encode(
        $schema,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}


// =====================================================================
// 4b. BreadcrumbList JSON-LD（所有頁面）
//     讓 Google SERP 顯示麵包屑路徑，AI 也用此判斷頁面層級
//     支援：分類祖先鏈、頁面父子層、term_link 錯誤防護
// =====================================================================
add_action( 'wp_head', 'ztk_breadcrumb_jsonld', 6 );
function ztk_breadcrumb_jsonld() {
    if ( is_front_page() || is_admin() ) { return; }
    // Yoast 和 RankMath 會自行輸出 BreadcrumbList → 跳過避免重複
    // SEOPress 把 BreadcrumbList 放在 @graph 裡（不同 script tag），兩者並存 Google 可接受
    if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) { return; }

    $items = [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁', 'item' => 'https://zonetech.tw/' ],
    ];
    $pos = 2;

    if ( is_singular( 'post' ) || is_single() ) {
        // 取文章主分類 + 祖先鏈
        $cats = get_the_category();
        if ( ! empty( $cats ) ) {
            $cat       = $cats[0];
            $ancestors = array_reverse( get_ancestors( $cat->term_id, 'category' ) );
            foreach ( $ancestors as $aid ) {
                $a = get_category( $aid );
                $link = get_category_link( $aid );
                if ( $a && ! is_wp_error( $link ) ) {
                    $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $a->name, 'item' => $link ];
                }
            }
            $link = get_category_link( $cat->term_id );
            if ( ! is_wp_error( $link ) ) {
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $cat->name, 'item' => $link ];
            }
        } else {
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => '部落格', 'item' => 'https://zonetech.tw/blogs/' ];
        }
        $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title(), 'item' => get_permalink() ];

    } elseif ( is_page() ) {
        // 父頁面鏈
        $post = get_queried_object();
        $ancestors = array_reverse( get_post_ancestors( $post ) );
        foreach ( $ancestors as $aid ) {
            $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title( $aid ), 'item' => get_permalink( $aid ) ];
        }
        $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title(), 'item' => get_permalink() ];

    } elseif ( is_category() || is_tag() || is_tax() ) {
        $term = get_queried_object();
        if ( $term && ! empty( $term->name ) ) {
            // 加分類祖先鏈
            if ( is_category() ) {
                $ancestors = array_reverse( get_ancestors( $term->term_id, 'category' ) );
                foreach ( $ancestors as $aid ) {
                    $link = get_category_link( $aid );
                    $a    = get_category( $aid );
                    if ( $a && ! is_wp_error( $link ) ) {
                        $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $a->name, 'item' => $link ];
                    }
                }
            }
            $link = get_term_link( $term );
            if ( ! is_wp_error( $link ) ) {
                $items[] = [ '@type' => 'ListItem', 'position' => $pos++, 'name' => $term->name, 'item' => $link ];
            }
        }
    } else {
        return; // 其他頁面不輸出
    }

    if ( count( $items ) < 2 ) { return; }

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
    echo '<script type="application/ld+json">' . ztk_safe_jsonld( $schema ) . '</script>' . "\n";
}


// =====================================================================
// 4c. HowTo JSON-LD 自動偵測（教學文章）
//     文章內若有「步驟一/二/三」或「Step 1/2/3」H2/H3，自動產生 HowTo schema
// =====================================================================
add_action( 'wp_head', 'ztk_howto_jsonld', 7 );
function ztk_howto_jsonld() {
    if ( ! is_singular() || is_front_page() ) { return; } // 含 blogs CPT；首頁排除

    $post = get_queried_object();
    if ( ! $post || empty( $post->post_content ) ) { return; }

    $content = $post->post_content;

    // PERF：快速檢查，避免在不含步驟的文章跑完整 regex
    if ( ! str_contains( $content, '步驟' ) && stripos( $content, 'Step ' ) === false ) { return; }

    // 找所有 H2/H3（捕捉完整內容含 inline markup）→ strip_tags 後再比對步驟關鍵字
    // 這樣可處理 <h3><span>步驟一</span>：...</h3> 等有內嵌標籤的情況
    if ( ! preg_match_all(
        '/<h[23][^>]*>(.*?)<\/h[23]>/isu',
        $content,
        $raw,
        PREG_OFFSET_CAPTURE
    ) ) {
        return;
    }

    // 過濾：只保留標題文字符合「步驟X」或「Step N」的項目
    $step_pattern = '/^\s*(?:步驟[一二三四五六七八九十0-9]+|Step\s*\d+)/iu';
    $m = [ [], [] ]; // $m[0] = full match entries, $m[1] = captured text entries
    foreach ( $raw[0] as $idx => $full_match ) {
        $inner_text = wp_strip_all_tags( $raw[1][ $idx ][0] );
        if ( preg_match( $step_pattern, $inner_text ) ) {
            $m[0][] = $full_match;          // [full_string, offset]
            $m[1][] = $raw[1][ $idx ];      // [inner_html, offset]
        }
    }
    if ( count( $m[0] ) < 3 ) { return; } // 至少 3 步才當作 HowTo

    $steps = [];
    $total = count( $m[0] );
    foreach ( $m[0] as $i => $full_match ) {
        $match_str   = $full_match[0];                     // 整段 <h2>…</h2>
        $match_start = $full_match[1];
        $match_end   = $match_start + strlen( $match_str );
        $clean_name  = wp_strip_all_tags( $m[1][ $i ][0] );

        // 此 heading 結尾到下一個 heading 開頭之間的內容即為 step 說明
        $next_h_pos = ( $i + 1 < $total ) ? $m[0][ $i + 1 ][1] : false;
        $between    = ( $next_h_pos !== false )
            ? substr( $content, $match_end, $next_h_pos - $match_end )
            : substr( $content, $match_end );
        $step_text  = wp_trim_words( wp_strip_all_tags( $between ), 40, '...' );

        // 優先使用 heading 的 id 屬性（如 Table of Contents 插件加的 rtoc-N）
        preg_match( '/\sid=["\']([^"\']+)["\']/', $match_str, $id_m );
        $anchor = $id_m ? '#' . $id_m[1] : '#step-' . ( $i + 1 );

        $steps[] = [
            '@type'    => 'HowToStep',
            'position' => $i + 1,
            'name'     => $clean_name,
            'text'     => $step_text ?: $clean_name,
            'url'      => get_permalink( $post ) . $anchor,
        ];
    }

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'HowTo',
        'name'        => get_the_title( $post ),
        'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
        'image'       => get_the_post_thumbnail_url( $post, 'full' ) ?: ZTK_OG_IMAGE_URL,
        'totalTime'   => 'PT30M', // 預設 30 分鐘閱讀，可後續依文章覆寫
        'step'        => $steps,
    ];
    echo '<script type="application/ld+json">' . ztk_safe_jsonld( $schema ) . '</script>' . "\n";
}


// =====================================================================
// 4d. FAQPage 自動偵測（文章末段若有 H2「常見問題」「FAQ」 + H3 問題清單）
//     已用 Yoast/SEOPress FAQ block 的文章不重複輸出
// =====================================================================
add_action( 'wp_head', 'ztk_faqpage_jsonld', 8 );
function ztk_faqpage_jsonld() {
    if ( ! is_singular() || is_front_page() ) { return; } // 含 blogs CPT；首頁排除
    $post = get_queried_object();
    if ( ! $post ) { return; }

    // SEOPress 全站自動偵測 FAQ schema → 若裝了 SEOPress，直接跳過避免重複
    if ( defined( 'SEOPRESS_VERSION' ) ) { return; }

    // 若此文章已有 Yoast FAQ block，Yoast 會自行輸出 FAQPage JSON-LD，跳過
    if ( function_exists( 'has_block' ) && has_block( 'yoast/faq-block', $post ) ) { return; }

    $content = $post->post_content;

    // PERF：快速檢查，避免在沒有 FAQ 段落的文章跑完整 regex
    if ( stripos( $content, '常見問題' ) === false && stripos( $content, 'FAQ' ) === false ) { return; }

    // 抓「常見問題」「FAQ」H2（大小寫不拘）之後到下一個 H2 的範圍
    if ( ! preg_match( '/<h2[^>]*>.*?(?:常見問題|FAQ).*?<\/h2>(.*?)(?=<h2|$)/sui', $content, $m ) ) {
        return;
    }
    $faq_block = $m[1];

    // H3 問題 + 後段答案（到下一個 H2/H3 或結尾）
    if ( ! preg_match_all( '/<h3[^>]*>(.*?)<\/h3>(.*?)(?=<h[23]|$)/su', $faq_block, $qm, PREG_SET_ORDER ) ) {
        return;
    }
    if ( count( $qm ) < 2 ) { return; }

    $main_entity = [];
    foreach ( $qm as $q ) {
        $question = wp_strip_all_tags( $q[1] );
        $answer   = trim( wp_strip_all_tags( $q[2] ) );
        if ( ! $question || ! $answer ) { continue; }
        $main_entity[] = [
            '@type'          => 'Question',
            'name'           => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                // 保留完整答案；若超過 5000 字才截斷（Google 建議無硬性上限，但過長影響索引）
                'text'  => mb_strlen( $answer ) > 5000 ? mb_substr( $answer, 0, 5000 ) . '…' : $answer,
            ],
        ];
    }
    if ( count( $main_entity ) < 2 ) { return; }

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $main_entity,
    ];
    echo '<script type="application/ld+json">' . ztk_safe_jsonld( $schema ) . '</script>' . "\n";
}


// =====================================================================
// 5. llms.txt 覆寫（讓 ChatGPT、Claude、Perplexity 等 AI 正確理解業務）
//    用 init priority=1 最早攔截，確保覆蓋其他 plugin 的 llms.txt handler
// =====================================================================
add_action( 'init', 'ztk_serve_llms_txt', 1 );
function ztk_serve_llms_txt() {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) { return; }
    $path = strtok( $_SERVER['REQUEST_URI'], '?' );
    if ( $path !== '/llms.txt' ) { return; }

    // 抓最新 10 篇文章（標題 + 摘要）
    $posts = get_posts( [
        'numberposts' => 10,
        'post_status' => 'publish',
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );

    $lines = [
        '# 蓋斯克科技-ZONETECH',
        '',
        '> 企業級大型場域無線網路建置｜智慧工廠WiFi｜倉儲WiFi｜辦公室網路規劃｜商務中心網路',
        '> Last updated: ' . gmdate( 'Y-m-d' ),
        '',
        '## About',
        '',
        '蓋斯克科技（ZONETECH）專注台灣大型場域企業級無線網路建置，服務範圍涵蓋：',
        '- 智慧工廠 / 大型製造廠（200坪～5000坪）',
        '- 大型倉儲 / 物流中心 / 冷鏈倉庫（1000坪以上）',
        '- 辦公室 / 商辦大樓',
        '- 商務中心 / 共享辦公空間',
        '- 跨樓層、地下室、戶外千人活動高密度裝置環境',
        '',
        '主要服務：企業級 Cisco / UniFi 無線網路規劃、多WAN頻寬整合（雙WAN / SD-WAN）、企業防火牆建置（Fortinet / pfSense）。',
        '擁有 200坪以上辦公室、跨樓層、地下室、大型倉儲實際成功案例。',
        '',
        '## Services',
        '',
        '- 工廠WiFi建置（智慧製造、AGV自動導引、工業 IoT 設備聯網）',
        '- 倉儲WiFi建置（WMS條碼掃描、RFID、叉車通訊、物流自動化）',
        '- 辦公室/商辦網路規劃（大型開放空間漫遊、視訊會議優化）',
        '- 商務中心多租戶網路（VLAN隔離、獨立頻寬控制）',
        '- 企業防火牆/雙WAN建置（頻寬合併、備援切換）',
        '- AI 爬蟲友善最佳化（llms.txt / robots.txt / 結構化資料）',
        '',
        '## Target Keywords',
        '',
        '工廠WiFi, 倉儲WiFi, 工廠無線網路, 倉庫WiFi, 辦公室網路規劃, 企業WiFi建置,',
        '商務中心WiFi, 網路規劃公司, 企業網路, 無線網路規劃, 大型場域WiFi',
        '',
        '## Search',
        '',
        '- Search URL: `https://zonetech.tw?s={query}`',
        '',
        '## Recent Content',
        '',
    ];

    foreach ( $posts as $post ) {
        $title   = $post->post_title;
        $url     = get_permalink( $post->ID );
        $excerpt = wp_strip_all_tags( get_the_excerpt( $post->ID ) );
        if ( ! $excerpt ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
        }
        $excerpt = mb_substr( $excerpt, 0, 120 );
        $lines[] = "- [{$title}]({$url}): {$excerpt}";
    }

    $lines[] = '';
    $lines[] = '## Complete Sitemap';
    $lines[] = '';
    $lines[] = 'For a comprehensive list of all URLs, see: https://zonetech.tw/sitemaps.xml';

    $body = implode( "\n", $lines );

    status_header( 200 );
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );
    header( 'Vary: Accept-Encoding' );
    echo $body;
    exit;
}


// =====================================================================
// 6. AI Agent 就緒支援（isitagentready.com 四項）
// =====================================================================

// ── 攔截 /.well-known/* 請求（WordPress 預設會重定向這些路徑）──────────
add_action( 'init', 'ztk_wellknown_endpoints', 1 );
function ztk_wellknown_endpoints() {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) { return; }
    $path = rtrim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

    switch ( $path ) {

        // ── MCP Server Card (SEP-1649) ──────────────────────────────
        case '/.well-known/mcp/server-card.json':
            $card = [
                'schemaVersion' => '1.0.0',
                'serverInfo'    => [ 'name' => 'zonetech.tw', 'version' => '1.0.0' ],
                'metadata'      => [
                    'title'       => '蓋斯克科技-ZONETECH',
                    'description' => '企業級大型場域無線網路建置｜智慧工廠WiFi｜倉儲WiFi｜辦公室網路規劃',
                    'url'         => 'https://zonetech.tw',
                    'language'    => 'zh-TW',
                    'topics'      => [ 'enterprise wifi', 'wireless network', 'factory wifi', 'office network', 'commercial center network', 'warehouse wifi' ],
                ],
                'capabilities' => [
                    'search' => [
                        'description' => '搜尋蓋斯克科技網站內容',
                        'endpoint'    => 'https://zonetech.tw/?s={query}',
                    ],
                    'contact' => [
                        'description' => '諮詢企業網路建置',
                        'endpoint'    => 'https://zonetech.tw/contact/',
                    ],
                ],
            ];
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── Agent Skills Discovery Index ────────────────────────────
        case '/.well-known/agent-skills/index.json':
            $index = [
                '$schema' => 'https://agentskills.io/schema/v0.2.0/index.schema.json',
                'skills'  => [
                    [
                        'name'        => 'search',
                        'type'        => 'search',
                        'description' => '搜尋蓋斯克科技的企業 WiFi / 網路建置內容',
                        'url'         => 'https://zonetech.tw/?s={query}',
                        'sha256'      => hash( 'sha256', 'search:zonetech.tw' ),
                    ],
                    [
                        'name'        => 'contact',
                        'type'        => 'action',
                        'description' => '聯絡蓋斯克科技詢問企業無線網路建置報價',
                        'url'         => 'https://zonetech.tw/contact/',
                        'sha256'      => hash( 'sha256', 'contact:zonetech.tw' ),
                    ],
                ],
            ];
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── API Catalog (RFC 9727) — 指向 WP REST API ───────────────
        case '/.well-known/api-catalog':
            $catalog = [
                'linkset' => [
                    [
                        'anchor'       => 'https://zonetech.tw/wp-json/',
                        'service-desc' => [ [ 'href' => 'https://zonetech.tw/wp-json/' ] ],
                        'service-doc'  => [ [ 'href' => 'https://developer.wordpress.org/rest-api/' ] ],
                    ],
                ],
            ];
            header( 'Content-Type: application/linkset+json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── OpenAPI / MPP (/openapi.json) ────────────────────────────
        case '/openapi.json':
            $openapi = [
                'openapi' => '3.1.0',
                'info'    => [
                    'title'       => '蓋斯克科技-ZONETECH',
                    'version'     => '1.0.0',
                    'description' => '企業級大型場域無線網路建置｜智慧工廠WiFi｜倉儲WiFi',
                ],
                'servers' => [ [ 'url' => 'https://zonetech.tw' ] ],
                'paths'   => [
                    '/contact/' => [
                        'get' => [
                            'summary'     => '企業網路建置諮詢頁面',
                            'operationId' => 'getContactPage',
                            'responses'   => [ '200' => [ 'description' => 'HTML 表單頁面' ] ],
                        ],
                    ],
                    '/' => [
                        'get' => [
                            'summary'     => '企業無線網路建置首頁',
                            'operationId' => 'getHomePage',
                            'responses'   => [ '200' => [ 'description' => 'HTML 首頁' ] ],
                        ],
                    ],
                ],
            ];
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $openapi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── ACP (Agentic Commerce Protocol) ─────────────────────────
        case '/.well-known/acp.json':
            $acp = [
                'protocol' => [ 'name' => 'acp', 'version' => '1.0' ],
                'api_base_url' => 'https://zonetech.tw/wp-json/',
                'transports'   => [ 'https' ],
                'capabilities' => [
                    'services' => [ 'inquiry', 'consultation' ],
                ],
                'inquiry_endpoint' => 'https://zonetech.tw/contact/',
            ];
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $acp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── UCP (Universal Commerce Protocol) ───────────────────────
        case '/.well-known/ucp':
            $ucp = [
                'protocol_version' => '1.0',
                'services'         => [ 'inquiry' ],
                'capabilities'     => [
                    'inquiry' => [ 'endpoint' => 'https://zonetech.tw/contact/' ],
                ],
                'spec' => 'https://ucp.dev/specification/overview/',
            ];
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( $ucp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── Web Bot Auth — 空 JWKS（無簽名需求，停止重定向即可）──────
        case '/.well-known/http-message-signatures-directory':
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( [ 'keys' => [] ] );
            exit;

        // ── OAuth Protected Resource（無受保護 API，回傳空宣告）──────
        case '/.well-known/oauth-protected-resource':
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( [
                'resource'              => 'https://zonetech.tw/',
                'authorization_servers' => [],
                'scopes_supported'      => [],
            ] );
            exit;

        // ── OAuth Authorization Server Metadata（RFC 8414）──────────
        // ── OpenID Connect Discovery（OIDC Core §4）────────────────
        // Token 流程：client_credentials，client_id = WP 帳號，
        //             client_secret = WP Application Password
        case '/.well-known/openid-configuration':
        case '/.well-known/oauth-authorization-server':
            $is_oidc = ( $path === '/.well-known/openid-configuration' );
            $meta = [
                'issuer'                                => 'https://zonetech.tw',
                'token_endpoint'                        => 'https://zonetech.tw/wp-json/zonetech/v1/token',
                'jwks_uri'                              => 'https://zonetech.tw/.well-known/jwks.json',
                'grant_types_supported'                 => [ 'client_credentials' ],
                'token_endpoint_auth_methods_supported' => [ 'client_secret_basic', 'client_secret_post' ],
                'scopes_supported'                      => [ 'api:read' ],
                'response_types_supported'              => [ 'token' ],
                'service_documentation'                 => 'https://zonetech.tw/openapi.json',
                'ui_locales_supported'                  => [ 'zh-TW', 'en' ],
            ];
            if ( $is_oidc ) {
                // OIDC 額外欄位（OpenID Connect Discovery 1.0 §3）
                $meta['userinfo_endpoint']                     = 'https://zonetech.tw/wp-json/wp/v2/users/me';
                $meta['subject_types_supported']               = [ 'public' ];
                $meta['id_token_signing_alg_values_supported'] = [ 'RS256' ];
                $meta['claims_supported']                      = [ 'sub', 'name', 'email' ];
            }
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=3600' );
            echo json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
            exit;

        // ── JWKS（無 JWT 簽名需求，返回空 keyset）────────────────────
        case '/.well-known/jwks.json':
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=86400' );
            echo json_encode( [ 'keys' => [] ] );
            exit;
    }
}


// ── OAuth Token Endpoint：client_credentials → WP Application Password ──
// Agent 用法：POST /wp-json/zonetech/v1/token
//   grant_type=client_credentials&client_id=<wp_user>&client_secret=<app_pass>
//   或 Authorization: Basic base64(client_id:client_secret)
// 回傳：{ access_token, token_type:"Bearer", expires_in, scope }
// access_token 可直接當 Authorization: Bearer 或 Basic 頭送後續請求
add_action( 'rest_api_init', 'ztk_register_token_endpoint' );
function ztk_register_token_endpoint() {
    register_rest_route( 'zonetech/v1', '/token', [
        'methods'             => [ 'POST' ],
        'callback'            => 'ztk_token_endpoint_cb',
        'permission_callback' => '__return_true',
    ] );
}
function ztk_token_endpoint_cb( WP_REST_Request $req ) {
    $grant_type    = sanitize_text_field( $req->get_param( 'grant_type' ) ?? '' );
    $client_id     = sanitize_text_field( $req->get_param( 'client_id' )  ?? '' );
    $client_secret = $req->get_param( 'client_secret' ) ?? '';

    // 支援 HTTP Basic Auth 傳入憑證（RFC 6749 §2.3.1）
    if ( ( ! $client_id || ! $client_secret ) ) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION']
            ?? ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' );
        if ( preg_match( '/^Basic\s+(.+)$/i', $auth_header, $am ) ) {
            $decoded = base64_decode( $am[1], true );
            if ( $decoded && str_contains( $decoded, ':' ) ) {
                [ $client_id, $client_secret ] = explode( ':', $decoded, 2 );
                $client_id = sanitize_text_field( $client_id );
            }
        }
    }

    if ( $grant_type !== 'client_credentials' ) {
        return new WP_Error( 'unsupported_grant_type',
            'Only client_credentials is supported.', [ 'status' => 400 ] );
    }
    if ( ! $client_id || ! $client_secret ) {
        return new WP_Error( 'invalid_client',
            'client_id and client_secret are required.', [ 'status' => 401 ] );
    }

    // wp_authenticate 會經由 authenticate filter 驗證 WP Application Password
    $user = wp_authenticate( $client_id, $client_secret );
    if ( is_wp_error( $user ) ) {
        // 模糊化錯誤訊息，防止帳號列舉攻擊
        return new WP_Error( 'invalid_client',
            'Invalid client_id or client_secret.', [ 'status' => 401 ] );
    }

    // 發行 opaque Bearer token（base64 encode 供後續 Basic Auth 使用）
    $token    = base64_encode( $client_id . ':' . $client_secret );
    $response = rest_ensure_response( [
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'expires_in'   => 3600,
        'scope'        => 'api:read',
    ] );
    $response->set_status( 200 );
    return $response;
}

// ── WebMCP — 讓支援 navigator.modelContext 的 AI agent 在頁面上直接發現工具 ──
add_action( 'wp_head', 'ztk_webmcp_inline', 20 );
function ztk_webmcp_inline() {
    ?>
<script>
(function(){
  if(typeof navigator==='undefined'||!navigator.modelContext){return;}
  try{
    navigator.modelContext.provideContext({
      site:{
        name:'蓋斯克科技-ZONETECH',
        description:'企業級大型場域無線網路建置｜智慧工廠WiFi｜倉儲WiFi｜辦公室網路規劃',
        url:'https://zonetech.tw',
        language:'zh-TW'
      },
      tools:[
        {
          name:'search',
          description:'搜尋蓋斯克科技企業WiFi與網路建置內容',
          inputSchema:{type:'object',properties:{query:{type:'string',description:'搜尋關鍵字'}},required:['query']},
          execute:async function(p){window.location.href='https://zonetech.tw/?s='+encodeURIComponent(p.query);}
        },
        {
          name:'contact',
          description:'前往諮詢頁面，詢問企業無線網路建置報價',
          inputSchema:{type:'object',properties:{}},
          execute:async function(){window.location.href='https://zonetech.tw/contact/';}
        }
      ]
    });
  }catch(e){}
})();
</script>
    <?php
}

// ── Markdown for Agents ────────────────────────────────────────────────
// 當請求帶有 Accept: text/markdown 時回傳乾淨的 Markdown 版本
add_action( 'template_redirect', 'ztk_markdown_for_agents', 1 );
function ztk_markdown_for_agents() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ( stripos( $accept, 'text/markdown' ) === false ) { return; }

    // 取得當前頁面/文章內容
    $title   = wp_title( '|', false, 'right' ) ?: get_bloginfo( 'name' );
    $content = '';
    $excerpt = '';

    if ( is_singular() ) {
        $post    = get_queried_object();
        $title   = get_the_title( $post );
        $content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
    } elseif ( is_front_page() ) {
        $title   = get_bloginfo( 'name' );
        $content = '蓋斯克科技（ZONETECH）專注台灣大型場域企業級無線網路建置，服務智慧工廠、大型倉儲、辦公室、商務中心。' .
                   '主要服務：企業級WiFi規劃、多WAN頻寬整合、企業防火牆建置。';
    }

    $md  = "# {$title}\n\n";
    if ( $excerpt ) { $md .= "> {$excerpt}\n\n"; }
    if ( $content ) { $md .= $content . "\n"; }
    $md .= "\n---\n來源：" . esc_url( home_url( $_SERVER['REQUEST_URI'] ) ) . "\n";
    $md .= "網站：https://zonetech.tw\n";

    $tokens = str_word_count( $md );

    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'X-Markdown-Tokens: ' . $tokens );
    header( 'Cache-Control: public, max-age=3600' );
    header( 'Vary: Accept' );
    echo $md;
    exit;
}


// =====================================================================
// 7. SEOPress 補完 — 修正後台 High Impact 通知
// =====================================================================

// ── 7a. Filter 直接覆寫輸出（立即生效，不管資料庫是否更新）───────────
add_filter( 'seopress_titles_title', 'ztk_seopress_title_fix', 10 );
function ztk_seopress_title_fix( $title ) {
    if ( is_post_type_archive( 'faq_cpt' ) ) {
        return '常見問題 | 蓋斯克科技企業WiFi建置';
    }
    if ( is_category() || is_tag() || is_tax() ) {
        $term = get_queried_object();
        if ( $term && ! empty( $term->name ) ) {
            return $term->name . ' | 蓋斯克科技';
        }
    }
    return $title;
}

add_filter( 'seopress_titles_desc', 'ztk_seopress_desc_fix', 10 );
function ztk_seopress_desc_fix( $desc ) {
    if ( is_post_type_archive( 'faq_cpt' ) ) {
        return '蓋斯克科技企業無線網路建置常見問題：工廠WiFi、倉儲WiFi、辦公室網路、商務中心網路規劃疑問一次解答。';
    }
    if ( is_category() || is_tag() || is_tax() ) {
        $term = get_queried_object();
        if ( $term && ! empty( $term->description ) ) {
            return $term->description;
        }
        return '蓋斯克科技企業WiFi建置相關文章 — 智慧工廠、大型倉儲、辦公室、商務中心無線網路規劃實務。';
    }
    return $desc;
}

// ── 7b. 更新 SEOPress 資料庫設定，讓後台通知消失（idempotent，只寫一次）─
add_action( 'admin_init', 'ztk_seopress_fill_missing_settings', 1 );
function ztk_seopress_fill_missing_settings() {
    if ( ! defined( 'SEOPRESS_VERSION' ) ) { return; }

    $option  = get_option( '_seopress_titles_settings', [] );
    $changed = false;

    // faq_cpt — 自訂文章類型存檔頁
    $cpt_defaults = [
        'seopress_titles_archive_faq_cpt_titles_title' => '常見問題 %%sep%% %%sitename%%',
        'seopress_titles_archive_faq_cpt_titles_desc'  => '蓋斯克科技企業無線網路建置常見問題解答，涵蓋工廠WiFi、倉儲WiFi、辦公室網路規劃。',
    ];
    foreach ( $cpt_defaults as $key => $val ) {
        if ( empty( $option[ $key ] ) ) {
            $option[ $key ] = $val;
            $changed = true;
        }
    }

    // 分類 / 標籤 / 自訂分類法
    $tax_list = [ 'category', 'post_tag', 'post_format', 'blog-tag', 'blog-category' ];
    foreach ( $tax_list as $tax ) {
        $slug = str_replace( '-', '_', $tax ); // blog-tag → blog_tag
        $defaults = [
            "seopress_titles_tax_{$slug}_titles_title" => '%%term_title%% %%sep%% %%sitename%%',
            "seopress_titles_tax_{$slug}_titles_desc"  => '%%term_description%%',
        ];
        foreach ( $defaults as $key => $val ) {
            if ( empty( $option[ $key ] ) ) {
                $option[ $key ] = $val;
                $changed = true;
            }
        }
    }

    if ( $changed ) {
        update_option( '_seopress_titles_settings', $option );
    }
}

// ── 7c. RSS 只顯示摘要（防止內容被第三方站台抓走）──────────────────────
// WordPress 設定 rss_use_excerpt：0 = 全文，1 = 摘要
add_filter( 'pre_option_rss_use_excerpt', function() { return '1'; } );
