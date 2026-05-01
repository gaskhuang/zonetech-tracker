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

// 不相關舊頁面：AI 爬蟲看到這些頁面會誤解業務定位，全部 noindex
// slug 格式：tag slug 或完整 path（不含網域）
define( 'ZTK_NOINDEX_TAG_SLUGS', [
    '租賃', 'qnap維修', '直播課程', '勒索病毒2021',
] );
define( 'ZTK_NOINDEX_PATHS', [
    '/blogs/4k-computer-clip-outfit/',
    '/category/showcase/computer-repair/',
] );


// =====================================================================
// 0. 自動把 App ID 寫入 Yoast Social 設定（省去手動填表）
// =====================================================================
add_action( 'plugins_loaded', 'ztk_sync_yoast_fb_app_id' );
function ztk_sync_yoast_fb_app_id() {
    if ( ! class_exists( 'WPSEO_Options' ) ) { return; } // Yoast 未安裝則跳過
    $social = get_option( 'wpseo_social', [] );
    if ( ( $social['facebook_app_id'] ?? '' ) === ZTK_FB_APP_ID ) { return; } // 已正確，跳過
    $social['facebook_app_id'] = ZTK_FB_APP_ID;
    update_option( 'wpseo_social', $social );
}


// =====================================================================
// 0b. Noindex 舊業務/不相關頁面（讓 AI 爬蟲跳過這些，不要誤解業務定位）
// =====================================================================
add_action( 'wp_head', 'ztk_noindex_old_pages', 1 );
function ztk_noindex_old_pages() {
    $path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

    // 檢查是否在 noindex path 清單
    foreach ( ZTK_NOINDEX_PATHS as $p ) {
        if ( rtrim( $path, '/' ) === rtrim( $p, '/' ) ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
            return;
        }
    }

    // 檢查是否為 noindex tag 頁面
    if ( is_tag() ) {
        $tag = get_queried_object();
        if ( $tag && in_array( $tag->slug, ZTK_NOINDEX_TAG_SLUGS, true ) ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }
    }
}

// Yoast 的 robots filter（雙重保險，讓 Yoast 也輸出 noindex）
add_filter( 'wpseo_robots', 'ztk_yoast_noindex_filter' );
function ztk_yoast_noindex_filter( $robots ) {
    $path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    foreach ( ZTK_NOINDEX_PATHS as $p ) {
        if ( rtrim( $path, '/' ) === rtrim( $p, '/' ) ) {
            return 'noindex, nofollow';
        }
    }
    if ( is_tag() ) {
        $tag = get_queried_object();
        if ( $tag && in_array( $tag->slug, ZTK_NOINDEX_TAG_SLUGS, true ) ) {
            return 'noindex, nofollow';
        }
    }
    return $robots;
}


// =====================================================================
// 1. robots.txt — 明確歡迎 15 個主要爬蟲
//    WordPress 的 robots_txt filter 在 virtual robots.txt 產生時觸發
// =====================================================================
add_filter( 'robots_txt', 'ztk_robots_txt', 20, 2 );
function ztk_robots_txt( $output, $public ) {
    $extra = <<<'EOT'

# ========================================================
# 明確歡迎主要搜尋引擎 & AI 爬蟲
# ========================================================

# Google Search + Discover
User-agent: Googlebot
Allow: /

# Google Ads 品質審核
User-agent: AdsBot-Google
Allow: /

# Microsoft Bing
User-agent: bingbot
Allow: /

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

# Meta — Facebook/Instagram 分享預覽（抓 OG 標籤）
User-agent: facebookexternalhit
Allow: /

# Meta — Meta AI 搜尋索引（meta-webindexer）
User-agent: meta-webindexer
Allow: /

# Meta — AI 模型訓練（meta-externalagent）
User-agent: meta-externalagent
Allow: /

# Meta — 廣告商品審核（meta-externalads）
User-agent: meta-externalads
Allow: /

# OpenAI ChatGPT / SearchGPT
User-agent: GPTBot
Allow: /
User-agent: ChatGPT-User
Allow: /
User-agent: OAI-SearchBot
Allow: /

# Amazon Alexa AI
User-agent: Amazonbot
Allow: /

# Pinterest
User-agent: Pinterestbot
Allow: /

# SEO 分析工具（允許爬取方便追蹤競爭情報）
User-agent: SemrushBot
Allow: /
User-agent: AhrefsBot
Allow: /
EOT;
    return $output . $extra;
}


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
// 4. LocalBusiness JSON-LD（首頁）
//    修正舊 WebSite schema 只寫「4K影像剪輯」的錯誤；
//    讓 GoogleBot、BingBot、Meta AI 等正確理解業務內容
// =====================================================================
add_action( 'wp_head', 'ztk_localbusiness_jsonld', 5 );
function ztk_localbusiness_jsonld() {
    if ( ! is_front_page() ) { return; }

    $schema = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'         => 'Organization',
                '@id'           => 'https://zonetech.tw/#organization',
                'name'          => '蓋斯克科技',
                'alternateName' => 'ZONETECH',
                'url'           => 'https://zonetech.tw',
                'image'         => ZTK_OG_IMAGE_URL,
                'description'   => '專注台灣大型場域企業級無線網路建置，服務智慧工廠、大型倉儲（1000坪以上）、辦公商辦、商務中心。主要服務：企業級WiFi規劃、多WAN頻寬整合、跨樓層網路建置。',
                'areaServed'    => [ [ '@type' => 'Country', 'name' => '台灣' ] ],
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
                    'availableLanguage' => [ 'Chinese' ],
                ],
            ],
        ],
    ];

    $json = json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
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
