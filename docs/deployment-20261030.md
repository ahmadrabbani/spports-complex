# Sports Complex release on 30 October 2026

Release: `sports-update-20261030T0215PKT`  
Live URL: https://104.207.64.25/sports-complex/  
Server: `lda-web:/var/www/sports-complex-manager`

## Delivered

- Official REACON master and member categories, safe backfill, audited freeze/unfreeze, explicit freeze-fee approvals, and protected challan adjustments.
- Ten new complex-admin logins with fixed complex scope, central Staff access management and first-login password setup.
- Normalized CNIC search and the individual-member family-entry restriction across shared staff/portal/mobile creation logic.

## Release evidence

- Local full suite: 135 tests, 988 assertions. Vite build passed; existing large-bundle advisory remains. Targeted Pint passed.
- Four 29 October migrations applied as production batch 19. Config, route and view caches regenerated. PHP 8.3 FPM active; maintenance off.
- All transferred source and current JS/CSS asset checksums matched. HTTPS root, administrator page, gallery dependency and compiled assets returned HTTP 200. Unauthenticated identity/private-card requests returned HTTP 401.
- SQLite integrity `ok`, zero foreign-key violations. Hashes of original financial rows and original user records matched the pre-release snapshot. Member snapshots exclude the intentional backfill timestamp changes and new schema fields.
- Retained: 10,187 members, 331,307 member transactions, 2,262 charges, 1 existing challan and 1 existing family record. Existing user count 2,285; added 10 new administrators, resulting in 2,295 users.
- Backfill applied 10,111 unambiguous mappings; second dry run: mapped 0, unchanged 10,111, review 76. Exceptions: 75 legacy freeze accounts and 1 unknown account 260000. No historical financial rows were rewritten.
- Live authenticated HTTP: central login and Staff access; normalized and formatted CNIC queries return matching member IDs; family additions to both individual accounts return 422/FAMILY_MEMBERS_NOT_ALLOWED and leave family count unchanged.
- All 10 new password hashes, distinct temporary passwords, scope assignments and password-change flags verified privately. Representative CW live login succeeds and customer API returns 409/PASSWORD_CHANGE_REQUIRED until setup. No password was changed as part of verification.
- Live UI screenshots: sign-in, reconciliation master, Staff access. Functional workflow screenshots use the isolated QA database and mock payments, clearly identified in the management report.

## Backups and rollback

Private server directory: `/var/backups/sports-update-20261030T0215PKT/`

- `application.tar.gz`: pre-release source, runtime files, configuration and private storage.
- `database.sqlite`: SQLite backup API snapshot, integrity checked.
- `env`, `nginx.conf`, `SHA256SUMS`: configuration and checksums; all backup checksums passed.
- `before-data.json`, `after-data.json`: original-column data hashes/counts; `baseline-schema.json` records the comparison fields and original IDs.
- `reconciliation-review-before.csv`, `reconciliation-review-after.csv`: private exception reports.
- `source-checksums.txt`, `source-check-results.txt`, `live-api-checks.json`, `credential-checks.json`: deployment verification.
- `rollback.sh`: syntax-checked restoration instructions preserving a pre-rollback database and private-upload copy. Archive contents and backup DB readback were checked. A full restoration was not executed against the live service. Reconcile any later production writes before restoring the release database.

The existing `.env`, production SQLite database and uploads were preserved. Local databases, test accounts, tests, development backups and private local credentials were not transferred. Required public vendor assets were included. Private uploads remain outside public web storage, owned by www-data and writable by the application.

## Administrator credential handoff

Production-generated credentials remain mode 0600 in:

- Server: `storage/app/private/complex-admin-logins-20261030.csv`
- Private local copy: `storage/app/private/production-complex-admin-logins-20261030.csv`

Distribute only the appropriate row to each assigned administrator through a secure channel. The management report and screenshot archive contain no passwords. Existing accounts/passwords were not reset. Local credentials generated on 29 October are not the production credentials.

## Remaining follow up

Review the 76 exceptions and confirm category associations for the three generic membership plans. Assign the functional logins to named staff, distribute credentials and complete first-login setup. Approve the operational freeze-fee amount/GL policy before billing. Obtain authoritative SAP payment response semantics and cancellation/reversal acknowledgements; unknown responses continue to fail closed. No live SAP issuance, cancellation, reversal or settlement was performed for deployment verification. The existing bank-test environment banner remains; deployment does not constitute bank integration or settlement certification.

## Management deliverables

`output/sports-complex-update-20261030/` contains the five-page Word/PDF report, original screenshots and safe verification JSON. The screenshot ZIP is separate from the private credential handoff.
