# yourls-discord-notifier

# YOURLS Discord Notify

Send real-time Discord notifications for YOURLS events with geolocation tracking, summaries, and comprehensive monitoring.

![Version](https://img.shields.io/badge/version-1.6-blue)
![YOURLS](https://img.shields.io/badge/YOURLS-1.7%2B-orange)
![License](https://img.shields.io/badge/license-MIT-green)

## ✨ Features

### 📢 Event Notifications
- **New Link Created** - Get notified when short URLs are created
- **Link Accessed** - Track when short URLs are clicked (with rate limiting)
- **Link Edited** - Monitor changes to existing short URLs
- **Link Deleted** - Track when short URLs are removed
- **Failed Login Attempts** - Security alerts for invalid login attempts
- **Successful Logins** - Audit trail of admin logins
- **Plugin Changes** - Know when plugins are activated or deactivated

### 🌍 Geolocation Tracking
- **IP-based location** - City, region, and country with flag emojis
- **ISP information** - See which internet provider was used
- **Interactive maps** - Clickable Google Maps links for exact locations
- **Smart caching** - 24-hour cache per IP to avoid rate limits
- **Privacy-safe** - Automatically skips private/localhost IPs

### 📊 Automated Summaries
- **Daily Summary** - Morning digest of yesterday's activity
- **Weekly Summary** - Monday overview of the previous week
- **Top performers** - See which links get the most clicks
- **Trend tracking** - Monitor growth over time

### 🔧 Smart Features
- **Rate Limiting** - Prevent notification spam for popular links
- **Webhook Testing** - Built-in test button to verify Discord connection
- **Configurable Events** - Enable/disable notifications per event type
- **Custom Domain Display** - Show your branded domain in notifications
- **Error Handling** - Graceful failures with detailed logging

## 📦 Installation

1. **Download the plugin**
   ```bash
   cd /path/to/yourls/user/plugins
   git clone https://github.com/yourusername/yourls-discord-notify.git discord-notify
   ```

2. **Create a Discord Webhook**
   - Open your Discord server
   - Go to Server Settings → Integrations → Webhooks
   - Click "New Webhook"
   - Give it a name (e.g., "YOURLS Notifier")
   - Select the channel for notifications
   - Copy the Webhook URL

3. **Activate the plugin**
   - Go to your YOURLS admin panel
   - Navigate to Plugins
   - Find "Yourls Discord Notify" and click Activate

4. **Configure settings**
   - Go to Plugins → Notifier
   - Paste your Discord Webhook URL
   - Configure your preferences
   - Click "Save Changes"

## ⚙️ Configuration

### Basic Settings

| Setting | Description | Default |
|---------|-------------|---------|
| **Discord Webhook URL** | Your Discord webhook URL (required) | - |
| **Display Domain** | Domain shown in notification titles | YOURLS |
| **Click Cooldown** | Seconds between click notifications per URL | 300 (5 min) |

### Event Subscriptions

Enable/disable notifications for specific events:

- ✅ **New Link Created** - Enabled by default
- ⬜ **Link Accessed** - Disabled by default (can be noisy)
- ⬜ **Successful Login** - Disabled by default
- ✅ **Link Deleted** - Enabled by default
- ✅ **Link Edited** - Enabled by default
- ⬜ **Plugin Activated** - Disabled by default
- ⬜ **Plugin Deactivated** - Disabled by default

### Geolocation

- **Enable Geolocation** - Add location data to failed logins and clicks
- Uses [ip-api.com](https://ip-api.com) (free, 45 requests/minute)
- Automatically caches results for 24 hours per IP

### Summary Reports

- **Daily Summary** - Sends first admin page load after midnight
- **Weekly Summary** - Sends first admin page load on Monday

## 🎨 Notification Examples

### New Link Created
```
🩳 New URL created (yoursite.com)

Short URL: yoursite.com/abc123
Long URL: https://example.com

🔑 Keyword: abc123
🌐 IP Address: 192.0.2.1
```

### Failed Login with Geolocation
```
❌ Failed Login Attempt (yoursite.com)

Username Attempted: admin

🌐 IP Address: 192.0.2.1
📍 Location: 🇺🇸 Las Vegas, Nevada, United States
🏢 ISP: Example ISP
🗺️ Map: [View on Map]
💻 User Agent: Mozilla/5.0...
```

### Daily Summary
```
📅 Daily Summary (yoursite.com)
Yesterday's Activity Summary
November 4, 2025

🔗 Total Links: 1,234
📊 Total Clicks: 45,678
➕ New Links: 12
👆 Clicks Yesterday: 456

🏆 Top Links Yesterday
• docker - 125 clicks
• test - 89 clicks
• github - 67 clicks
```

## 🔐 Security Features

### Failed Login Monitoring
- Tracks all failed login attempts
- Shows username attempted (but **never** logs passwords)
- Includes geolocation and ISP information
- Helps detect brute force attacks

### Plugin Change Tracking
- Know when plugins are activated or deactivated
- Track who made the change and from where
- Audit trail for system modifications

### Geolocation Tracking
- Identify suspicious login locations
- Track where your links are being accessed from
- Detect geographic anomalies

## 🛠️ Advanced Usage

### Rate Limiting for Clicks

To prevent notification spam for popular links:

1. Set the "Click Notification Cooldown" in settings
2. Default is 300 seconds (5 minutes)
3. Set to 0 to disable rate limiting
4. Each link has its own cooldown timer

### Testing Your Webhook

Use the built-in test button:

1. Go to Plugins → Notifier
2. Scroll to "Test Webhook Connection"
3. Click "🚀 Send Test Notification"
4. Check your Discord channel for the test message

### Customizing the Display Domain

Show your branded domain instead of "YOURLS":

1. Go to Plugins → Notifier
2. Enter your domain in "Display Domain"
3. Example: `short.ly` or `yourdomain.com`
4. This appears in all notification titles

## 📋 Requirements

- **YOURLS** 1.7 or higher
- **PHP** 7.0 or higher
- **cURL** PHP extension enabled
- **Discord** webhook URL

## 🐛 Troubleshooting

### Notifications Not Sending

1. **Check webhook URL** - Make sure it's correct and starts with `https://discord.com/api/webhooks/`
2. **Test the webhook** - Use the built-in test button
3. **Check PHP logs** - Look for cURL errors
4. **Verify cURL** - Ensure PHP cURL extension is installed

### Rate Limiting Issues

If you're hitting Discord rate limits:
- Increase the click notification cooldown
- Disable click notifications for high-traffic sites
- Use daily/weekly summaries instead of real-time click tracking

### Geolocation Not Working

- Check that you're not using a private IP (127.0.0.1, 192.168.x.x)
- Verify [ip-api.com](https://ip-api.com) is accessible from your server
- Check the 24-hour cache - location data updates daily

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📝 Changelog

### Version 1.6
- Added plugin activation/deactivation notifications (simplified)
- Added daily and weekly summary reports
- Added geolocation tracking for clicks and logins
- Added rate limiting for click notifications
- Added webhook test button
- Improved error handling
- Fixed security issue with password logging

### Version 1.5
- Added geolocation support
- Added failed login notifications
- Added link deleted/edited notifications
- Improved notification formatting

### Version 1.4
- Initial public release
- Basic Discord notifications for link creation and clicks

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🙏 Credits

- Built for [YOURLS](https://yourls.org/)
- Geolocation by [ip-api.com](https://ip-api.com)
- Discord webhook integration

## 💬 Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/yourls-discord-notify/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/yourls-discord-notify/discussions)
- **YOURLS Community**: [YOURLS Forums](https://discourse.yourls.org/)

---

Made with ❤️ for the YOURLS community