# LDA Sports Complex

Laravel 12 + React administration system for LDA Sports Complex, based on the seven supplied ZCMD member exports. The application keeps source identifiers for audit, separates a person from their renewable membership, and adds clean ledgers for plans, payments, facilities, and bookings.

## Included

- Session-based staff login with `admin`, `manager`, and `operator` roles
- Bright responsive public portal with complex/facility discovery and dedicated member authentication
- CNIC/email login, registration, password recovery, member dashboard, notifications, and appointment timeline
- Capacity-safe appointment booking with working-day schedules, configurable slots, and blocked dates
- Staff appointment operations for confirmation, arrival, file verification, gated form-number assignment, completion, cancellation, and immutable audit events
- Optional staff assignment to one sports complex; server-side scoping prevents cross-complex access
- Dashboard with portfolio totals, complex distribution, recent registrations, and source-backed SAP ledger activity
- Searchable, paginated member directory and member create/edit UI
- Membership-plan, membership, and payment API endpoints
- CNIC-based member portal accounts with a mandatory first-login password change
- Membership-plan facility access, maintenance notices, member profile management, and owner-only challan printing
- Live SAP challan generation with preview, GL line items, carried-forward outstanding charges, idempotency protection, printable output, and full request/response audit snapshots
- Facilities and bookings schema ready for the next UI section
- Idempotent XLSX import by source customer number
- Idempotent SAP customer-ledger PDF import with document, clearing, adjustment, and source-page audit fields
- MySQL production configuration and SQLite-compatible automated tests

## MySQL setup

```bash
cp .env.example .env
php artisan key:generate
```

Create a MySQL database and restricted application user, then set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`.

```bash
php artisan migrate --seed
npm install
npm run build
```

The seed creates the initial local account `admin@sports.local` with password `password`. Change it immediately outside local development.

## Import the supplied exports

```bash
php artisan members:import-zcmd \
  "/path/ZCMD Canal West.XLSX" \
  "/path/ZCMD China Scheme.XLSX" \
  "/path/ZCMD Gulberg.XLSX" \
  "/path/ZCMD Islamia Ground.XLSX" \
  "/path/ZCMD Minar-e-Pakistan.XLSX" \
  "/path/ZCMD Sabzazar.XLSX" \
  "/path/ZCMD Tajpura.XLSX"
```

Add `--dry-run` to validate files without committing. Re-running the same files updates changed records and leaves identical records unchanged.

Current `master data <complex>.XLSX` filenames are accepted as well as the original `ZCMD <complex>.XLSX` names. Johar Town is supported with code `JT` and account group `UD05`.

## Import SAP member transaction history

Install Poppler so the `pdftotext` command is available, then import the customer-ledger exports after importing the matching member master files:

```bash
php artisan members:import-sap-transactions \
  "/path/customer payments gulberg sports complex.pdf" \
  "/path/china scheme members data.pdf" \
  "/path/islamia ground members data.pdf" \
  "/path/Johar town members data.pdf" \
  "/path/minare pakistan member data.pdf" \
  "/path/sabzazar sports complex data.pdf" \
  "/path/tajpura sports complex members data.pdf"
```

The importer streams large PDFs, links rows by SAP customer number and complex, and deduplicates an exact canonical transaction hash. Add `--dry-run` to roll back after validation or `--strict` to roll back the whole import when any customer is absent from the matching master data. Without `--strict`, unmatched rows are counted and skipped rather than used to fabricate member records.

The original SAP date text is preserved. Dates outside 2017-2029 are flagged for review and omitted from the dashboard's latest-activity feed, but retained in the member statement and totals so source balances are not silently changed.

## Run locally

```bash
composer run dev
```

Open `http://127.0.0.1:8000`.

The public portal is available at `/`. The separate staff workspace is available at `/admin`. Portal members are denied access to staff APIs even though both surfaces use the same Laravel session infrastructure.

## Appointment configuration

`DatabaseSeeder` creates seven administrator-maintainable appointment purposes and a Monday–Saturday 09:00–17:00 schedule for each complex, with 30-minute slots and capacity two. These are initial operational defaults and should be reviewed before production. Administrators can maintain purposes, schedules, facilities, and blocked dates through the authenticated `/api/admin/...` configuration endpoints.

Appointment numbers use randomized public identifiers such as `APT-2026-123456`; database IDs are never exposed as appointment numbers. Slot availability is recalculated inside a transaction while locking the complex schedule. File entry requires both document verification and completion confirmation, and form assignment is rejected until that step is complete. Every workflow transition writes an appointment event, and form assignment creates an in-app member notification.

## Data boundary

The supplied workbooks remain member-master sources only. Historical financial activity comes exclusively from imported SAP customer-ledger PDFs and is stored separately from application-generated charges, payments, and challans. SAP document types such as `DR`, `DZ`, `Z2`, and adjustments are preserved with their signed amounts; they are not relabeled as online payments or locally generated challans. No expiry dates, facilities, attendance, bookings, or live SAP posting results are fabricated.

## SAP boundary

Set `SAP_CHALLAN_URL`, `SAP_CHALLAN_TIMEOUT`, `SAP_CHALLAN_AREA`, and `SAP_CHALLAN_TYPE` in `.env`. The service URL is configured once and query parameters are RFC 3986 encoded.

Fee GL accounts (`610400`, `610403`, `610402`, `610404`, `610401`, `610357`) remain separate from the eleven membership REACON accounts. New challans resolve REACON from the active member category, or 260111 for approved frozen-customer fees; fee-level REACON overrides no longer apply. Johar Town requires an exact approved cost/profit center. See [Membership reconciliation](docs/membership-reconciliation.md) for mappings, freeze controls, backfill, adjustment safeguards and SAP contract boundaries.

SAP generation succeeds only when the HTTP response is successful and includes a recognizable challan/document number (`challan_no`, `challanNo`, `document_no`, `documentNumber`, or `sap_document_no`, optionally under `data` or `result`). A timeout is recorded as `verification_required` because the remote GET may have completed even when the response was lost. Verify SAP before manually invoking the retry endpoint.

Outstanding values are derived from canonical `member_charges`, not prior challan totals. Unbilled outstanding charges may be carried once, preserving their original accounting. Charges already sent to live SAP are not automatically rebilled or locally superseded without a confirmed SAP cancellation contract. Generated corrections require manual SAP reconciliation; paid corrections require an authorized reversal. Verification remains authoritative and fail-closed.

## Member portal provisioning

Set a temporary password in `MEMBER_DEFAULT_PASSWORD`, then preview the affected members before creating accounts:

```bash
php artisan members:provision-logins --complex=GB --complex=IG --dry-run
php artisan members:provision-logins --complex=GB --complex=IG
php artisan members:provision-logins --file="/path/to/Gulberg Receivables Oct-26.xlsx" --dry-run
```

The member signs in with their 13-digit CNIC and the temporary password. All portal functions remain blocked until they replace it. Provisioning skips existing accounts, invalid CNICs, and duplicated CNICs. It never creates memberships, charges, challans, or payments.

Facilities are visible only when the member has an active membership. A facility with no selected membership plans is available to every active plan at that sports complex; selecting plans restricts it to those plans. Administrators can mark a facility available, under maintenance, or closed and publish a maintenance notice to affected member dashboards.

## Native mobile API

The member/applicant API is implemented under `/api/mobile/v1` with expiring, revocable Sanctum bearer tokens. See [the mobile developer guide](docs/mobile-api/README.md), [OpenAPI specification](docs/mobile-api/openapi.json), and [Postman collection](docs/mobile-api/postman_collection.json). Apply the personal-access-token migration before using this source. Mobile release deployment and HTTPS configuration are separate from this local implementation.

## Sports complex admin accounts

The central administrator can create and manage scoped logins in **Staff access**. Each complex admin is limited to its assigned customers and must change its temporary password at first login. See [Complex admin access](docs/complex-admin-access.md) for private credential handoff, the idempotent provisioning command, API permissions and release instructions.

# spports-complex
