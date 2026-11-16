# Security Report

## Controls implemented

| Area | Control |
|---|---|
| Access control | Capability checks on admin screens, REST callbacks and document downloads |
| CSRF | Nonces on every state-changing form and download link |
| Output | `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses_post` |
| Input | `sanitize_text_field`, `sanitize_email`, `sanitize_key`, `absint`, `wp_kses_post` |
| SQL | `$wpdb->prepare` with placeholders; identifiers from fixed schema methods; table names never user input |
| Auth | WordPress cookie authentication via `wp_signon` / `wp_set_auth_cookie`; no tokens in `localStorage` |
| Session | 2-day cookie by default, 14 days with "keep me signed in"; `wp_logout()` invalidates |
| Google | Server-side ID token verification against `https://oauth2.googleapis.com/tokeninfo`; audience and email-verified checks; IP rate limit |
| Documents | Stored outside public uploads; `.htaccess` deny; authenticated, capability/ownership-checked download; `nocache_headers`; `nosniff` |
| Email | Internal recipients only; client address stripped from To/Cc/Bcc; client may only appear in Reply-To; dedupe key in `bmtg_pj_notifications` |
| Pricing | Supplier cost/margin stored server-side; never rendered to clients |
| Audit | Actor, action, object and JSON metadata on staff mutations |
| Secrets | No API secrets required for Release 1; Google client secret optional and stored in the settings option |
| Cookies | Guest basket cookie is HTTP-only, SameSite=Lax, Secure when SSL |
| Caching | REST responses send `Cache-Control: no-store`; private pages emit `noindex`; no page caching for logged-in portal content |
| Uploads | Extension allow-list, 25 MB limit, `is_uploaded_file`, randomised filename, `0640` permissions |
| Uninstall | Explicit removal of tables, roles, options and onboarding metadata |

## Threat notes

- **Client enumeration:** registration checks `email_exists()` and returns a friendly message.
  This is a usability trade-off; if strict non-enumeration is required, replace it with a generic
  "check your inbox" flow.
- **Google sign-in:** the plugin trusts Google's tokeninfo response. A compromised Google client ID
  or token endpoint would affect sign-in. Keep the OAuth client restricted to the site origin.
- **Guest basket token:** possession of the HTTP-only cookie grants access to that guest basket.
  It contains no personal data until sign-in. Saving/Submitting requires a logged-in account.
- **File downloads:** the download endpoint checks ownership/capability, but does not scan for
  malware. Uploaded files are for staff/client use only.
- **Admin capability escalation:** administrators can grant capabilities. Treat admin accounts as
  fully trusted.

## Rate limiting / spam

- Google verification: 20 attempts per IP per minute (`transient`).
- Login/register: WordPress core protections apply; add a dedicated limiter at the host/WAF layer
  if the site is targeted.
- Enquiry dedupe: identical request within 15 minutes reuses the existing reference.
- Email dedupe: one notification per enquiry/quotation event.

## Recommended hardening

1. Force HTTPS and set `WP_DEBUG` off in production.
2. Add a WAF or rate limiter for `admin-post.php` and `wp-login.php`.
3. Restrict `wp-content/bmtg-private` at the web-server level (deny all).
4. Set file permissions `0644` for PHP/CSS/JS and `0640` for stored documents.
5. Review the administrator list and staff roles quarterly.
6. Keep WordPress, PHP and the plugin updated.

## Not in scope for Release 1

- Live supplier availability or automatic booking.
- Payment processing.
- Two-factor authentication for clients.
- Document malware scanning.
- Per-user "own leads only" scope (role-based only).