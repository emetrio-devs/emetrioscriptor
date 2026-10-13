=== Emetrio Scriptor ===
Contributors: nikunjvyas, emetriodevs
Donate link: https://www.emetrio.com/
Tags: posts, auto-post, rest api, automation, blog
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate WordPress blog publishing with a REST API, AI content generation, scheduled publishing, Brand DNA, featured images, and API logs.

== Description ==

**Emetrio Scriptor** is a lightweight WordPress plugin for automated blog publishing. It lets external systems publish posts through a dedicated REST API and can also generate and publish blog content automatically using connected AI providers.

Use it for automated publishing workflows, content pipelines, AI-powered blog generation, and scheduled WordPress publishing.

### Key Features:

* **REST API Publishing:** Publish WordPress posts programmatically through `/wp-json/blog-api/v1/publish`.
* **WordPress Authentication:** Uses standard WordPress authentication with Application Passwords for secure API access.
* **AI Content Generation:** Generate blog titles, content, excerpts, categories, tags, and featured-image descriptions using connected AI providers.
* **AI Provider Selection:** Configure the preferred AI provider for automated content generation.
* **Scheduled Publishing:** Automatically generate and publish content according to configured days, times, and frequency.
* **Brand DNA:** Configure language, business type, brand voice and tone, target audience, service area, and topics to avoid for AI-generated content.
* **Featured Images:** Provide an image URL and image upload for automatic download and attachment.
* **Quick Tester:** Test AI content generation and post creation directly from the WordPress admin dashboard.
* **API Logs:** View recent API requests, publication results, and errors from the plugin dashboard.
* **Rate Limiting:** Protect the REST API with configurable request rate limits.
* **Custom CSS:** Add custom CSS to individual posts created through Emetrio Scriptor.
* **Duplicate Protection:** Configure how duplicate post requests are handled.

== Installation ==

1. Install the plugin from the WordPress Plugins directory, or upload the `emetrio-scriptor` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' screen in WordPress Admin.
3. Go to 'Emetrio Scriptor' in the WordPress admin dashboard.
4. Configure the API, publishing, AI, Brand DNA, and scheduling settings as required.
5. If using automated AI generation, ensure your connected AI provider is active and authorized under Settings → Connector Approvals.
6. For REST API authentication, create a WordPress Application Password for the user account that will make API requests.

== Frequently Asked Questions ==

= How do I authenticate API requests? =

Use standard WordPress authentication with an Application Password assigned to a WordPress user with the required capabilities.

= What is the REST API endpoint? =

The publishing endpoint is:

`/wp-json/blog-api/v1/publish`

Send a POST request with the required JSON payload and authenticate using your WordPress username and Application Password.

= Can I test the API before using my application? =

Yes. The plugin includes an API Reference section with an example request payload that can be used with tools such as Postman or other API clients.

= Can Emetrio Scriptor generate posts automatically? =

Yes. Enable scheduled publishing and configure the required publishing frequency, day, time, AI provider, and content settings.

= Can I test AI generation without waiting for the schedule? =

Yes. Use the Quick Tester in the Emetrio Scriptor dashboard to test content generation and post creation manually.

= Can I use my own brand information for AI-generated content? =

Yes. Brand DNA settings allow you to provide your business type, language, brand voice and tone, target audience, service area, and topics to avoid.

= Does Emetrio Scriptor support featured images? =

Yes. You can provide an image URL, or upload an image from the Media Library, while testing in the Quick Tester.

= Where can I see API requests and errors? =

The API Logs tab in the Emetrio Scriptor dashboard displays recent requests, publication results, and errors.

= Does Emetrio Scriptor automatically publish posts? =

Yes, it publishes posts when scheduled publishing is enabled and the configured schedule conditions are met. Otherwise, posts can be created through the REST API or Quick Tester according to the selected settings.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added REST API publishing.
* Added scheduled AI content generation.
* Added AI provider configuration.
* Added Brand DNA settings.
* Added Quick Tester.
* Added API request logging.
* Added configurable rate limiting.
* Added custom CSS support.
* Added duplicate-post handling.

== Upgrade Notice ==

= 1.0.0 =
Initial release.