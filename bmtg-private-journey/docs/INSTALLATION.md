# Installation and Activation

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- HTTPS recommended (Secure cookies and Google sign-in)
- WordPress mail (SMTP plugin recommended for reliable delivery)

## Install

1. Upload the `bmtg-private-journey` folder to `wp-content/plugins/`, or upload
   `bmtg-private-journey.zip` under **Plugins → Add New → Upload Plugin**.
2. Activate **BMTG Private Journey**.
3. Activation:
   - Creates the `bmtg_pj_*` tables via `dbDelta`.
   - Adds capabilities and the Sales / Operations / Finance / Client roles.
   - Stores the schema version in `bmtg_pj_db_version`.
   - Does **not** create pages automatically.

## Initial configuration

1. **Private Journey → Settings → Brand & Business Identity**
   - Business name, logo(s), addresses, phone, email, website.
   - Colours and fonts. Suggested defaults: antique gold `#C6A75E`, ivory `#F9F7F2`,
     charcoal `#292929`, deep green `#17251F`.
2. **Social & Contact**
   - Instagram, Facebook, WhatsApp number/message, contact links, enable switches.
3. **Email**
   - Internal recipients (one per line or comma separated), optional internal CC,
     from name/email, Reply-To policy.
   - The client is never added to To/Cc/Bcc. No automatic client acknowledgement is sent.
4. **Access**
   - Registration on/off, post-login and post-logout destinations,
     optional Google OAuth client ID.
5. **Page Mapping**
   - Map existing pages or click **Create missing pages now**.
   - Place the listed shortcodes in the mapped pages or Elementor Shortcode widgets.
6. **Catalogue**
   - Add services, images, descriptions, options and statuses.
7. **Roles**
   - Assign Sales / Operations / Finance roles to staff users.
   - Clients receive the `bmtg_pj_client` role automatically on registration.

## Elementor

- Add a **Shortcode** widget and paste one plugin shortcode.
- Do not put PHP or database logic in an HTML widget.
- The visual page design is approved separately; the plugin supplies the functional markup.
- The existing **Inquire Privately** button can link to the mapped Welcome page.

## Google Sign-In (optional)

1. Create an OAuth 2.0 Web application client in Google Cloud.
2. Add the site domain to authorised JavaScript origins.
3. Paste the client ID into **Settings → Access**.
4. Enable Google sign-in.
5. Test with a Google account that is not already a site user.

## Verification after activation

- Visit the mapped Welcome page.
- Sign in with a test staff account; open every admin submenu.
- Create a published service with a fixed price.
- As a test client: register, complete onboarding, add a service, save the journey,
  submit an enquiry.
- As staff: review the request, create a proposal, issue it.
- As client: accept/decline/request changes; verify no supplier booking is implied.
- Upload a document as staff; download it as the owning client.
- Check `bmtg_pj_notifications` for a sent internal email and no client recipient.

## Hostinger / shared hosting notes

- Confirm PHP 8.0+ and the MySQL/MariaDB version.
- Ensure `wp_mail` can send (SMTP plugin recommended).
- Keep the plugin portable: no Hostinger-specific code is used.
- If object caching is enabled, exclude `bmtg_pj_*` private pages and `admin-post.php`.
- Do not enable page caching for logged-in portal pages.

## Rollback

See `docs/BACKUP-ROLLBACK.md`.