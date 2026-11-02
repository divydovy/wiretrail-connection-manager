=== Connections Manager ===
Contributors: divydovy
Tags: privacy, monitoring, http, integrations, gdpr
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which plugins and themes talk to which third-party services, how healthy those connections are, and whether personal data is being sent.

== Description ==

Every plugin and theme on your site can quietly call out to third-party services: email marketing, shipping, analytics, licensing servers, and more. Connections Manager shows you what is actually happening.

* **Detected connections**: every outbound service your site talks to, grouped by the plugin or theme that made the request.
* **Health**: request counts, error rates, and average latency per connection, with an alert when a service keeps failing.
* **Personal data checks**: flags requests that contain email addresses, phone numbers, IP addresses, card numbers, or similar data, and alerts you when that data goes to a service that is not recognised or not expected to receive it.
* **Settings > Connectors**: on WordPress 7.0 and later, detected connections also appear on the Connectors screen, marked as "Detected" so they are not confused with connections you set up yourself.
* **AI agent access**: on WordPress 6.9 and later, issues, connections, and recent requests are available as read-only Abilities, so an AI assistant connected to your site can tell you what needs attention.

Connections Manager only observes. It never blocks, changes, delays, or re-sends a request.

= Privacy =

Connections Manager does not send any data off your site and does not contact any external service.

For each outbound request it stores the destination host, the URL (with secrets and personal data redacted), the HTTP method, response status and size, timing, and which plugin or theme made the request. Request bodies are scanned in memory for personal data, but only the type of data found is stored. Request and response bodies are never stored.

Records are kept in your site's database and deleted automatically after the retention period you choose (7 days by default). You can delete all records at any time from Tools > Connections > Settings. Deleting the plugin removes its tables and options.

Requests to WordPress.org, to your own site, and to the Stripe, PayPal, and Braintree payment gateways are not recorded.

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools > Connections**.
3. Connections appear as your plugins and themes make requests. Some only make requests on a schedule or when you use a feature, so check back after a while.

== Frequently Asked Questions ==

= Does this slow my site down? =

Recording a request takes one small database write after the request has finished. The request itself is not delayed or altered.

= Does it see browser-side scripts and pixels? =

Not in this version. It records server-side requests made through the WordPress HTTP API, which is how almost all plugins and themes talk to external services.

= Why is a connection attributed to "wp-core"? =

The request was made by WordPress itself, or by code that runs outside a plugin or theme directory (for example a must-use plugin).

= Can I stop a host from being recorded? =

Yes. Add it under Tools > Connections > Settings > Ignored hosts. Developers can also use the `cmgr_ignored_domains` filter.

= Can I teach it about more services? =

Use the `cmgr_known_services` filter to add hosts, display names, and whether each service is expected to receive personal data.

= Can my AI assistant ask about connection problems? =

Yes, on WordPress 6.9 and later. The plugin registers three read-only abilities through the WordPress Abilities API: `connections-manager/get-issues` (alerts, failing connections, and slow connections), `connections-manager/list-connections`, and `connections-manager/get-recent-requests`. They are available over the REST API at `/wp-json/wp-abilities/v1/` and to MCP clients through the MCP Adapter. Only users who can manage options can run them, and they return the same redacted data shown on the Connections screen.

== Screenshots ==

1. Detected connections: every outbound service grouped by the plugin that called it, with health, latency, personal data checks, and alerts that need attention.
2. Settings: turn recording on or off, choose how long records are kept, and ignore hosts.
3. On WordPress 7.0 and later, detected connections also appear on Settings > Connectors.

== Changelog ==

= 0.2.1 =
* Connections averaging 2 seconds or more are marked "slow" in the Health column.
* Fix: requests to wordpress.org itself (the dashboard news feed) are no longer recorded.

= 0.2.0 =
* New: read-only Abilities (`get-issues`, `list-connections`, `get-recent-requests`) so AI agents can report connection problems. WordPress 6.9+.
* Fix: WordPress.com, Jetpack Stats, Jetpack Protect, and Akismet are recognised services, so Jetpack's own requests no longer raise "unrecognised host" alerts.

= 0.1.0 =
* First release: detected connections, health, personal data checks, and the Connectors screen integration.
