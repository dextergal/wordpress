=== SWIS Performance ===
Contributors: nosilver4u
Requires at least: 5.7
Tested up to: 6.0
Requires PHP: 7.2
Stable tag: 1.7.0
License: GPLv3

It makes your site faster, and bakes you a cake too! Alright, maybe no cakes...

== Description ==

SWIS is a collection of tools for improving the speed of your site. It includes the following:
* Page Caching to reduce the overhead of PHP and database queries. AKA: super speed boost.
* Defer JS to prevent render-blocking scripts.
* Load CSS asynchronously to prevent render-blocking CSS.
* Inline critical CSS to prevent a Flash of Unstyled Content (FOUC) when using async CSS.
* Minify JS/CSS to trim out extra white-space.
* Compress all assets and set proper expiration headers for awesome browser caching.
* Deliver all static resources from a CDN. CDN sold separately, perhaps you'd like https://ewww.io/easy/
* Disable unused JS/CSS resources. Fully customizable, load/unload files site-wide, or on specific pages.
* DNS pre-fetch and pre-connect hints so browsers can load third-party assets quicker.
* Optimize Google Fonts.

== Changelog ==

= 1.7.0 =
* added: manual Clear Site Cache action purges server-based caches: WP object cache, WP Engine, SiteGround, Pagely, LiteSpeed, and SpinupWP
* changed: add data-no-defer to critical css control JS
* changed: critical CSS for individual pages can be removed by clearing existing CSS and saving
* changed: Easy Digital Downloads cookies added to default cache exclusions
* fixed: stdClass "not found" in EDD updater file in edge cases
* fixed: HTML parsers break Bricks front-end editor

= 1.6.1 =
* updated: plugin updater class with ability for auto-updates
* changed: page cache can be enabled on any host in conjunction with server-based page caching
* fixed: get_plugin_data function undefined in some cases
* fixed: .htaccess admin notice shown on servers where it does not exist (and is not needed)
* fixed: cache engine debug function throws error in some edge cases
* fixed: PHP empty needle error in caching function

= 1.6.0 =
* added: CriticalCSS.com integration to generate critical CSS automatically and avoid FOUC with deferred CSS
* added: cache size and clear cache button on settings page
* changed: cache preload status auto-refreshes when running in background
* changed: cache overrides take effect without toggling cache setting
* fixed: page cache upgrade routine installs advanced-cache.php incorrectly
* fixed: undefined constant notice during page cache upgrade
* fixed: undefined variable when getting cache size
* fixed: cache exclusions not saving properly
* fixed: front-end checks not detecting feeds, embeds, and previews

= 1.5.4 =
* added: configure content directory with SWIS_CONTENT_DIR
* changed: make all permissions checks filterable
* fixed: removal of dashicons CSS breaks Ninja Forms
* fixed: unaltered CSS was pre-loaded twice
* fixed: fatal error for invalid class when mobile caching enabled
* fixed: JS errors when WP admin bar is hidden
* fixed: spaces in Slim rules prevent URL matches
* fixed: conflicts with Thrive Editor

= 1.5.3 =
* fixed: newer versions of Avada/Fusion builder not detected properly
* fixed: some functions of Customizer not working with deferred JS

= 1.5.2 =
* fixed: CSS defer double-parses the fallback noscript tags
* fixed: cache preload triggered by cache clear during plugin deactivation
* fixed: HTML parsing code incorrectly handles JSON markup

= 1.5.1 =
* added: preload CSS for Avada, Brizy Builder, Gutenberg plugin, and TagDiv Composer
* fixed: JS Minify breaks when processing Brizy Builder JS
* fixed: JS Minify breaks Kali Forms
* fixed: empty wp-content folder (/) causes rewriting of page links
* fixed: CDN rewriter throws empty needle warning for strpos
* fixed: CDN rewriter and WebP cache variant incorrectly handle JSON responses
* fixed: jQuery not deferred due to updated markup in core WP
* fixed: unable to remove last rule from Eliminate Unused JS/CSS

= 1.5.0 =
* added: preload CSS for Gutenberg, Oxygen, Elementor, GenerateBlocks and Beaver Builder when Optimize CSS Loading is enabled
* added: WebP cache variant supports relative image URLs
* fixed: WebP cache variant setting not saved to disk settings file
* fixed: WebP cache variant removes background images if .webp file does not exist
* fixed: Optimize CSS loading adds extra markup for preloaded theme files

See separate changelog.txt for full history.
