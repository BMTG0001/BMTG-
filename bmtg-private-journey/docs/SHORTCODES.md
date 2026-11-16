# Shortcode Registry

All shortcodes are registered by `Core\Shortcodes` and rendered by `Frontend\Renderer`.
Names contain no spaces. Every shortcode validates permissions, escapes output and loads
only the assets it needs. All work inside Elementor Shortcode widgets and normal pages.

| Shortcode | Purpose | Key attributes |
|---|---|---|
| `[bmtg_pj_welcome]` | Branded welcome/entry experience | — |
| `[bmtg_pj_login]` | Email/password + optional Google sign-in | `redirect_to` (URL) |
| `[bmtg_pj_registration]` | Client registration | — |
| `[bmtg_pj_first_login]` | Onboarding profile capture | — |
| `[bmtg_pj_dashboard]` | Client dashboard / onboarding gate | — |
| `[bmtg_pj_navigation]` | Responsive private portal navigation | — |
| `[bmtg_pj_catalogue]` | Filterable public catalogue | `type`, `category`, `per_page` |
| `[bmtg_pj_category]` | Catalogue filtered to a category | `category`, `title` |
| `[bmtg_pj_featured]` | Featured items | `limit`, `title` |
| `[bmtg_pj_item_detail]` | Service detail + add-to-journey form | `slug` |
| `[bmtg_pj_search]` | Catalogue search | `per_page` |
| `[bmtg_pj_journey_builder]` | Journey basket with builder framing | — |
| `[bmtg_pj_basket]` | Journey basket, edit/remove/reorder/save | — |
| `[bmtg_pj_enquiry_form]` | Complete quotation request | — |
| `[bmtg_pj_my_enquiries]` | Client enquiry list | — |
| `[bmtg_pj_my_quotations]` | Client proposals + accept/decline/modify | — |
| `[bmtg_pj_my_journeys]` | Confirmed journeys | — |
| `[bmtg_pj_documents]` | Protected client documents | — |
| `[bmtg_pj_profile]` | Profile + preferences | — |
| `[bmtg_pj_concierge]` | Configured contact channels | — |
| `[bmtg_pj_footer]` | Branded footer | — |
| `[bmtg_pj_brand_header]` | Brand header + navigation | `nav="0"` to hide navigation |

## Examples

```
[bmtg_pj_welcome]
[bmtg_pj_catalogue type="hotel" per_page="12"]
[bmtg_pj_featured limit="3" title="Selected journeys"]
[bmtg_pj_item_detail]
[bmtg_pj_basket]
[bmtg_pj_navigation]
```

## Behaviour notes

- Public catalogue content is indexable when the item is `published` and `public_visibility = 1`.
- Private screens render a sign-in prompt when the visitor is not logged in.
- The dashboard shows the first-login form until `bmtg_pj_onboarded` user meta is set.
- The enquiry form requires login before submission; guests are redirected to sign in.
- Item detail uses `?pj_item={slug}` on the mapped detail page.
- Catalogue filters use `?pj_type=`, `?pj_category=`, `?pj_search=` and `?pj_page=`.
- Shortcodes fail gracefully if a page is not mapped; links fall back to the home page.
- Elementor: place the shortcode in a Shortcode widget. Do not put PHP or business logic in HTML widgets.

## Adding a shortcode

1. Add a method to `Frontend\Renderer`.
2. Register the tag → method mapping in `Core\Shortcodes::register()`.
3. Escape all output and use `wp_nonce_field` for every POST form.
4. Update this registry.