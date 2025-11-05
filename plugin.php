<?php
/*
Plugin Name: Yourls Discord Notify
Plugin URI: 
Description: Sends a notification to Discord when a short is created
Version: 1.6
Author: 
Author URI: 
*/

// No direct call
if (!defined('YOURLS_ABSPATH')) {
    die();
}

/* ------------------------------------------------------------------ */
/*  Daily/Weekly Summary Notifications                                */
/* ------------------------------------------------------------------ */

// Check if it's time to send summaries (called on admin page loads)
yourls_add_action('admin_init', 'notifier_check_summaries');
function notifier_check_summaries() {
    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $daily_enabled = yourls_get_option('notifier_daily_summary', false);
    $weekly_enabled = yourls_get_option('notifier_weekly_summary', false);

    if ($daily_enabled) {
        notifier_maybe_send_daily_summary($webhook);
    }

    if ($weekly_enabled) {
        notifier_maybe_send_weekly_summary($webhook);
    }
}

function notifier_maybe_send_daily_summary($webhook) {
    $last_sent = yourls_get_option('notifier_last_daily_summary', 0);
    $now = time();
    $today_start = strtotime('today midnight');

    // Only send once per day, after midnight
    if ($last_sent < $today_start) {
        notifier_send_daily_summary($webhook);
        yourls_update_option('notifier_last_daily_summary', $now);
    }
}

function notifier_maybe_send_weekly_summary($webhook) {
    $last_sent = yourls_get_option('notifier_last_weekly_summary', 0);
    $now = time();
    $week_start = strtotime('last Monday midnight');

    // Only send once per week, on Monday
    if ($last_sent < $week_start && date('N') == 1) { // 1 = Monday
        notifier_send_weekly_summary($webhook);
        yourls_update_option('notifier_last_weekly_summary', $now);
    }
}

function notifier_send_daily_summary($webhook) {
    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $stats = yourls_get_db_stats();

    // Get yesterday's stats
    $yesterday_start = strtotime('yesterday midnight');
    $yesterday_end = strtotime('today midnight');
    
    $table = YOURLS_DB_TABLE_URL;
    $log_table = YOURLS_DB_TABLE_LOG;
    
    // New links created yesterday
    $new_links = yourls_get_db()->fetchValue(
        "SELECT COUNT(*) FROM `$table` WHERE UNIX_TIMESTAMP(`timestamp`) >= :start AND UNIX_TIMESTAMP(`timestamp`) < :end",
        ['start' => $yesterday_start, 'end' => $yesterday_end]
    );

    // Clicks yesterday
    $clicks_yesterday = yourls_get_db()->fetchValue(
        "SELECT COUNT(*) FROM `$log_table` WHERE UNIX_TIMESTAMP(`click_time`) >= :start AND UNIX_TIMESTAMP(`click_time`) < :end",
        ['start' => $yesterday_start, 'end' => $yesterday_end]
    );

    // Top 5 clicked links yesterday
    $top_links = yourls_get_db()->fetchObjects(
        "SELECT keyword, COUNT(*) as clicks FROM `$log_table` 
         WHERE UNIX_TIMESTAMP(`click_time`) >= :start AND UNIX_TIMESTAMP(`click_time`) < :end
         GROUP BY keyword ORDER BY clicks DESC LIMIT 5",
        ['start' => $yesterday_start, 'end' => $yesterday_end]
    );

    $top_links_text = '';
    if (!empty($top_links)) {
        foreach ($top_links as $link) {
            $short_url = YOURLS_SITE . '/' . $link->keyword;
            $top_links_text .= "• `{$link->keyword}` - {$link->clicks} clicks\n";
        }
    } else {
        $top_links_text = "No clicks yesterday";
    }

    $description = "**Yesterday's Activity Summary**\n" . date('F j, Y', $yesterday_start);

    $fields = [
        ['name' => '🔗 Total Links', 'value' => number_format($stats['total_links']), 'inline' => true],
        ['name' => '📊 Total Clicks', 'value' => number_format($stats['total_clicks']), 'inline' => true],
        ['name' => '➕ New Links', 'value' => number_format($new_links), 'inline' => true],
        ['name' => '👆 Clicks Yesterday', 'value' => number_format($clicks_yesterday), 'inline' => true],
        ['name' => '🏆 Top Links Yesterday', 'value' => $top_links_text, 'inline' => false],
    ];

    $embed = [
        'title' => "📅 Daily Summary ($display_domain)",
        'description' => $description,
        'color' => 0x5865F2, // Blurple
        'fields' => $fields,
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c'),
    ];

    notifier_discord($webhook, $embed);
}

function notifier_send_weekly_summary($webhook) {
    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $stats = yourls_get_db_stats();

    // Get last week's stats (Monday to Sunday)
    $week_start = strtotime('last Monday midnight', strtotime('yesterday'));
    $week_end = strtotime('last Sunday 23:59:59', strtotime('yesterday')) + 1;
    
    $table = YOURLS_DB_TABLE_URL;
    $log_table = YOURLS_DB_TABLE_LOG;
    
    // New links created last week
    $new_links = yourls_get_db()->fetchValue(
        "SELECT COUNT(*) FROM `$table` WHERE UNIX_TIMESTAMP(`timestamp`) >= :start AND UNIX_TIMESTAMP(`timestamp`) < :end",
        ['start' => $week_start, 'end' => $week_end]
    );

    // Clicks last week
    $clicks_week = yourls_get_db()->fetchValue(
        "SELECT COUNT(*) FROM `$log_table` WHERE UNIX_TIMESTAMP(`click_time`) >= :start AND UNIX_TIMESTAMP(`click_time`) < :end",
        ['start' => $week_start, 'end' => $week_end]
    );

    // Top 10 clicked links last week
    $top_links = yourls_get_db()->fetchObjects(
        "SELECT keyword, COUNT(*) as clicks FROM `$log_table` 
         WHERE UNIX_TIMESTAMP(`click_time`) >= :start AND UNIX_TIMESTAMP(`click_time`) < :end
         GROUP BY keyword ORDER BY clicks DESC LIMIT 10",
        ['start' => $week_start, 'end' => $week_end]
    );

    $top_links_text = '';
    if (!empty($top_links)) {
        foreach ($top_links as $link) {
            $short_url = YOURLS_SITE . '/' . $link->keyword;
            $top_links_text .= "• `{$link->keyword}` - {$link->clicks} clicks\n";
        }
    } else {
        $top_links_text = "No clicks last week";
    }

    $description = "**Last Week's Activity Summary**\n" . 
                   date('M j', $week_start) . ' - ' . date('M j, Y', $week_end - 1);

    $fields = [
        ['name' => '🔗 Total Links', 'value' => number_format($stats['total_links']), 'inline' => true],
        ['name' => '📊 Total Clicks', 'value' => number_format($stats['total_clicks']), 'inline' => true],
        ['name' => '➕ New Links', 'value' => number_format($new_links), 'inline' => true],
        ['name' => '👆 Clicks Last Week', 'value' => number_format($clicks_week), 'inline' => true],
        ['name' => '🏆 Top 10 Links Last Week', 'value' => $top_links_text, 'inline' => false],
    ];

    $embed = [
        'title' => "📅 Weekly Summary ($display_domain)",
        'description' => $description,
        'color' => 0x5865F2, // Blurple
        'fields' => $fields,
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c'),
    ];

    notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Link Deleted                                                      */
/* ------------------------------------------------------------------ */
function notifier_delete_link($args) {
    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $keyword = $args[0];
    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $username = defined('YOURLS_USER') ? YOURLS_USER : 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Try to get URL info before it's deleted (might not be available)
    $url_info = yourls_get_keyword_infos($keyword);
    $long_url = isset($url_info['url']) ? $url_info['url'] : 'Unknown';
    $clicks = isset($url_info['clicks']) ? $url_info['clicks'] : 'Unknown';

    $embed = [
        'title' => "🗑️ Short URL Deleted ($display_domain)",
        'description' => "A short URL has been permanently deleted",
        'color' => 0xff6b6b, // Red
        'fields' => [
            ['name' => '🔗 Keyword', 'value' => "`$keyword`", 'inline' => true],
            ['name' => '📊 Total Clicks', 'value' => (string)$clicks, 'inline' => true],
            ['name' => '👤 Deleted By', 'value' => "`$username`", 'inline' => true],
            ['name' => '🌐 IP Address', 'value' => $ip, 'inline' => true],
            ['name' => '🔗 Original URL', 'value' => strlen($long_url) > 100 ? substr($long_url, 0, 97) . '...' : $long_url, 'inline' => false],
        ],
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c'),
    ];

    notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Link Edited - Simple success notification with geolocation        */
/* ------------------------------------------------------------------ */
function notifier_edit_link($args) {
    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $username = defined('YOURLS_USER') ? YOURLS_USER : 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Get geolocation if enabled
    $geo_enabled = yourls_get_option('notifier_geolocation_enabled', true);
    $geo = $geo_enabled ? notifier_get_geolocation($ip) : null;

    $fields = [
        ['name' => '👤 Edited By', 'value' => "`$username`", 'inline' => true],
        ['name' => '🌐 IP Address', 'value' => $ip, 'inline' => true],
    ];

    // Add geolocation fields if available
    if ($geo) {
        $location_parts = array_filter([
            $geo['city'] ?? null,
            $geo['regionName'] ?? null,
            $geo['country'] ?? null
        ]);
        $location = implode(', ', $location_parts);
        $flag = isset($geo['countryCode']) ? notifier_get_flag_emoji($geo['countryCode']) : '';
        
        if (!empty($location)) {
            $fields[] = ['name' => '📍 Location', 'value' => "$flag $location", 'inline' => true];
        }
        
        if (!empty($geo['isp'])) {
            $fields[] = ['name' => '🏢 ISP', 'value' => $geo['isp'], 'inline' => true];
        }

        // Add map link if we have coordinates
        if (isset($geo['lat']) && isset($geo['lon'])) {
            $map_url = "https://www.google.com/maps?q={$geo['lat']},{$geo['lon']}";
            $fields[] = ['name' => '🗺️ Map', 'value' => "[View on Map]($map_url)", 'inline' => true];
        }
    }

    $embed = [
        'title' => "✏️ Short URL Edited ($display_domain)",
        'description' => "A short URL has been successfully edited",
        'color' => 0xffa500, // Orange
        'fields' => $fields,
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c'),
    ];

    notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  SUCCESSFUL LOGIN – with geolocation                               */
/* ------------------------------------------------------------------ */
function notifier_auth_successful() {
    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $username = defined('YOURLS_USER') ? YOURLS_USER : ($_POST['username'] ?? 'Unknown');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $domain = yourls_get_option('notifier_display_domain', 'YOURLS');

    // Get geolocation if enabled
    $geo_enabled = yourls_get_option('notifier_geolocation_enabled', true);
    $geo = $geo_enabled ? notifier_get_geolocation($ip) : null;

    $fields = [];
    
    // Username field
    $fields[] = ['name' => '👤 User', 'value' => "`$username`", 'inline' => true];
    
    // IP Address field
    $fields[] = ['name' => '🌐 IP Address', 'value' => $ip, 'inline' => true];

    // Geolocation fields if available
    if ($geo) {
        $location_parts = array_filter([
            $geo['city'] ?? null,
            $geo['regionName'] ?? null,
            $geo['country'] ?? null
        ]);
        $location = implode(', ', $location_parts);
        $flag = isset($geo['countryCode']) ? notifier_get_flag_emoji($geo['countryCode']) : '';
        
        if (!empty($location)) {
            $fields[] = ['name' => '📍 Location', 'value' => "$flag $location", 'inline' => true];
        }
        
        if (!empty($geo['isp'])) {
            $fields[] = ['name' => '🏢 ISP', 'value' => $geo['isp'], 'inline' => true];
        }

        // Add map link if we have coordinates
        if (isset($geo['lat']) && isset($geo['lon'])) {
            $map_url = "https://www.google.com/maps?q={$geo['lat']},{$geo['lon']}";
            $fields[] = ['name' => '🗺️ Map', 'value' => "[View on Map]($map_url)", 'inline' => true];
        }
    }

    // User Agent field
    $fields[] = ['name' => '💻 User Agent', 'value' => substr($user_agent, 0, 100) . (strlen($user_agent) > 100 ? '...' : ''), 'inline' => false];

    $embed = [
        'title' => "✅ Successful Login ($domain)",
        'description' => "**User:** `$username` logged in successfully",
        'color' => 0x00ff00, // Green
        'fields' => $fields,
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c')
    ];

    notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Discord sender – with improved error handling                     */
/* ------------------------------------------------------------------ */
function notifier_discord(string $webhook, array $embed)
{
    $payload = [
        'content' => null,
        'embeds'  => [$embed],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Discord returns 204 on success, 200-299 are generally OK
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Discord notification failed. HTTP Code: $http_code, Error: $error, Response: $response");
        return ['success' => false, 'http_code' => $http_code, 'error' => $error, 'response' => $response];
    }
    return ['success' => true, 'http_code' => $http_code];
}

/* ------------------------------------------------------------------ */
/*  Rate limiting helper for click notifications                      */
/* ------------------------------------------------------------------ */
function notifier_should_notify_click($keyword)
{
    $cooldown = (int)yourls_get_option('notifier_click_cooldown', 300); // 5 min default
    if ($cooldown <= 0) return true; // No rate limiting

    $last_notify_key = "notifier_last_click_$keyword";
    $last_notify = yourls_get_option($last_notify_key, 0);
    $now = time();

    if (($now - $last_notify) < $cooldown) {
        return false; // Too soon
    }

    yourls_update_option($last_notify_key, $now);
    return true;
}

/* ------------------------------------------------------------------ */
/*  New short-URL created                                             */
/* ------------------------------------------------------------------ */
function notifier_post_add_new_link($args)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    $data = $args[3];
    if ($data['status'] !== 'success') return;

    $long_url   = $data['url']['url'];
    $short_url  = $data['shorturl'];
    $keyword    = ltrim(parse_url($short_url, PHP_URL_PATH), '/');
    $ip         = $data['url']['ip'];
    $date       = new DateTime($data['url']['date']);

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $title = "New 🩳 URL created ($display_domain)";

    $embed = [
        'title'       => $title,
        'description' => "**Short URL:** $short_url\n**Long URL:** <$long_url>",
        'color'       => 0x00ff00, // Green
        'fields'      => [
            ['name' => 'Keyword',     'value' => $keyword, 'inline' => true],
            ['name' => 'IP Address',  'value' => $ip,      'inline' => true],
        ],
        'footer'      => ['text' => 'YOURLS Notifier'],
        'timestamp'   => $date->format('c'),
        'thumbnail'   => ['url' => 'https://yourls.org/assets/images/yourls-logo.png'],
    ];

    notifier_discord($discord_webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Short-URL accessed (click) – with rate limiting and geolocation   */
/* ------------------------------------------------------------------ */
function notifier_redirect_shorturl($args)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    $keyword = $args[1];
    
    // Rate limiting check
    if (!notifier_should_notify_click($keyword)) {
        return; // Skip notification due to cooldown
    }

    $long_url  = $args[0];
    $short_url = YOURLS_SITE . '/' . $keyword;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Get click count safely
    try {
        $url_info = yourls_get_keyword_infos($keyword);
        $clicks = isset($url_info['clicks']) ? (int)$url_info['clicks'] + 1 : 'Unknown';
    } catch (Exception $e) {
        $clicks = 'Unknown';
    }

    // Get geolocation if enabled
    $geo_enabled = yourls_get_option('notifier_geolocation_enabled', true);
    $geo = $geo_enabled ? notifier_get_geolocation($ip) : null;

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $title = "🚀 Short URL accessed ($display_domain)";

    $fields = [
        ['name' => '🩳 Short URL', 'value' => $short_url, 'inline' => true],
        ['name' => 'Keyword',  'value' => "`$keyword`", 'inline' => true],
        ['name' => 'Total Clicks', 'value' => (string)$clicks, 'inline' => true],
        ['name' => '🌐 IP Address', 'value' => $ip, 'inline' => true],
    ];

    // Add geolocation fields if available
    if ($geo) {
        $location_parts = array_filter([
            $geo['city'] ?? null,
            $geo['regionName'] ?? null,
            $geo['country'] ?? null
        ]);
        $location = implode(', ', $location_parts);
        $flag = isset($geo['countryCode']) ? notifier_get_flag_emoji($geo['countryCode']) : '';
        
        if (!empty($location)) {
            $fields[] = ['name' => '📍 Location', 'value' => "$flag $location", 'inline' => true];
        }
        
        if (!empty($geo['isp'])) {
            $fields[] = ['name' => '🏢 ISP', 'value' => $geo['isp'], 'inline' => true];
        }

        // Add map link if we have coordinates
        if (isset($geo['lat']) && isset($geo['lon'])) {
            $map_url = "https://www.google.com/maps?q={$geo['lat']},{$geo['lon']}";
            $fields[] = ['name' => '🗺️ Map', 'value' => "[View on Map]($map_url)", 'inline' => true];
        }
    }

    $embed = [
        'title'       => $title,
        'description' => "**Redirect:** $short_url → <$long_url>",
        'color'       => 0x0099ff, // Blue
        'fields'      => $fields,
        'footer'      => ['text' => 'YOURLS Notifier'],
        'timestamp'   => (new DateTime())->format('c'),
    ];

    notifier_discord($discord_webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  IP Geolocation lookup                                             */
/* ------------------------------------------------------------------ */
function notifier_get_geolocation($ip) {
    // Skip private/local IPs
    if ($ip === 'Unknown' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    // Cache geolocation for 24 hours to avoid rate limits
    $cache_key = "notifier_geo_$ip";
    $cached = yourls_get_option($cache_key);
    if ($cached && isset($cached['timestamp']) && (time() - $cached['timestamp']) < 86400) {
        return $cached['data'];
    }

    // Use ip-api.com (free, no key required, 45 req/min limit)
    $api_url = "http://ip-api.com/json/$ip?fields=status,message,country,countryCode,regionName,city,isp,org,lat,lon";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || $data['status'] !== 'success') {
        return null;
    }

    // Cache the result
    yourls_update_option($cache_key, [
        'timestamp' => time(),
        'data' => $data
    ]);

    return $data;
}

/* ------------------------------------------------------------------ */
/*  FAILED LOGIN – with geolocation                                   */
/* ------------------------------------------------------------------ */
function notifier_login_failed() {
    // Only send notification if credentials were actually submitted
    if (empty($_POST['username']) && empty($_POST['password'])) {
        return; // Login page just loaded, not an actual failed attempt
    }

    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $username = $_POST['username'] ?? 'Unknown';
    // SECURITY: Never log passwords, even masked
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $domain = yourls_get_option('notifier_display_domain', 'YOURLS');

    // Get geolocation if enabled
    $geo_enabled = yourls_get_option('notifier_geolocation_enabled', true);
    $geo = $geo_enabled ? notifier_get_geolocation($ip) : null;

    $fields = [];
    
    // IP Address field
    $fields[] = ['name' => '🌐 IP Address', 'value' => $ip, 'inline' => true];

    // Geolocation fields if available
    if ($geo) {
        $location_parts = array_filter([
            $geo['city'] ?? null,
            $geo['regionName'] ?? null,
            $geo['country'] ?? null
        ]);
        $location = implode(', ', $location_parts);
        $flag = isset($geo['countryCode']) ? notifier_get_flag_emoji($geo['countryCode']) : '';
        
        if (!empty($location)) {
            $fields[] = ['name' => '📍 Location', 'value' => "$flag $location", 'inline' => true];
        }
        
        if (!empty($geo['isp'])) {
            $fields[] = ['name' => '🏢 ISP', 'value' => $geo['isp'], 'inline' => true];
        }

        // Add map link if we have coordinates
        if (isset($geo['lat']) && isset($geo['lon'])) {
            $map_url = "https://www.google.com/maps?q={$geo['lat']},{$geo['lon']}";
            $fields[] = ['name' => '🗺️ Map', 'value' => "[View on Map]($map_url)", 'inline' => true];
        }
    }

    // User Agent field
    $fields[] = ['name' => '💻 User Agent', 'value' => substr($user_agent, 0, 100) . (strlen($user_agent) > 100 ? '...' : ''), 'inline' => false];

    $embed = [
        'title' => "❌ Failed Login Attempt ($domain)",
        'description' => "**Username Attempted:** `$username`",
        'color' => 0xff0000,
        'fields' => $fields,
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c')
    ];

    notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Get flag emoji from country code                                  */
/* ------------------------------------------------------------------ */
function notifier_get_flag_emoji($country_code) {
    if (strlen($country_code) !== 2) return '';
    
    $country_code = strtoupper($country_code);
    $first_letter = mb_chr(ord($country_code[0]) - ord('A') + 0x1F1E6);
    $second_letter = mb_chr(ord($country_code[1]) - ord('A') + 0x1F1E6);
    
    return $first_letter . $second_letter;
}

/* ------------------------------------------------------------------ */
/*  Test webhook function                                             */
/* ------------------------------------------------------------------ */
function notifier_test_webhook($webhook)
{
    if (empty($webhook) || !filter_var($webhook, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'Invalid webhook URL'];
    }

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $user = defined('YOURLS_USER') ? YOURLS_USER : 'Administrator';
    
    $embed = [
        'title' => "✅ Test Notification ($display_domain)",
        'description' => "This is a test notification from your YOURLS Notifier plugin.",
        'color' => 0x9b59b6, // Purple
        'fields' => [
            ['name' => 'Status', 'value' => '✓ Webhook is working correctly', 'inline' => false],
            ['name' => 'Tested By', 'value' => $user, 'inline' => true],
            ['name' => 'Test Time', 'value' => (new DateTime())->format('Y-m-d H:i:s'), 'inline' => true],
        ],
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c'),
        'thumbnail' => ['url' => 'https://yourls.org/assets/images/yourls-logo.png'],
    ];

    return notifier_discord($webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  Plugin bootstrap                                                  */
/* ------------------------------------------------------------------ */
yourls_add_action('plugins_loaded', 'notifier_loaded');
function notifier_loaded()
{
    yourls_register_plugin_page('notifier_settings', 'Notifier', 'notifier_register_settings_page');

    // Always register failed login notifications (security feature)
    yourls_add_action('login_failed', 'notifier_login_failed');

    // Register optional event notifications based on settings
    $events = notifier_get_events_subscriptions();
    foreach ($events as $event => $enabled) {
        if ($enabled) {
            yourls_add_action($event, 'notifier_' . $event);
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Event subscription helpers                                        */
/* ------------------------------------------------------------------ */
function notifier_get_events_subscriptions()
{
    $defaults = [
        'post_add_new_link' => true,
        'redirect_shorturl' => false,
        'auth_successful' => false,
        'delete_link' => true,
        'edit_link' => true,
    ];
    $saved = yourls_get_option('notifier_events_subscriptions');
    if (is_array($saved)) {
        foreach ($saved as $event => $status) {
            if (array_key_exists($event, $defaults)) {
                $defaults[$event] = (bool)$status;
            }
        }
    }
    return $defaults;
}

/* ------------------------------------------------------------------ */
/*  Settings page – with webhook testing                              */
/* ------------------------------------------------------------------ */
function notifier_register_settings_page()
{
    $events = notifier_get_events_subscriptions();
    $descriptions = [
        'post_add_new_link' => 'When a new link is shortened',
        'redirect_shorturl' => 'When a short URL is accessed',
        'auth_successful' => 'When a user successfully logs in',
        'delete_link' => 'When a short URL is deleted',
        'edit_link' => 'When a short URL is edited',
    ];

    $test_result = null;

    // Handle webhook test
    if (isset($_POST['test_webhook'])) {
        yourls_verify_nonce('notifier_settings');
        $webhook = trim($_POST['discord_webhook']);
        $test_result = notifier_test_webhook($webhook);
    }

    // Save settings
    if (isset($_POST['discord_webhook']) && !isset($_POST['test_webhook'])) {
        yourls_verify_nonce('notifier_settings');

        $webhook = trim($_POST['discord_webhook']);
        if (!empty($webhook) && !filter_var($webhook, FILTER_VALIDATE_URL)) {
            echo '<div class="error"><p>Invalid Discord webhook URL.</p></div>';
        } else {
            yourls_update_option('notifier_discord_webhook', $webhook);
            
            $display_domain = trim($_POST['display_domain'] ?? '');
            yourls_update_option('notifier_display_domain', $display_domain);

            $cooldown = max(0, (int)($_POST['click_cooldown'] ?? 300));
            yourls_update_option('notifier_click_cooldown', $cooldown);

            $geo_enabled = isset($_POST['geolocation_enabled']);
            yourls_update_option('notifier_geolocation_enabled', $geo_enabled);

            $posted = $_POST['events'] ?? [];
            foreach ($events as $e => $on) {
                $events[$e] = isset($posted[$e]);
            }
            yourls_update_option('notifier_events_subscriptions', $events);
            
            echo '<div class="updated"><p>Settings saved successfully.</p></div>';
        }
    }

    // Display test result
    if ($test_result !== null) {
        if ($test_result['success']) {
            echo '<div class="updated"><p><strong>✅ Test successful!</strong> Check your Discord channel for the test message. (HTTP ' . $test_result['http_code'] . ')</p></div>';
        } else {
            $error_msg = isset($test_result['http_code']) 
                ? "HTTP {$test_result['http_code']}: {$test_result['error']}" 
                : $test_result['error'];
            echo '<div class="error"><p><strong>❌ Test failed!</strong> ' . htmlspecialchars($error_msg) . '</p></div>';
            if (!empty($test_result['response'])) {
                echo '<div class="error"><p><strong>Response:</strong> ' . htmlspecialchars(substr($test_result['response'], 0, 200)) . '</p></div>';
            }
        }
    }

    $webhook = yourls_get_option('notifier_discord_webhook', '');
    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $cooldown = yourls_get_option('notifier_click_cooldown', 300);
    $geo_enabled = yourls_get_option('notifier_geolocation_enabled', true);
    $nonce   = yourls_create_nonce('notifier_settings');

    echo <<<HTML
<style>
    .notifier-test-section {
        background: #f0f0f1;
        border-left: 4px solid #9b59b6;
        padding: 15px;
        margin: 20px 0;
    }
    .notifier-button-group {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 10px;
    }
</style>

<div class="wrap">
    <h2>Notifier Settings</h2>
    <form method="post">
        <input type="hidden" name="nonce" value="$nonce" />
        <table class="form-table">
            <tr>
                <th><label for="discord_webhook">Discord Webhook URL</label></th>
                <td>
                    <input type="url" id="discord_webhook" name="discord_webhook" value="$webhook"
                           placeholder="https://discord.com/api/webhooks/..." size="80" />
                    <p class="description">
                        Get your webhook URL from Discord: Server Settings → Integrations → Webhooks → New Webhook
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="display_domain">Display Domain (in title)</label></th>
                <td>
                    <input type="text" id="display_domain" name="display_domain" value="$display_domain"
                           placeholder="e.g. localhost, myshort.com" size="40" />
                    <p class="description">Shown in titles like: <code>New short URL created (localhost)</code></p>
                </td>
            </tr>
            <tr>
                <th><label for="click_cooldown">Click Notification Cooldown</label></th>
                <td>
                    <input type="number" id="click_cooldown" name="click_cooldown" value="$cooldown" min="0" step="60" />
                    <p class="description">Seconds between notifications for the same URL (prevents spam). Set to 0 to disable rate limiting.</p>
                </td>
            </tr>
            <tr>
                <th><label for="geolocation_enabled">Enable Geolocation for Failed Logins</label></th>
                <td>
HTML;
    
    $geo_checked = $geo_enabled ? 'checked' : '';
    echo <<<HTML
                    <input type="checkbox" id="geolocation_enabled" name="geolocation_enabled" $geo_checked />
                    <p class="description">Show location, ISP, and map link for failed login attempts. Uses ip-api.com (free, 45 requests/minute limit). Data is cached for 24 hours per IP.</p>
                </td>
            </tr>
        </table>

        <div class="notifier-test-section">
            <h3 style="margin-top: 0;">🔍 Test Webhook Connection</h3>
            <p>Click the button below to send a test notification to your Discord channel. This will help verify that your webhook URL is configured correctly.</p>
            <div class="notifier-button-group">
                <button type="submit" name="test_webhook" value="1" class="button">
                    🚀 Send Test Notification
                </button>
                <span style="color: #666; font-size: 12px;">
                    (You must save your webhook URL first if you just changed it)
                </span>
            </div>
        </div>

        <h3>Event Subscriptions</h3>
        <p class="description">Choose which events should trigger Discord notifications:</p>
        <table class="form-table">
HTML;

    foreach ($events as $event => $enabled) {
        $checked = $enabled ? 'checked' : '';
        $desc = $descriptions[$event] ?? $event;
        echo <<<HTML
            <tr>
                <th><label for="$event">$desc</label></th>
                <td><input type="checkbox" id="$event" name="events[$event]" $checked /></td>
            </tr>
HTML;
    }

    echo <<<HTML
        </table>
        <p class="submit"><input type="submit" value="Save Changes" class="button button-primary" /></p>
    </form>
</div>
HTML;
}