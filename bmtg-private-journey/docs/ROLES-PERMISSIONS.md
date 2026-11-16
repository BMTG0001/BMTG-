# Roles and Permissions Matrix

Capabilities are installed by `Core\Capabilities` on activation and upgrade.

## Capability → role

| Capability | Administrator | Sales | Operations | Finance | Client |
|---|:-:|:-:|:-:|:-:|:-:|
| `bmtg_pj_manage` (super) | ✓ | — | — | — | — |
| `bmtg_pj_view_requests` | ✓ | ✓ | ✓ | ✓ | — |
| `bmtg_pj_manage_requests` | ✓ | ✓ | ✓ | — | — |
| `bmtg_pj_manage_catalogue` | ✓ | — | — | — | — |
| `bmtg_pj_manage_suppliers` | ✓ | — | — | — | — |
| `bmtg_pj_view_pricing` | ✓ | — | — | ✓ | — |
| `bmtg_pj_manage_pricing` | ✓ | — | — | ✓ | — |
| `bmtg_pj_manage_quotations` | ✓ | ✓ | — | — | — |
| `bmtg_pj_issue_quotations` | ✓ | ✓ | — | — | — |
| `bmtg_pj_manage_documents` | ✓ | — | ✓ | — | — |
| `bmtg_pj_view_reports` | ✓ | — | — | ✓ | — |
| `bmtg_pj_manage_settings` | ✓ | — | — | — | — |
| `bmtg_pj_client` | — | — | — | — | ✓ |

`bmtg_pj_manage` and `manage_options` both pass every `Capabilities::can_*` check.

## Roles

| Role | Purpose |
|---|---|
| `administrator` | Full plugin access. |
| `bmtg_pj_sales` | Requests, proposals, issuing quotations. No supplier cost or margin. |
| `bmtg_pj_operations` | Requests, supplier coordination, documents. No pricing. |
| `bmtg_pj_finance` | Requests (view), pricing, reports. No catalogue or supplier editing. |
| `bmtg_pj_client` | Own profile, enquiries, quotations, journeys, documents. |

## Data boundaries

- Clients can only read their own enquiries/quotations/documents.
- Staff endpoints check capabilities; client endpoints check ownership.
- Supplier cost, margin and internal notes are only rendered on staff screens that pass
  `Capabilities::can_view_pricing()` / `can_manage_quotes()`.
- Document downloads require either `bmtg_pj_manage_documents` or matching `client_id`.

## Staff scope notes

The Release 1 role matrix is role-based. If per-user scope (own leads only) is required later,
add an `assigned_to` filter in `Enquiry::list()` and `Admin` screens. The schema already stores
`assigned_to` and the audit log records assignments.