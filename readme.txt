=== BIT GF Submitter ===
Contributors: JD
Donate link: http://www.blackicetrading.com
Tags: woocommerce, orders, supplier, api, submission
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Submit each order in GF Ready to Export queue to GF via API.

== Description ==

For each order that's ready to export submit it to GF via their API having checked that the relative artwork is available.

Add Order Statues for Submission Failiures:
GF (Missing Artwork)
GF (Submission Failed)

For use with BlackIce systems and automation. Untested/unsupported for other system.

== Installation ==

1. Upload `bit-gf-submitter` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 0.4 =
* Reduce Scheduled Task time from 5 mins interval to 2 mins.
* Increase orders submit per interval from 5 to 10.
* Commented out the Test API field for live system.
* Remove TEST info from API Notes submission.
* Added print file URL to Log on INVALID Print File.

= 0.3 =
* Initial testing all working. with GF systems. Further testing from live system before removal of testing api flag.

= 0.2 =
* All programming and testing upto running.
* Adds 2 new queues for missing artwork and API error.
* Scheduled Action logic for checking GF (Ready to Export) queue every 5 mins.
* Added Bulk action Submit to GF item and link into target of scheduled action. update scheduled action args to pass same data as bulk action.
* Added GF Submitter options page and options.
* Added plugin variables to pull from set options.
* Printfile logic to check Printfile URL is valid. Added checks for cache of Printfileurl check.
* SKU convert lookup and logic.
* API submission build and checks.
* Inital plugin testing now working. progressing to v0.3

= 0.1 =
* Initial setup of plugin directory structure.
* Create GIT repository
* Add queue check to Deactivation warning.
