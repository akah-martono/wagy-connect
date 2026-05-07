=== Wagy Connect ===
Contributors: akah
Tags: security, 2fa, two factor authentication, notifications, whatsapp
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress security and messaging suite: WhatsApp 2FA, security alerts, custom login URL, message logs, bulk broadcast, and Fluent Forms integration.

== Description ==

**Wagy** connects your WordPress site to a self-hosted WhatsApp Gateway (WAGY API), enabling real-time security alerts, two-factor authentication, and bulk messaging — all delivered directly to WhatsApp.

= Core Features =

* **WhatsApp 2FA** — Sends a one-time password (OTP) via WhatsApp (or email fallback) to users on every login.
* **Security Notifications** — Sends instant WhatsApp alerts to the admin for new logins, password changes, new user registrations, and brute-force attempts.
* **System Update Alerts** — Notifies the admin via WhatsApp when new WordPress core, plugin, or theme updates are available or have been installed.
* **Custom Login URL** — Hides the default `wp-login.php` endpoint and replaces it with a custom slug to reduce automated attacks.
* **Admin Block** — Blocks unauthenticated direct access to `/wp-admin/` with a configurable error message.
* **Message Log** — View and filter all WhatsApp messages sent from the dashboard, with status, recipient, and timestamp details.
* **Access Control** — Granular permission system (Standard or Strict mode) to control which roles or specific users can view and manage Wagy pages.

= Status & Quota Dashboard =

* **Visual Quota Dashboard** — Color-coded progress bars for FREE and PRO quota tiers. Shows percentage remaining, countdown to expiry (PRO), and auto-reset date (FREE).
* **Auto-reconnect Prompt** — Context-aware banners on the Status page: yellow warning with inline QR if the device was previously paired but is now logged out; blue guide for first-time pairing.
* **Admin Notices** — Automatic warning banners on all Wagy admin pages when the API is unreachable or the WhatsApp device is disconnected, with a direct "Reconnect / View QR" button.

= Settings =

* **Owner Info Tab** — Manage the email and WhatsApp number of the device owner directly from the Settings page. Data is saved to the WAGY API server (used for inactivity and logout notifications from the server-side Janitor service).
* **Settings Cache Invalidation** — Saving API credentials automatically clears the cached connection status so admin notices always reflect the latest configuration.

= Broadcast =

* **Bulk WhatsApp Broadcast** — Send a single WhatsApp message (with optional media) to multiple recipients at once.
* **WordPress User Import** — Import phone numbers directly from WordPress users by role, using any configurable user meta key (e.g. `wagy_2fa_whatsapp`, `billing_phone`).
* **AJAX Batch Sending** — Messages are sent in batches of 25 via AJAX to avoid PHP timeouts, with a live results table showing queued/failed status per number.
* **Expiry & Retry Control** — Configure message expiry duration (1h–7d) and retry interval per broadcast.

= Developer Integration =

* **Action Hook** — Third-party plugins and themes can send WhatsApp messages without touching the Wagy API directly:
  `do_action( 'wagy_send_message', $phone, $message, $media_url, $args )`
* **Payload Filter** — Modify the message payload before it is sent:
  `apply_filters( 'wagy_message_payload', $payload )`

= Fluent Forms Integration =

* Send WhatsApp messages triggered by Fluent Forms submissions using configurable field mappings.

= Requirements =

This plugin requires a running instance of the **WAGY API** (a self-hosted WhatsApp Gateway server). Configure the Base URL, Device ID, and Client Token in **Wagy > Settings** before features become active.

== Installation ==

1. Upload the `wagy-connect` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Wagy > Settings** and configure your WAGY API Base URL, Device ID, and Client Token.
4. Scan the QR code on the **Wagy > Status** page to connect your WhatsApp account.
5. Optionally, configure the device owner info in the **Owner Info** tab of Settings.

== Frequently Asked Questions ==

= Does this plugin work without the WAGY API server? =

No. This plugin is a WordPress client for the WAGY self-hosted API. You need a running WAGY server instance to use WhatsApp-related features. Security features like the Custom Login URL and Admin Block work independently.

= Is the Client Token stored securely? =

Yes. The Client Token is encrypted using AES-256-CBC with your site's unique WordPress secret keys before being stored in the database.

= What happens if WhatsApp is disconnected? =

An admin notice will appear on all Wagy pages with a direct link to the Status page where you can scan the QR code to reconnect. Security notifications will fall back to email if configured.

= How does the Broadcast import work? =

The import reads phone numbers from a WordPress user meta key. The default key is `wagy_2fa_whatsapp` (used by the Wagy 2FA feature), but you can specify any meta key — for example `billing_phone` for WooCommerce customers.

= How can I send a WhatsApp message from my theme or plugin? =

Use the action hook:

`do_action( 'wagy_send_message', '628123456789', 'Your message here' );`

You can also pass a media URL as the third argument and an array of additional API arguments as the fourth.

= What is the Owner Info tab for? =

The Owner Info stores your email and WhatsApp number on the WAGY server. The WAGY server uses this information to send you inactivity warnings (at 50 and 55 days of no activity) and logout notifications directly — independently of WordPress.

== Screenshots ==

1. Status & Quota page — connection status, color-coded quota progress bars, and auto-reconnect QR prompt.
2. Settings page — API credentials, Security tab, and Owner Info tab.
3. Messages Log — filterable, paginated log of all sent WhatsApp messages.
4. Broadcast page — compose and send bulk WhatsApp messages with WordPress user import.

== Changelog ==

= 0.0.2 =
* Add Indonesian language.

= 0.0.1 =
* Initial release.

== Upgrade Notice ==

= 0.0.2 =
Initial release. No upgrade steps required.
