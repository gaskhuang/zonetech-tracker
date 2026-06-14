<?php
/**
 * Plugin Name: AI Crawler Logger
 * Description: Logs visits from AI crawlers (GPTBot, ClaudeBot, etc.) and exposes a token-protected REST endpoint for daily aggregation.
 * Version: 0.1.0
 * Author: zonetech tracker
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// =====================================================================
// CONFIG — edit AICL_TOKEN before uploading. Use a 32-char random string.
// =====================================================================
if ( ! defined( 'AICL_TOKEN' ) ) {
    define( 'AICL_TOKEN', '1a9dcba0ef3226dcc563821ea4ddb085' );
}
if ( ! defined( 'AICL_RETENTION_DAYS' ) ) {
    define( 'AICL_RETENTION_DAYS', 90 );
}

// 20 known AI crawler User-Agent fingerprints.
// Source: aeo-crawler-check (https://github.com/sstklen/aeo-crawler-check)
function aicl_bot_patterns() {
    return [
        // AI crawlers (training + search)
        'GPTBot'              => '/GPTBot/i',
        'ChatGPT-User'        => '/ChatGPT-User/i',
        'OAI-SearchBot'       => '/OAI-SearchBot/i',
        'ClaudeBot'           => '/ClaudeBot/i',
        'Claude-SearchBot'    => '/Claude-SearchBot/i',
        'Anthropic-AI'        => '/anthropic-ai/i',
        'Google-Extended'     => '/Google-Extended/i',
        'PerplexityBot'       => '/PerplexityBot/i',
        'Applebot-Extended'   => '/Applebot-Extended/i',
        'Amazonbot'           => '/Amazonbot/i',
        'xAI-Grok'            => '/xAI[- ]?Grok|Grok-?Bot/i',
        'MistralAI-User'      => '/MistralAI-User/i',
        'Bytespider'          => '/Bytespider/i',
        'meta-externalagent'  => '/meta-externalagent/i',
        'meta-webindexer'     => '/meta-webindexer/i',
        'meta-externalads'    => '/meta-externalads/i',
        'CohereBot'           => '/cohere-?ai|CohereBot/i',
        'Bravebot'            => '/Bravebot/i',
        'CCBot'               => '/CCBot/i',
        'DuckAssistBot'       => '/DuckAssistBot/i',
        'YouBot'              => '/YouBot/i',
        'Diffbot'             => '/Diffbot/i',
        // Search engine crawlers (Cloudflare Top 15)
        'Googlebot'           => '/Googlebot(?!-Image|-Video|-News|-AdsBot)/i',
        'bingbot'             => '/bingbot/i',
        'Applebot'            => '/Applebot(?!-Extended)/i',
        'FacebookExternalHit' => '/facebookexternalhit/i',
        'Google-AdsBot'       => '/AdsBot-Google/i',
        'Baiduspider'         => '/Baiduspider/i',
        'PinterestBot'        => '/Pinterestbot/i',
        'YandexBot'           => '/YandexBot/i',
        // SEO analysis tools
        'SemrushBot'          => '/SemrushBot/i',
        'AhrefsBot'           => '/AhrefsBot/i',
    ];
}

// ---------------------------------------------------------------------
// 1. Create table on activation (mu-plugin: run on every load, idempotent)
// ---------------------------------------------------------------------
function aicl_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'ai_crawler_log';
}

function aicl_maybe_install_table() {
    global $wpdb;
    $installed = (int) get_option( 'aicl_db_version', 0 );
    if ( $installed >= 1 ) { return; }

    $table   = aicl_table_name();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        ua VARCHAR(512) NOT NULL DEFAULT '',
        uri VARCHAR(512) NOT NULL DEFAULT '',
        bot_name VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY idx_ts (ts),
        KEY idx_bot (bot_name)
    ) {$charset};" );
    update_option( 'aicl_db_version', 1 );
}
add_action( 'plugins_loaded', 'aicl_maybe_install_table' );

// ---------------------------------------------------------------------
// 2. Match UA on every request, INSERT if hit
// ---------------------------------------------------------------------
function aicl_log_if_bot() {
    if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) { return; }
    $ua = (string) $_SERVER['HTTP_USER_AGENT'];

    $bot = '';
    foreach ( aicl_bot_patterns() as $name => $pattern ) {
        if ( preg_match( $pattern, $ua ) ) { $bot = $name; break; }
    }
    if ( $bot === '' ) { return; }

    global $wpdb;
    $wpdb->insert(
        aicl_table_name(),
        [
            'ts'       => current_time( 'mysql', true ), // GMT
            'ip'       => substr( aicl_client_ip(), 0, 64 ),
            'ua'       => substr( $ua, 0, 512 ),
            'uri'      => substr( ( $_SERVER['REQUEST_URI'] ?? '' ), 0, 512 ),
            'bot_name' => $bot,
        ]
    );
}
add_action( 'init', 'aicl_log_if_bot', 1 );

function aicl_client_ip() {
    $candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
    foreach ( $candidates as $k ) {
        if ( ! empty( $_SERVER[ $k ] ) ) {
            $ip = explode( ',', $_SERVER[ $k ] )[0];
            return trim( $ip );
        }
    }
    return '';
}

// ---------------------------------------------------------------------
// 3. REST endpoint: GET /wp-json/aitracker/v1/logs?date=YYYY-MM-DD
//    Auth: Authorization: Bearer <AICL_TOKEN>
// ---------------------------------------------------------------------
add_action( 'rest_api_init', function () {
    register_rest_route( 'aitracker/v1', '/logs', [
        'methods'             => 'GET',
        'permission_callback' => 'aicl_check_bearer',
        'callback'            => 'aicl_rest_logs',
        'args'                => [
            'date' => [
                'required'          => false,
                'validate_callback' => function ( $v ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ); },
            ],
        ],
    ] );
    register_rest_route( 'aitracker/v1', '/health', [
        'methods'             => 'GET',
        'permission_callback' => 'aicl_check_bearer',
        'callback'            => function () {
            global $wpdb;
            $t = aicl_table_name();
            return [
                'ok'         => true,
                'total_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
                'newest'     => $wpdb->get_var( "SELECT MAX(ts) FROM {$t}" ),
                'oldest'     => $wpdb->get_var( "SELECT MIN(ts) FROM {$t}" ),
            ];
        },
    ] );
} );

function aicl_check_bearer( $req ) {
    $auth = $req->get_header( 'authorization' );
    if ( ! $auth || stripos( $auth, 'Bearer ' ) !== 0 ) { return false; }
    $given = trim( substr( $auth, 7 ) );
    return hash_equals( AICL_TOKEN, $given );
}

function aicl_rest_logs( $req ) {
    global $wpdb;
    $date = $req->get_param( 'date' );
    if ( ! $date ) { $date = gmdate( 'Y-m-d', time() - 86400 ); } // default yesterday UTC
    $start = $date . ' 00:00:00';
    $end   = $date . ' 23:59:59';
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT ts, ip, ua, uri, bot_name FROM " . aicl_table_name() . " WHERE ts BETWEEN %s AND %s ORDER BY ts ASC LIMIT 50000",
        $start, $end
    ), ARRAY_A );
    return [
        'date'  => $date,
        'count' => count( $rows ),
        'rows'  => $rows,
    ];
}

// ---------------------------------------------------------------------
// 4. Daily prune via WP-Cron
// ---------------------------------------------------------------------
add_action( 'aicl_daily_prune', 'aicl_prune_old' );
function aicl_prune_old() {
    global $wpdb;
    $days  = (int) AICL_RETENTION_DAYS;
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . aicl_table_name() . " WHERE ts < %s", $cutoff ) );
}
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'aicl_daily_prune' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'aicl_daily_prune' );
    }
} );
