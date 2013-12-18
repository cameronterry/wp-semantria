=== WP Semantria ===
Contributors: cameronterry
Donate link: https://github.com/cameronterry/wp-semantria
Tags: semantria, taxonomy, terms, posts, tag, tagging, tags
Requires at least: 3.5.1
Tested up to: 3.8
Stable tag: 0.2.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WordPress plugin for integrating with Semantria to generate custom taxonomies.

Author's word, "I always strive to make the best than I can but live resolutely in the knowledge than I have much more to learn from you than you from I." 

== Description ==

Simple plugin that allows you to connect to your [Semantria](http://semantria.com/) API account to WordPress to generate tags and custom taxonomies for your Posts.

== Installation ==
1. Upload the files to the '/wp-content/plugins/wp-semantria/' directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings -> Semantria Settings and enter your Semantria Consumer Key and Secret and Save.
4. Click "Perform Data Ingestion" and then make a cup of tea :-)

== Changelog ==

= 0.2.4 =
* Added a database upgrade mechanism in case of future database changes.
* Now ensure the creation of the Queue table is in UTF-8.  (Please note; the database check above will NOT alter the collation of the pre-existing table in case of compatibility issues.)
* Checked against the final WordPress 3.8 release.

= 0.2.3 =
* [Bug Fix] Schoolboy error resolved which was causing the Data Ingestion to only pull through half at a time.

= 0.2.2 =
* [Bug Fix] Version numbers no longer having an identity crisis!
* [Bug Fix] Queues page - clicking "Process" on an Expired Queued document no longer does nothing.

= 0.2.1 =
* Stopped using Cloudflare CDN and included Handlebars.js locally.

= 0.2.0 =
* Brand new Queues page which shows table of documents being processed.
* New "Evaluate" screen where you can hand pick the Semantria terms for use.
* Choose a "Mode" - Automatic (let Semantria work quietly in the background unaided) and Manual (take full control and handpick the terms you want).
* [Improvement] Data ingestion can now be performed initially and later (for example, if the plugin is deactivated for a period of time.)
* [Improvement] Loading icon added to indicate something is happening on data ingestion.
* [Bug Fix] AJAX calls now check for Nonces.
* [Bug Fix] No longer spits out IDs instead of the actual term name.
* [Bug Fix] Settings page now displays a confirmation message when saved.
* Various performance improvements.
* Forker's rejoice - comments and refactoring changes so that you can better figure out what's going on!

= 0.1.0 =
* Initial release.