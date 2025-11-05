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
        'description' => "**Long URL:** $long_url\n**Short URL:** $short_url",
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
/*  Short-URL accessed (click) – with rate limiting                   */
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

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $title = "🚀 Short URL accessed ($display_domain)";

    $embed = [
        'title'       => $title,
        'description' => "**Redirect:** $short_url → $long_url",
        'color'       => 0x0099ff, // Blue
        'fields'      => [
            ['name' => '🩳 🔗 URL', 'value' => $short_url, 'inline' => true],
            ['name' => 'Short Keyword',  'value' => "`$keyword`", 'inline' => true],
            ['name' => 'Total Clicks',   'value' => (string)$clicks, 'inline' => true],
            ['name' => 'IP Address',     'value' => $ip, 'inline' => true],
        ],
        'footer'      => ['text' => 'YOURLS Notifier'],
        'timestamp'   => (new DateTime())->format('c'),
    ];

    notifier_discord($discord_webhook, $embed);
}

/* ------------------------------------------------------------------ */
/*  FAILED LOGIN – NO PASSWORD LOGGING (SECURITY FIX)                 */
/* ------------------------------------------------------------------ */
yourls_add_action('login_failed', 'notifier_login_failed');
function notifier_login_failed() {
    $webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($webhook)) return;

    $username = $_POST['username'] ?? 'Unknown';
    // SECURITY: Never log passwords, even masked
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $domain = yourls_get_option('notifier_display_domain', 'YOURLS');

    $embed = [
        'title' => "❌ Failed Login Attempt ($domain)",
        'description' => "**Username Attempted:** `$username`",
        'color' => 0xff0000,
        'fields' => [
            ['name' => 'IP Address', 'value' => $ip, 'inline' => true],
            ['name' => 'User Agent', 'value' => substr($user_agent, 0, 100) . (strlen($user_agent) > 100 ? '...' : ''), 'inline' => false]
        ],
        'footer' => ['text' => 'YOURLS Notifier'],
        'timestamp' => (new DateTime())->format('c')
    ];

    notifier_discord($webhook, $embed);
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
        echo <<<HTML
            <tr>
                <th><label for="$event">{$descriptions[$event]}</label></th>
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