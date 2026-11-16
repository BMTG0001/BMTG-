# Backup and Rollback

## What to back up

1. **Database tables** with prefix `bmtg_pj_`:
   - `bmtg_pj_clients`, `bmtg_pj_client_preferences`
   - `bmtg_pj_catalogue_categories`, `bmtg_pj_catalogue_items`, `bmtg_pj_service_options`
   - `bmtg_pj_suppliers`
   - `bmtg_pj_journey_drafts`, `bmtg_pj_journey_items`
   - `bmtg_pj_enquiries`, `bmtg_pj_enquiry_items`
   - `bmtg_pj_quotation_versions`, `bmtg_pj_quotation_items`
   - `bmtg_pj_confirmed_journeys`, `bmtg_pj_reservations`
   - `bmtg_pj_documents`, `bmtg_pj_notifications`, `bmtg_pj_audit_logs`, `bmtg_pj_sequences`
2. **Options**:
   - `bmtg_pj_settings`
   - `bmtg_pj_pages`
   - `bmtg_pj_db_version`
   - `bmtg_pj_installed_at`
3. **Files**:
   - `wp-content/bmtg-private/` (protected documents)
   - `wp-content/plugins/bmtg-private-journey/`
4. **User meta**:
   - `bmtg_pj_onboarded`

## Backup commands (example)

```
mysqldump --single-transaction --routines --triggers \
  wp_database $(wp db prefix)bmtg_pj_% > bmtg_pj_db.sql

tar -czf bmtg-private-docs.tar.gz wp-content/bmtg-private
tar -czf bmtg-private-journey-plugin.tar.gz wp-content/plugins/bmtg-private-journey
```

## Safe update procedure

1. Put the site in maintenance mode.
2. Back up database + `bmtg-private` + plugin folder.
3. Deactivate the plugin (data is preserved).
4. Replace the plugin folder.
5. Activate the plugin; `Core\Migrations` applies additive changes.
6. Check `bmtg_pj_db_version`.
7. Run the manual smoke test in `docs/TESTING.md`.
8. Take the site out of maintenance mode.

## Rollback

### Plugin rollback

1. Deactivate the current plugin.
2. Restore the previous plugin folder from backup.
3. Activate it.
4. Restore the previous database dump if the new version changed data incompatibly.
5. Clear object/page caches.

### Database rollback

```
mysql wp_database < bmtg_pj_db.sql
```

This drops/recreates the backed-up tables. Do not restore over a live site without maintenance mode.

### Document rollback

Restore `wp-content/bmtg-private` from the archive. Confirm the directory is not web-accessible.

## Uninstall

`uninstall.php` runs only via **Plugins → Delete** after deactivation. It:

- Drops all `bmtg_pj_*` tables.
- Removes the plugin roles and capabilities.
- Deletes plugin options.
- Deletes `bmtg_pj_onboarded` user meta and plugin transients.
- Does **not** delete files in `wp-content/bmtg-private`; remove them manually if required.

Always back up before uninstalling.

## Disaster recovery checklist

- [ ] Database dump date and location recorded
- [ ] `bmtg-private` archive date and location recorded
- [ ] Plugin ZIP archived
- [ ] Staging restore tested
- [ ] Maintenance window agreed
- [ ] Post-restore smoke test completed