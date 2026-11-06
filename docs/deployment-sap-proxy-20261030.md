# SAP proxy Laravel release — 30 October 2026

Release: sports-sap-proxy-20261030, live at https://104.207.64.25/sports-complex/.

Deployed SapChallanService, services configuration, mock-payment selector source and production assets. Configured public creation URL http://103.8.115.199:91/sapchallan/index.php with internal fallback http://172.16.130.200:91/sapchallan/index.php. Payment mode remains live. Payment verification configuration was not changed. PHP proxy replacement was reported installed by the user; this release does not modify that separate host.

150 tests / 1,106 assertions passed, targeted Pint passed, Vite build passed. Build reports the existing bundle-size advisory. Live source/build SHA256 checksums match local. Live numeric-response parser check passed without an outbound SAP call. Config, routes and views cached; no pending migrations; PHP-FPM active; maintenance off.

Backup: /var/backups/sports-sap-proxy-20261030/ with application.tar.gz, database.sqlite, env, nginx.conf, SHA256SUMS and rollback.sh. Archive validation and all backup checksums passed; rollback script syntax checked, not executed. No database schema change. SQLite integrity OK; complete row comparisons against backup passed for members, challans, challan_items, member_charges, payments, bank_transactions, users and sports_complexes. Counts: 10,187 members, 1 challan, 1 challan item, 2,262 charges, 0 payments, 1 bank transaction, 2,296 users, 10 complexes.

Homepage, admin shell, release marker and deployed JS/CSS returned HTTP 200. Private storage is writable by www-data; public/vendor/three/three.module.js exists. An initial probe of the nonexistent /api/public/complexes returned 404; the configured public route is /api/public/portal.

No real challan was issued and no bank settlement was attempted. Real upstream creation and the internal fallback's network availability remain unverified by this deployment.
