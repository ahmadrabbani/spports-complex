# Sports complex administrator logins

Implemented locally on 29 October 2026 and deployed on 30 October 2026. Ten new production logins were generated separately; use the private production handoff file, not the earlier local credentials. See [deployment evidence](deployment-20261030.md).

## Using the logins

All staff use the existing `/admin` sign-in page with their own login email and password. The email determines the assigned sports complex; the user cannot select another complex at login. The page no longer pre-fills or displays the starter administrator credentials.

There are now **10 separate complex-admin accounts**, one for every active complex in the local database: Canal Bank (CW), Gulberg (GB), Sabzazar (SZ), Islamia Ground (IG), China Scheme (CS), Minar-e-Pakistan (MP), Johar Town (JT), Tajpura (TP), LDA Pedal Arena (PA), and Hockey Stadium (HS).

Generated functional login IDs follow `<lowercase complex code>.admin@sports.local`; these are internal login identifiers, not claimed deliverable email addresses. The central administrator can replace them with the assigned staff member's real login email in **Staff access**. No emails were sent.

Unique randomly generated temporary passwords are in the private local file:

`storage/app/private/complex-admin-logins-20261029.csv`

The file has mode 0600 and is outside public web storage. Share each row only with the appropriate administrator through a secure channel, and remove the plaintext handoff file after secure distribution. Do not publish or commit it. Passwords are hashed in `users` and excluded from account lists and audit history.

At first login, each new administrator must replace the temporary password. Customer APIs, cards and documents are blocked until completion. The new password requires the current password, confirmation, at least 12 characters, upper/lowercase letters, numbers and a symbol. No existing administrator password was reset.

## Permissions

The existing `admin` role is reused with a required `sports_complex_id` and an explicit `is_complex_admin` flag. This preserves the existing controllers and prevents a missing/deleted assignment from silently becoming central administrator access.

| Capability | Central administrator | Complex administrator |
|---|---|---|
| Customer directory, search, dashboard and ledger | All complexes | Assigned complex only |
| Add/edit customers, memberships and payments | All complexes | Assigned complex only |
| Member/family documents, photos and cards | All complexes | Assigned complex only |
| Freeze/unfreeze, category assignment, approved freeze fees | All complexes | Assigned complex only |
| Challans, print, verification, adjustment requests/history | All complexes | Assigned complex only; existing SAP safeguards remain |
| Appointments | All complexes | Assigned complex only |
| View reference data and available plans | All complexes | Own complex plus shared plans/categories |
| Create/edit/disable/reassign staff logins | Yes | No |
| Global accounting, fee/plan mapping, SAP centers, facilities/images and bank diagnostics | Yes | No |

Server-side checks protect both lists and direct record URLs. Changing a URL, submitted complex ID, filter or customer ID does not grant access to another complex. The customer form preselects the only assigned complex. The sidebar and banner show the assigned complex and hide central administration controls.

Disabling an account takes effect on subsequent requests, including an existing session and authenticated private files. Inactive complexes and missing complex assignments fail closed. Invalid sessions are cleared so the user can sign in again. Logout remains available even for a disabled account.

## Central Staff access screen

The central administrator can:

1. Create a named administrator login and choose one active complex. The server generates a unique temporary password and displays it once, with `Cache-Control: no-store`.
2. Edit the name, login email, assigned complex and active state.
3. See whether the first-login password change is complete.

Complex admins cannot list staff users, create users, edit themselves into another complex, clear their scope, change their role or manage central administrator accounts. This interface does not reset existing passwords. Account creation and changes are recorded in `staff_access_audits` with actor, timestamp and non-secret before/after fields; password-change audits record only that setup was completed.

## API and command

Existing session/CSRF authentication is retained. Staff login is throttled.

| Method | Route | Permission |
|---|---|---|
| POST | `/login` | Staff credentials; active account and complex required |
| GET | `/api/me` | Own identity and assigned complex; available during first-login setup |
| PUT | `/api/staff/password` | Own current password, `password`, `password_confirmation`; throttled |
| GET | `/api/staff-users` | Central administrator only |
| POST | `/api/staff-users` | Central administrator; `name`, `email`, `sports_complex_id` |
| PUT | `/api/staff-users/{staffUser}` | Central administrator; name/email/assignment/active state only |

Create a new account through the API:

```json
{"name":"Assigned administrator","email":"admin@example.test","sports_complex_id":1}
```

The create response contains `user` and `temporary_password` once. List/update responses never contain the password. Client-supplied role/member-link/scope-flag fields are prohibited; the server always creates a scoped administrator.

Provision missing accounts without changing existing accounts or passwords:

```sh
php artisan staff:provision-complex-admins
php artisan staff:provision-complex-admins --apply --credentials=storage/app/private/new-complex-admin-logins.csv
```

The default is a dry run. An existing admin for a complex, including an inactive admin, is preserved and not silently reenabled. A generated email collision aborts the batch instead of repurposing an unrelated user. The credentials path must be a new file. Creation is transactional and credentials are written privately; a failed batch rolls back and removes its incomplete export.

Local provisioning created 10 accounts; a second apply found **0 missing accounts**. All 10 password hashes and assignments were verified and all 10 temporary passwords were distinct. No password values are recorded in this document or public logs.

## Migration and files

Migration: `2026_10_29_000004_add_complex_admin_access` adds the indexed `users.is_complex_admin` flag and `staff_access_audits`. Existing scoped administrators are flagged without resetting passwords, activity or membership data. The existing role and sports-complex foreign key are retained.

New: `StaffAccessController`, `StaffAccessService`, `StaffAccessAudit`, `EnsureActiveAccount`, `ProvisionComplexAdmins`, React `StaffAccess` / `StaffPasswordChange`, `ComplexAdminAccessTest`, and the migration.

Updated: `User`, `SportsComplex`, `AuthController`, `EnsureStaffUser`, `EnsurePasswordChanged`, reference data / reconciliation plan scoping, settings authorization in accounting / appointments / gallery / bank controllers, routes and middleware registration, React shell/login/member form/bank controls, styles and compiled assets.

A pre-change SQLite backup is at `storage/app/backups/complex-admins-20261029/database.sqlite` (mode 0600). No existing customer, membership, challan, payment or SAP ledger values were modified to provision these logins. The main database now has the original central administrator plus ten newly provisioned complex administrators; synthetic browser accounts live only in a separate QA database.

## Validation and release

Automated tests cover scoped login, dashboard/list/reference filtering, own customer creation/editing, foreign customer creation/moving/deleting/read protection, private documents/photos/cards, family and challan URLs, central-only staff management and settings, missing/inactive assignments, first-login setup and strong-password checks, private credential exports, audit secrecy and idempotent provisioning. Existing regression tests are included.

Browser QA uses `tmp/reconciliation-ui/database.sqlite`, synthetic accounts and mock payment mode. It verifies central Staff access, the first-login setup gate, and a complex admin's directory showing only its own customers despite another complex's customer being present.

To release, back up production code/database/private storage, apply the new migration, then provision accounts in that environment or create them through central Staff access. Local credentials do not create production users. Do not run the broad default DatabaseSeeder against production, because it includes starter credentials and unrelated configuration. Production release and login verification were subsequently completed on 30 October; see the deployment evidence.

Final validation: **117 tests passed / 858 assertions**; Vite production build and targeted PHP formatting passed. The new migration rolled back and reapplied successfully on the isolated QA database. [Validation evidence](complex-admin-validation.json) confirms that all 10,187 customers and existing membership/financial records, including 331,307 ledger transactions, are unchanged; existing user records/password hashes are unchanged. SQLite integrity and foreign-key checks pass. [Browser evidence](screenshots/complex-admin-customers.png) uses synthetic records only.
