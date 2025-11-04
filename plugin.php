<?php
/*
Plugin Name: Yourls Discord Notify
Plugin URI: 
Description: Sends a notification to Discord when a short is created
Version: 1.4
Author: 
Author URI: 
*/

// No direct call
if (!defined('YOURLS_ABSPATH')) {
    die();
}

/* ------------------------------------------------------------------ */
/*  Discord sender – accepts a full embed array                         */
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
    $response = curl_exec($ch);
    curl_close($ch);

    if (!empty($response)) {
        trigger_error('Notifier failed to notify Discord!');
    }
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
/*  Short-URL accessed (click) – with IP and emojis                   */
/* ------------------------------------------------------------------ */
function notifier_redirect_shorturl($args)
{
    $discord_webhook = yourls_get_option('notifier_discord_webhook');
    if (empty($discord_webhook)) {
        return;
    }

    $keyword   = $args[1];
    $long_url  = $args[0];
    $short_url = YOURLS_SITE . '/' . $keyword;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    try {
        $table  = YOURLS_DB_TABLE_URL;
        $clicks = yourls_get_db()->fetchValue(
            "SELECT `clicks` FROM `$table` WHERE `keyword` = :keyword",
            ['keyword' => $keyword]
        );
        $clicks = (int)$clicks + 1;
    } catch (Exception $e) {
        $clicks = 'Unknown';
    }

    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $title = "🚀 Short URL accessed ($display_domain)";

    $embed = [
        'title'       => $title,
        'description' => "**Redirect:** $short_url → [$long_url]",
        'color'       => 0x0099ff, // Blue
        'fields'      => [
            ['name' => '🩳 🔗 URL', 'value' => "[$short_url]", 'inline' => true],
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
/*  Settings page – with Display Domain field                         */
/* ------------------------------------------------------------------ */
function notifier_register_settings_page()
{
    $events = notifier_get_events_subscriptions();
    $descriptions = [
        'post_add_new_link' => 'When a new link is shortened',
        'redirect_shorturl' => 'When a short URL is accessed',
    ];

    // Save settings
    if (isset($_POST['discord_webhook'])) {
        yourls_verify_nonce('notifier_settings');

        $webhook = trim($_POST['discord_webhook']);
        if (!filter_var($webhook, FILTER_VALIDATE_URL)) {
            echo '<div class="error"><p>Invalid Discord webhook URL.</p></div>';
        } else {
            yourls_update_option('notifier_discord_webhook', $webhook);
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $display_domain = trim($_POST['display_domain'] ?? '');
        yourls_update_option('notifier_display_domain', $display_domain);

        $posted = $_POST['events'] ?? [];
        foreach ($events as $e => $on) {
            $events[$e] = isset($posted[$e]);
        }
        yourls_update_option('notifier_events_subscriptions', $events);
    }

    $webhook = yourls_get_option('notifier_discord_webhook', '');
    $display_domain = yourls_get_option('notifier_display_domain', 'YOURLS');
    $nonce   = yourls_create_nonce('notifier_settings');

    echo <<<HTML
<div class="wrap">
    <h2>Notifier Settings</h2>
    <form method="post">
        <input type="hidden" name="nonce" value="$nonce" />
        <table class="form-table">
            <tr>
                <th><label for="discord_webhook">Discord Webhook URL</label></th>
                <td><input type="url" id="discord_webhook" name="discord_webhook" value="$webhook"
                           placeholder="https://discord.com/api/webhooks/..." size="80" /></td>
            </tr>
            <tr>
                <th><label for="display_domain">Display Domain (in title)</label></th>
                <td>
                    <input type="text" id="display_domain" name="display_domain" value="$display_domain"
                           placeholder="e.g. localhost, myshort.com" size="40" />
                    <p class="description">Shown in titles like: <code>New short URL created (localhost)</code></p>
                </td>
            </tr>
        </table>

        <h3>Event Subscriptions</h3>
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