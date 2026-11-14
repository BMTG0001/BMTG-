# BMTG CRM Foundation Architecture (Phase 2, Milestone 1)

Workspace implementation spec (additive to existing Integrations Hub + Chatbot). Native WordPress/PHP, single install/DB, existing data/encryption/settings preserved.

## Module
- Namespace/prefix: `BMTG\CRM` autoloaded from `includes/crm/` (class-{kebab}.php).
- Boot: `BMTG\CRM\Module::boot()` invoked from `Hub::boot()` after core services/hooks; idempotent registration. Activation adds CRM capabilities via `CRM\Capabilities`.
- Migrations: independent version option `bmtg_crm_db_version` (constant `BMTG_CRM_DB_VERSION = '1'`), `CRM\Migrations::maybe_migrate()` using `dbDelta`, additive/idempotent. No changes to integrations tables/credentials/encryption.

## Tables (`{prefix}bmtg_crm_*`)
- `bmtg_crm_customers`: id PK, first_name, last_name, email, email_normalized, phone, phone_normalized, country, wp_user_id (nullable), source, assigned_to, status, notes_internal (text), created_at, updated_at, deleted_at (soft). Unique/index: email_normalized, phone_normalized, assigned_to, status.
- `bmtg_crm_customer_preferences`: id PK, customer_id FK-like (indexed), pref_key, pref_value (longtext/json), source, created_at, updated_at. Index (customer_id,pref_key).
- `bmtg_crm_leads`: id PK, customer_id, title, status, source, channel, assigned_to, priority, destinations, travel_start, travel_end, pax_adults, pax_children, inbox_conversation_id, external_ref, lost_reason, won_at, closed_at, created_by, created_at, updated_at. Indexes: customer_id,status,assigned_to,next_follow_up (via reminders join/list), inbox_conversation_id, created_at.
- `bmtg_crm_lead_status_history`: id PK, lead_id, from_status, to_status, changed_by, reason, created_at. Index lead_id, created_at.
- `bmtg_crm_lead_activities`: id PK, lead_id, customer_id, type, body, meta (longtext json), actor_id, dedupe_key (varchar 191, nullable unique), created_at. Indexes: lead_id, customer_id, created_at, unique dedupe_key (when not null via app-level check + unique where supported in dbDelta best-effort; enforced by lookup).
- `bmtg_crm_reminders`: id PK, lead_id, assigned_to, due_at, remind_type, status (pending/done/snoozed/cancelled), note, completed_at, created_by, created_at, updated_at. Indexes: assigned_to,due_at,status, lead_id.
- `bmtg_crm_sequences`: name PK/varchar, seq bigint, updated_at (reserved for future atomic sequences e.g. bookings; not used for booking generation in this milestone).

## Domain rules
- Lead statuses: `new, contacted, qualified, proposal, negotiating, won, lost, on_hold`. Transitions recorded in status history + activity.
- Assignment/reassignment recorded in history/activity.
- Reminders: due-date handling (UTC), list due/overdue/upcoming, complete/snooze/cancel.
- Normalization: email lowercased/trimmed; phone digits-plus normalized. Safe duplicate handling: find existing customer by normalized email/phone before create; link lead to matched customer. Chatbot lead creation idempotent per conversation (reuses existing linked lead).

## Capabilities
`bmtg_crm_view_leads`, `bmtg_crm_manage_leads`, `bmtg_crm_assign_leads`, `bmtg_crm_view_all_leads`, `bmtg_crm_view_customers`, `bmtg_crm_manage_customers`, `bmtg_crm_manage_reminders`. Granted to administrator. Sales scope limited to own/assigned unless view_all.

## REST (`bmtg/v1/crm`, permission-checked, no-store)
- Customers: GET /customers, GET /customers/{id}, POST /customers, PUT /customers/{id}
- Preferences: GET /customers/{id}/preferences, POST /customers/{id}/preferences
- Leads: GET /leads, GET /leads/{id}, POST /leads, PUT /leads/{id}, POST /leads/{id}/status, POST /leads/{id}/assign
- Activities: GET /leads/{id}/activities, POST /leads/{id}/activities
- Reminders: GET /reminders, GET /reminders/due, POST /reminders, PUT /reminders/{id}, POST /reminders/{id}/complete
Pagination, validation, sanitization on all inputs.

## Integrations
- `bmtg_create_or_link_crm_lead` filter: consent already enforced by chatbot; create/link customer+lead, record activity, return lead id.
- `bmtg_unified_inbox_event` action: inspect payload, compute dedupe_key (sha256 of conversation_id|event|at|payload-json hash), skip if activity exists; append timeline activity linked to lead/customer by inbox_conversation_id.

## Safety
UTC timestamps, prepared queries/indexes, no API secrets/sensitive docs, no booking number generation, data preserved on upgrade/deactivate.
