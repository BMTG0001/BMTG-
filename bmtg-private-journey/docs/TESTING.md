# Test Results and Test Plan

## Build-environment validation (executed)

The build environment had Node.js but no PHP CLI and no WordPress installation.
Static validation was therefore performed with the `php-parser` npm package.

| Check | Result |
|---|---|
| PHP syntax parse, all files | **PASS** — 33 PHP files parsed, 0 syntax errors |
| Duplicate class method definitions | **PASS** — none found |
| Unresolved `$this->`, static and `new` class references | **PASS** — only 2 known false positives (`$this->{$method}` dynamic dispatch and `\WP_REST_Response` global class) |
| Missing required method arguments | **PASS** — none detected |
| Manifest / file presence | **PASS** — plugin header, uninstall, assets, docs |

Validation scripts are reproducible with `php-parser`:

```
node validate.js /path/to/bmtg-private-journey
node analyze.js /path/to/bmtg-private-journey
node argcheck.js /path/to/bmtg-private-journey
```

## Not executed in this environment

- WordPress runtime activation / deactivation.
- `dbDelta` execution against a real database.
- Email delivery through `wp_mail`.
- Google token verification against Google's live endpoint.
- Browser rendering at the five responsive breakpoints.
- Load/performance measurement against the real host.

These are **release blockers** and must be completed on a staging WordPress install before
production deployment. Do not claim the plugin is production-tested from this build alone.

## Manual test plan (staging)

### 1. Activation and schema
1. Activate the plugin.
2. Confirm `bmtg_pj_db_version` = `1` and all `bmtg_pj_*` tables exist.
3. Confirm roles `bmtg_pj_sales`, `bmtg_pj_operations`, `bmtg_pj_finance`, `bmtg_pj_client`.
4. Activate/deactivate repeatedly; confirm no errors and data preserved.
5. Re-activate; confirm categories are seeded once, not duplicated.

### 2. Branding
1. Change every brand field; save.
2. Confirm logo, colours, fonts and social links render in:
   welcome, login, registration, dashboard, catalogue, item detail, basket, enquiry,
   quotations, documents, concierge, footer.
3. Disable each social link; confirm it disappears.
4. Confirm WhatsApp URL uses the configured number and encoded message.
5. Confirm no company detail is hardcoded in rendered HTML.

### 3. Pages
1. Open Page Mapping; map each screen.
2. Click **Create missing pages now**; confirm pages created with the correct shortcode.
3. Click again; confirm no duplicates and the notice reports existing pages.
4. Place shortcodes in Elementor Shortcode widgets; confirm rendering.

### 4. Auth and onboarding
1. Register a client; confirm role, client row and onboarding form.
2. Complete onboarding; confirm profile saved and `bmtg_pj_onboarded` set.
3. Log out; confirm redirect to the configured destination and cookie invalidation.
4. Log in; confirm dashboard opens directly.
5. Google sign-in: valid Google account → dashboard; invalid/expired token → friendly error.
6. Confirm no auth token is stored in `localStorage`.

### 5. Catalogue and basket
1. Create one item per service type; publish with public visibility.
2. Confirm public catalogue filters, search, pagination and item detail.
3. Add each service type to the basket with valid and invalid requirements.
4. Confirm service-specific fields appear and validation messages are helpful.
5. Reorder, edit, remove; save as logged-in client.
6. Guest: add to basket, confirm sign-in prompt on save/submit.
7. Log in; confirm guest basket merges into the client account.

### 6. Enquiry and email
1. Submit one complete enquiry.
2. Confirm reference `BMTG-PJ-YY-NNNN`, enquiry rows and snapshot items.
3. Confirm internal email received at configured recipients.
4. Confirm client is not in To/Cc/Bcc; Reply-To is the client where enabled.
5. Submit identical enquiry within 15 minutes; confirm same reference / no duplicate email.
6. Simulate `wp_mail` failure; confirm notification row status `failed` and error logged.

### 7. Pricing and quotations
1. Create a proposal with known cost and selling price.
2. Confirm subtotal, discount, tax, total and margin are correct.
3. Leave one line without a selling price; confirm it shows Price on Request.
4. Issue the proposal; confirm previous issued version is superseded.
5. Confirm client sees the latest non-draft version and cannot see cost/margin/internal notes.
6. Accept as client; confirm quotation `accepted` and confirmed journey
   `pending_supplier_confirmation`, **not** a supplier booking.
7. Decline and request modifications; confirm statuses.

### 8. Documents
1. Upload a PDF as staff; assign to a client.
2. Confirm the file is outside `wp-content/uploads`.
3. Download as the owning client; confirm success.
4. Attempt download as another client; confirm 403.
5. Attempt a direct URL to the file; confirm denied.
6. Delete as staff; confirm row marked deleted and file removed.

### 9. Admin and reports
1. Confirm each staff role sees only its permitted menus and actions.
2. Confirm Finance sees pricing but not catalogue/supplier editing.
3. Confirm Sales cannot see supplier cost/margin in the client proposal.
4. Confirm audit log entries for create/update/delete/issue/status changes.
5. Confirm reports totals match stored data.

### 10. Responsive / performance
- Test 360, 768, 1024, 1440 and 1920 px.
- Confirm no horizontal scroll at 360 px.
- Measure catalogue query time and page weight on the staging host.
- Confirm private pages are not cached by the host page cache.
- Confirm no private data in a shared cache.

## Definition of done for Release 1

- All Phase 1 and Phase 2 modules implemented and passing the manual plan.
- No PHP notices/warnings in `debug.log` during the full journey.
- Internal email delivery verified.
- Document permissions verified.
- Staging sign-off before production deployment.