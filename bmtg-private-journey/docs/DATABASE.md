# Database Schema

All tables use `$wpdb->prefix . 'bmtg_pj_*'`. All timestamps are UTC `datetime`
(`Y-m-d H:i:s`). Monetary values are `decimal(12,2)`. JSON fields are `longtext`.

## `bmtg_pj_clients`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK AI | |
| wp_user_id | bigint unsigned NULL | Linked WP user |
| email | varchar(191) | |
| email_normalized | varchar(191) UNIQUE | Lowercase |
| first_name / last_name | varchar(191) | |
| phone | varchar(40) | |
| phone_verified | tinyint(1) | 0/1 |
| preferences | longtext | JSON |
| privacy_consent_at | datetime NULL | |
| status | varchar(20) | active |
| created_at / updated_at | datetime | |

## `bmtg_pj_client_preferences`

id, client_id (indexed with pref_key), pref_key, pref_value (JSON), source, created_at, updated_at.

## `bmtg_pj_catalogue_categories`

id, name, slug (UNIQUE), description, sort_order, status, created_at, updated_at.
Seeded with the 12 collection categories on first migration.

## `bmtg_pj_catalogue_items`

id, slug (UNIQUE), item_type, title, summary, description, country, destination, location,
category_slug, tags, facilities, highlights, inclusions, exclusions, conditions, duration,
price_type (`fixed`|`poa`), price, currency, cover_image_id, gallery, status
(`draft`|`published`|`archived`), featured, public_visibility, search_meta, sort_order, meta,
created_at, updated_at.

Indexes: `item_type`, `status`, `category_slug`, `featured`, `public_visibility`.

## `bmtg_pj_service_options`

id, item_id, label, description, price_type, price, meta (JSON), sort_order, status,
created_at, updated_at. Index: `item_id`.

## `bmtg_pj_suppliers`

id, name, supplier_type, contact_name, email, phone, notes, status, meta, created_at, updated_at.

## `bmtg_pj_journey_drafts`

id, client_id NULL, wp_user_id NULL, token (UNIQUE), title, notes, status
(`draft`|`saved`|`submitted`), created_at, updated_at.

## `bmtg_pj_journey_items`

id, journey_id, item_id NULL, service_type, option_id NULL, quantity, start_date, end_date,
adults, children, rooms, pickup, dropoff, flight_number, notes, meta (JSON), sort_order,
created_at, updated_at. Index: `journey_id`.

## `bmtg_pj_enquiries`

id, client_id NULL, journey_id NULL, reference (UNIQUE), dedupe_key, status, name, email, phone,
destinations, arrival_date, departure_date, adults, children, budget, preferences, special_requests,
supplier_cost_total, sell_price, markup, tax_total, discount_total, currency, fx_date,
quote_valid_until, internal_notes, assigned_to, created_at, updated_at.

Indexes: `reference`, `dedupe_key`, `client_id`, `status`, `assigned_to`.

## `bmtg_pj_enquiry_items`

id, enquiry_id, item_id NULL, service_type, description, quantity, unit_cost NULL, unit_price NULL,
line_total, meta (JSON), sort_order. Index: `enquiry_id`.

## `bmtg_pj_quotation_versions`

id, enquiry_id, version, status (`draft`|`issued`|`accepted`|`declined`|`superseded`|`expired`),
totals (JSON), notes, pdf_path, issued_at, accepted_at, expires_at, client_response, created_by,
created_at, updated_at. Index: `enquiry_id`, `status`.

## `bmtg_pj_quotation_items`

id, quotation_id, item_type, item_id NULL, description, quantity, unit_cost NULL, unit_price NULL,
line_total, meta, sort_order. Index: `quotation_id`.

## `bmtg_pj_confirmed_journeys`

id, enquiry_id, quotation_id NULL, reference, status
(`pending_supplier_confirmation`|`in_reservation`|`confirmed`|`completed`|`cancelled`), meta, created_at,
updated_at.

## `bmtg_pj_reservations`

id, confirmed_id, supplier_id NULL, item_id NULL, status, meta, created_at, updated_at.

## `bmtg_pj_documents`

id, client_id NULL, enquiry_id NULL, title, file_path (stored filename), file_name (original),
mime, size, status (`active`|`deleted`), created_by, created_at. Index: `client_id`, `enquiry_id`.

## `bmtg_pj_notifications`

id, type, recipient, subject, body, status (`pending`|`sent`|`failed`), dedupe_key (UNIQUE),
error, created_at, sent_at. Index: `status`, `created_at`.

## `bmtg_pj_audit_logs`

id, actor_id NULL, action, object_type, object_id, meta (JSON), created_at.
Indexes: `actor_id`, `(object_type,object_id)`, `created_at`.

## `bmtg_pj_sequences`

name PK, seq, updated_at. Seeded with `enquiry` and `journey`.

## Migration rules

- Schema version: `bmtg_pj_db_version` (currently `1`).
- `Core\Migrations` uses `dbDelta`; each activation/upgrade is additive and idempotent.
- No destructive migration is run on activation or deactivation.
- Data is preserved on deactivation; removal happens only through `uninstall.php`.