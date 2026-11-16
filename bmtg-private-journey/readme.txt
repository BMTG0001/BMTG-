=== BMTG Private Journey ===
Contributors: bmtravelgroup
Tags: travel, journeys, quotations, private, luxury
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A premium travel discovery, multi-service journey building and private quotation platform for BM Travel Group.

== Description ==

BMTG Private Journey is an independent WordPress plugin. It does not replace the existing BMTG Business Manager chatbot and does not alter the public website or Elementor pages.

Clients can:
* Explore a curated, editable travel catalogue.
* Combine hotels, restaurants, private transport, rail, airport assistance and experiences into one journey.
* Save a journey basket and submit one complete request for a personalised quotation.
* Sign in, complete a first-login profile, view enquiries, proposals, confirmed journeys and private documents.

Staff can:
* Manage the catalogue, suppliers, journey requests, versioned quotations, clients, documents and reports.
* Configure brand identity, social links, email recipients, page mapping and roles.

Key principles:
* No payment or automatic booking in Release 1.
* No live availability claim without a real supplier integration.
* Supplier costs and internal margins stay server-side.
* No automatic acknowledgement email to clients.

== Installation ==

1. Upload the `bmtg-private-journey` folder to `/wp-content/plugins/` or upload the ZIP in Plugins → Add New.
2. Activate the plugin. Activation creates the database tables and capabilities.
3. Open Private Journey → Settings and complete Brand & Business Identity, Social & Contact, Email and Access.
4. Open Settings → Page Mapping and either map existing pages or use "Create missing pages now".
5. Add services under Private Journey → Catalogue.
6. Test one journey end-to-end with a staff account and a client account.

== Frequently Asked Questions ==

= Does this modify the existing website or chatbot? =
No. The plugin uses its own tables, options, pages and shortcodes. It does not modify the BMTG Business Manager plugin.

= Does it take payments? =
No. Release 1 is quotation-only.

= Does it show live availability? =
No. Availability is confirmed manually by staff with suppliers unless a future supplier integration is added.

= Where are documents stored? =
Outside the public uploads directory, with authenticated download links.

== Changelog ==

= 1.0.0 =
* Initial release: catalogue, journey basket, enquiry, pricing, versioned quotations, client dashboard, staff administration, branding, page mapping, protected documents, audit logging.