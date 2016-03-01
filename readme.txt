=== wp-url2snapshot ===
Contributors: cadeyrn
Donate link: https://paypal.me/petermolnar/3
Tags: linkrot, archive, hyperlink, url
Requires at least: 3.0
Tested up to: 4.4.1
Stable tag: 0.2.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Required minimum PHP version: 5.3

Automatically pull linked websites to a snapshot (HMTL only)

== Description ==

To prevent the frustration of linkrot the plugin pulls in the curlable HTML content of every URL present in a post. This includes every published post. This is stored in a separate MySQL table to prevent potential slowdowns and collisions.

Already dead links will be shown as errors in the edit post page in admin.

The actual work is done with WordPress Cron; due to the nature of this job it's highly recommended to set Cron to run from real system cron.

As this is purely for historical, archival reasons, there currently no way of presenting this content. (yet)

== Installation ==

1. Upload contents of `wp-url2snapshot` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress

== Frequently Asked Questions ==

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.2.1 =
* 2016-03-01*

* better logging, added filter for urls

= 0.2 =
*2016-01-07*

* better logic
* replaced db, adding response headers, cookies and additional stuff

= 0.1 =
*2015-12-11*

* initial public release
