# Page Mapping

Plugin screens are mapped to normal WordPress pages in:

**Private Journey → Settings → Page Mapping**

Each row lets an administrator:

- Select an existing WordPress page.
- See the required shortcode.
- See the suggested route.
- Create the page only when the administrator explicitly clicks **Create missing pages now**.

## Registry

| Screen | Shortcode | Suggested slug |
|---|---|---|
| Welcome | `[bmtg_pj_welcome]` | `/private-journey/` |
| Login | `[bmtg_pj_login]` | `/private-journey/login/` |
| Registration | `[bmtg_pj_registration]` | `/private-journey/register/` |
| Client Dashboard | `[bmtg_pj_dashboard]` | `/private-journey/dashboard/` |
| Catalogue | `[bmtg_pj_catalogue]` | `/private-journey/collection/` |
| Service Detail | `[bmtg_pj_item_detail]` | `/private-journey/service/` |
| Journey Builder | `[bmtg_pj_journey_builder]` | `/private-journey/journey/` |
| Journey Basket | `[bmtg_pj_basket]` | `/private-journey/basket/` |
| Enquiry | `[bmtg_pj_enquiry_form]` | `/private-journey/enquiry/` |
| My Enquiries | `[bmtg_pj_my_enquiries]` | `/private-journey/enquiries/` |
| My Quotations | `[bmtg_pj_my_quotations]` | `/private-journey/quotations/` |
| My Confirmed Journeys | `[bmtg_pj_my_journeys]` | `/private-journey/journeys/` |
| My Documents | `[bmtg_pj_documents]` | `/private-journey/documents/` |
| My Profile | `[bmtg_pj_profile]` | `/private-journey/profile/` |
| Concierge & Support | `[bmtg_pj_concierge]` | `/private-journey/concierge/` |

## Safety

- Repeated use of **Create missing pages now** never duplicates pages.
- Pages created by the plugin are marked with `_pj_system_page`, `_pj_page_key` and `_pj_shortcode` meta.
- The plugin does not overwrite an existing page's content. If you map an existing page, place the
  shortcode there yourself.
- The actual visual design of each page is approved separately before finalisation.
- The existing Elementor **Inquire Privately** button can link to the mapped Welcome page.

## Unmapped screens

If a screen has no page, `Core\Pages::url()` returns the home URL and the shortcode still renders.
This prevents fatal errors on a fresh install before mapping is complete.