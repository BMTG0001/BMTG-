=== BMTG Business Manager ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.4
License: Proprietary

Business management plugin including Integrations Hub (Groq, OpenRouter, Unsplash, Canva Connect) and public website chatbot.

== Installation ==
1. Upload the `bmtg-business-manager` folder to `/wp-content/plugins/` or install the ZIP via Plugins > Add New > Upload Plugin.
2. Add an encryption key to `wp-config.php` (required before saving secrets):
   define('BMTG_ENCRYPTION_KEY', 'base64:REPLACE_WITH_32_RANDOM_BYTES_BASE64');
   Generate 32 random bytes (e.g. `openssl rand -base64 32`) and prefix with `base64:`. Back this up separately from the database.
3. Activate "BMTG Business Manager".
4. Go to Settings > BMTG Integrations to connect services (capability: administrators with `bmtg_manage_integrations`).

== Notes ==
- PHP sodium extension is required.
- Secrets are encrypted at rest; values are never shown in UI/API/logs/audit (hint/source only). `BMTG_{CONNECTOR}_{FIELD}` constants in wp-config override DB values.
- Public chatbot: use shortcode `[bmtg_chatbot]` or enable auto-inject in settings; integrates with Integrations Hub (Groq primary, OpenRouter fallback) and unified inbox/CRM hooks.
- Deactivation/upgrades preserve credentials and audit data.
