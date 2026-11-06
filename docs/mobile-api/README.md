# LDA Sports Complex Mobile API

Developer handoff • API v1 • HTTPS update 22 October 2026

## Delivery and scope

This package implements a native mobile backend for **members and applicants**. It supports public discovery, device login, registration, password recovery, profile and memberships, dues and ledger, challans, facilities, appointments, notifications, membership cards, dependents and BOP MPGS sandbox payments.

**Status: deployed**, with publicly trusted HTTPS enabled on 22 October 2026. Website: `https://104.207.64.25/sports-complex/`. Live mobile API: `https://104.207.64.25/sports-complex/api/mobile/v1`. HTTPS token login, account and ledger reads, logout and token revocation were verified externally using a temporary synthetic account, which was then removed. HTTP requests redirect to HTTPS; clients should use the HTTPS base URL directly. The IP certificate renews automatically, with checks every six hours and a successful renewal/deploy-hook dry run.

- Local base URL: `http://127.0.0.1:8000/api/mobile/v1`
- Android emulator: `http://10.0.2.2:8000/api/mobile/v1`
- Physical devices: use a reachable development host and appropriate HTTPS configuration.
- Live base URL: `https://104.207.64.25/sports-complex/api/mobile/v1`.
- Mobile staff administration, new SAP challan creation, monthly billing scheduler, attendance scanning, push delivery, refunds, settlement reconciliation and production bank activation are outside this member API release.
- `openapi.json` is importable into Swagger Editor or compatible API tools. Reused resource responses permit additive fields; this guide defines the fields the mobile app should rely on.
- `postman_collection.json` includes all 33 operations, placeholder data and a login script that saves the returned token. Replace placeholder IDs with records from your own account.

## Authentication and app flow

Send `Accept: application/json` and, for JSON writes, `Content-Type: application/json`. Protected requests also require `Authorization: Bearer <access_token>`. Cookies, `/sanctum/csrf-cookie` and CSRF tokens are not required for these routes. Browser session authentication alone is rejected.

1. Load `GET /portal` to get complexes and appointment purposes.
2. Existing members sign in using their provisioned member CNIC or email and password. Staff/admin credentials are deliberately rejected.
3. A new applicant may register. Registration creates an unlinked applicant account; it does not create an active membership or grant facility access. An existing member CNIC requires staff-assisted provisioning; CNIC knowledge alone cannot link financial records.
4. Store `access_token` in iOS Keychain or Android Keystore-backed secure storage. Never embed tokens or passwords in source, analytics, query strings or logs.
5. If `must_change_password` is true, show the change-password screen. Only `/auth/me`, `/auth/password` and `/auth/logout` are available until changed. Other protected endpoints return HTTP 409 and `code: PASSWORD_CHANGE_REQUIRED`.
6. Password change revokes **all device tokens**, including the caller. Clear local credentials and log in again with the new password. Reset through either the mobile or existing web reset flow also revokes device tokens. Existing web password changes revoke tokens too.
7. Tokens expire after 30 days. There is no refresh token. On 401, clear the token and return to login. On 403, show access denied; the account may be inactive or the resource belongs to someone else. Logout revokes only the current device token.
8. Use `/me` for profile and membership plans, `/dashboard` for summary and appointments, and the dedicated ledger, challan and notification endpoints for their screens.

### Login example

```bash
curl -X POST http://127.0.0.1:8000/api/mobile/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"login":"member@example.test","password":"YOUR_MEMBER_PASSWORD","device_name":"Android phone"}'
```

Example response, HTTP 200:

```json
{
  "token_type": "Bearer",
  "access_token": "TOKEN_RETURNED_ONCE",
  "expires_at": "2026-11-17T09:00:00.000000Z",
  "must_change_password": false,
  "user": {
    "id": 123,
    "name": "Demo Member",
    "email": "member@example.test",
    "cnic": "9999900000001",
    "phone": "03000000000",
    "member_id": 456,
    "must_change_password": false
  }
}
```

The examples are synthetic and do not identify a working account. Do not use the administrator password from the director brief in the mobile app.

```bash
curl http://127.0.0.1:8000/api/mobile/v1/me \
  -H 'Accept: application/json' -H 'Authorization: Bearer YOUR_ACCESS_TOKEN'
```

## Endpoint reference

All paths below are relative to the base URL. `Token and password changed` means an active member/applicant token with no pending temporary-password change. IDs are internal integers unless described otherwise. Bank transactions use `public_id` UUIDs; appointments use their `public_id`, not database IDs or the display appointment number. Complex detail uses the complex slug.

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/appointment-slots` | Token and password changed | Available appointment slots |
| POST | `/appointments` | Token and password changed | Book an appointment |
| GET | `/appointments/{appointment}` | Token and password changed | Own appointment detail and events |
| POST | `/appointments/{appointment}/cancel` | Token and password changed | Cancel a pending or confirmed appointment |
| POST | `/auth/forgot-password` | Public | Email password reset link |
| POST | `/auth/login` | Public | Issue a member device token |
| POST | `/auth/logout` | Token | Revoke current device token |
| GET | `/auth/me` | Token | Current authenticated identity |
| PUT | `/auth/password` | Token | Change password and revoke all tokens |
| POST | `/auth/register` | Public | Register an unlinked applicant and issue token |
| POST | `/auth/reset-password` | Public | Reset password and revoke all tokens |
| GET | `/bank-transactions/{transaction}` | Token and password changed | Read own stored bank transaction status |
| GET | `/bank-transactions/{transaction}/receipt` | Token and password changed | Read verified payment receipt HTML |
| POST | `/bank-transactions/{transaction}/verify` | Token and password changed | Query bank server and verify payment |
| GET | `/bank/checkout/{transaction}` | Signed URL | Open short-lived signed hosted checkout bridge |
| GET | `/bank/return` | Public | Public static mobile bank return instruction |
| GET | `/challans` | Token and password changed | List own challans |
| GET | `/challans/{challan}` | Token and password changed | Own challan detail and fee items |
| POST | `/challans/{challan}/bank/initiate` | Token and password changed | Initiate hosted BOP sandbox checkout |
| GET | `/challans/{challan}/print` | Token and password changed | Own printable challan HTML |
| GET | `/dashboard` | Token and password changed | Dashboard appointments dues summary and recent notices |
| GET | `/facilities` | Token and password changed | Facilities allowed by active membership plans |
| GET | `/family-members` | Token and password changed | List or create own dependent records |
| POST | `/family-members` | Token and password changed | List or create own dependent records |
| GET | `/ledger` | Token and password changed | Paginated ledger with full-account totals |
| GET | `/me` | Token and password changed | Member profile and memberships |
| PUT | `/me` | Token and password changed | Member profile and memberships |
| GET | `/me/card.pdf` | Token and password changed | Download own membership card PDF |
| GET | `/me/photo` | Token and password changed | Read own private profile photograph |
| GET | `/notifications` | Token and password changed | Paginated account notifications |
| PUT | `/notifications/{notification}/read` | Token and password changed | Mark own notification read |
| GET | `/portal` | Public | Public complexes facilities images and appointment purposes |
| GET | `/sports-complexes/{sportsComplex}` | Public | Public complex detail by slug |

## Request fields and validation

All listed fields are required unless marked optional. Extra fields never grant an admin role or allow linking another member.

| Endpoint | Body or query |
| --- | --- |
| POST `/auth/login` | `login` CNIC or email, `password`, `device_name` up to 100 characters. CNIC formatting is stripped. |
| POST `/auth/register` | `name` up to 255 characters, unique `cnic` 13 digits, unique `email`, Pakistani `phone` in `03XXXXXXXXX` format, `password`, `password_confirmation`, `device_name`. No file/photo upload in this mobile registration endpoint. |
| POST `/auth/forgot-password` | `email`. Always returns the same eligibility-neutral message. Delivery depends on configured mail transport. |
| POST `/auth/reset-password` | `email`, emailed `token`, `password`, `password_confirmation`. Invalid or expired reset returns 422. |
| PUT `/auth/password` | `current_password`, `password`, `password_confirmation`. New password needs 8+ characters with uppercase, lowercase and a digit. |
| PUT `/me` | `name`, unique `email`, `phone` in `03XXXXXXXXX` format. Updates the portal account; it does not amend the imported SAP member master record. |
| GET `/ledger`, GET `/notifications` | Optional `page` (default 1), `per_page` (default 25, clamped to 1–100). |
| POST `/family-members` | `full_name` up to 255 characters, `relationship` up to 80, optional `date_of_birth` not after today, optional `parent_family_member_id` within the same sponsor’s dependents. |
| GET `/appointment-slots` | Query `sports_complex_id`, `date` in `YYYY-MM-DD`, strictly after today. |
| POST `/appointments` | `sports_complex_id`, `appointment_purpose_id`, `appointment_date` in `YYYY-MM-DD`, `appointment_time` in `HH:mm`. Use available slots rather than inventing times. |
| POST `/appointments/{appointment}/cancel` | Optional `reason`, up to 1,000 characters. Only pending or confirmed appointments can be cancelled. |
| POST `/challans/{challan}/bank/initiate` | `initiation_key`: a fresh UUID for a new payment attempt. Persist and reuse it for retries of the same attempt. |
| POST `/bank-transactions/{transaction}/verify` | No body. Bank inquiry is performed on the server. |
| POST `/auth/logout`, PUT `/notifications/{notification}/read` | No body. Marking an already-read notification read is safe to repeat. |

Passwords in registration, reset and change require at least 8 characters, uppercase, lowercase and a digit. No staff-created membership, financial charge or bank status can be written directly by the mobile client.

## Response contracts

JSON responses use the endpoint shapes below; there is no universal `success/data` wrapper. Unknown fields may be added. Keep CNIC/customer numbers as strings, preserve decimal money values and display PKR. Do not infer that imported SAP credits were online bank payments.

| Endpoint | Stable response shape and UI use |
| --- | --- |
| `/portal` | `{complexes: [...], purposes: [...]}`. Complexes include `id`, `name`, `slug`, `code`, address, phone, descriptions, opening hours, facilities and images. Purpose entries provide booking IDs and names. |
| `/sports-complexes/{slug}` | Complex object with facilities, images and `appointment_schedule` when configured. Some image URLs may be relative; resolve against the deployed application root, including `/sports-complex` when present. |
| `/auth/me` | `{user: {id, name, email, cnic, phone, member_id, must_change_password}}`. |
| GET or PUT `/me` | `{user: {...}, member: object or null}`. Member includes `id`, `customer_no`, `membership_no`, `full_name`, `status`, `sports_complex`, `memberships` and `profile_image_url`. Memberships include `status`, `starts_on`, nullable `expires_on`, and `plan`. Use `/me/photo` with bearer authentication to retrieve the photograph. |
| `/dashboard` | `user`, `sports_complex`, `upcoming`, `appointments`, `notifications`, `unpaid_challans: {count, amount}`, `account_summary: {source, transaction_count, total_charged, total_paid, balance}`. `upcoming` can be null. Display existing workflow statuses; do not treat a booked appointment as membership approval. |
| `/ledger` | `member`, `source` when linked, `summary: {total_charged, total_paid, balance}`, `transactions: [...]`, `meta: {current_page, per_page, total, last_page}`. Rows include `key`, `date`, `type`, `reference`, `description`, `status`, `debit`, `credit`, `balance`. SAP rows may also include document type, clearing document and `date_is_anomalous`. Totals and running balances cover the full account, not just the requested page. The current implementation computes the source ledger before slicing it into pages. |
| `/challans` | `{data: [...]}` with `id`, `challan_no`, `purpose`, `amount`, `issued_on`, `due_on`, `status`, `sap_status`, `sap_document_no`. All own challans, newest first; this endpoint is not paginated. |
| `/challans/{id}` | Challan financial fields plus `previous_outstanding`, `current_amount`, `items: [{id, description, amount, gl_account}]` and authenticated `print_url`. Internal SAP request/response payloads are not returned. |
| `/facilities` | `{data: [...]}`. Requires an active dated membership and filters to the linked complex and eligible membership plans. A facility with no assigned plans is available to every active plan at that complex. Maintenance/closed facilities may still be shown with their operational status and maintenance notice. This is discovery/access information, not a facility booking reservation. |
| `/notifications` | Laravel paginator: `data`, `current_page`, `per_page`, `total`, `last_page`, `next_page_url`, etc. Entries include `id`, `type`, `title`, `message`, nullable `read_at`, and optional action URL. Resolve web action paths to native app screens. Dashboard maintenance notices are informational and do not have a notification database ID to mark read. |
| `/family-members` | GET returns an array. POST returns the created dependent object with HTTP 201. No family editing/deleting or native family-card download endpoint in this release. |
| `/appointment-slots` | `{slots: [...]}`. Use returned slot time and availability/capacity. Availability can change before submission; handle 409/422 and reload. |
| POST `/appointments` | Created appointment object, HTTP 201, including `public_id`, `appointment_no`, date/time and status. |
| `/appointments/{public_id}` | Own appointment with `sports_complex`, `purpose`, `events`; cancellation returns the updated appointment. Dashboard is the appointment list in this API version. |
| Bank initiation/status/verify | `public_id`, `challan_id`, `order_id`, `status`, `amount`, `currency`, `environment`, `bank_transaction_reference`, `failure_message`, `verified_at`, nullable `checkout_url` and `receipt_url`. No bank credential, success indicator or raw gateway session ID is exposed in JSON. |

An applicant with no linked member receives `member: null`, empty finance/facility arrays and zero totals. A membership card or photo requires a linked record and returns 404 when absent. Notification and ledger lists have different documented pagination shapes; do not assume one parser fits both.

### Errors and limits

| HTTP | Meaning and expected app action |
| --- | --- |
| 200 | Successful read/update/login or bank inquiry. A 200 bank response does not imply payment success; inspect `status`. |
| 201 | Applicant/dependent/appointment created. |
| 204 | Current device logout succeeded; no response body. |
| 401 | Missing, expired or revoked token. Sign in again. |
| 403 | Wrong owner, inactive user, wrong role/ability, or invalid/expired checkout URL. |
| 404 | Resource, linked member, photo or verified receipt unavailable. |
| 409 | Temporary password change required or workflow conflict. Inspect `code` when present. |
| 422 | Validation error. Display field-specific messages from `errors`. |
| 429 | Rate limit; respect `Retry-After` and back off. |
| 500/503 | Unexpected server/integration issue or service unavailable. Preserve the initiation key and transaction ID; do not claim success or repeatedly start new payment attempts. |

```json
{"message":"The given data was invalid.","errors":{"login":["The supplied member credentials are not valid."]}}
```

```json
{"message":"Change the temporary password before using the member portal.","code":"PASSWORD_CHANGE_REQUIRED"}
```

Limits: all mobile requests 120/minute; public authentication operations share 5/minute; password change/payment initiation share an additional 5/minute authenticated write bucket; bank verification 10/minute. Limits apply by authenticated user or IP as appropriate. Numeric middleware buckets are separated so the overall limit does not double-count against the authentication limit.

## Cards documents and photographs

`GET /me/card.pdf` returns `application/pdf`. Download it using a bearer-authorised HTTP client, save to an app-private file and then open/share that file. Do not send the bearer token in a URL.

`GET /me/photo` returns private image bytes, normally WebP. Photo replacement remains administrator-controlled. Absence returns 404; render a placeholder.

`GET /challans/{id}/print` and `/bank-transactions/{uuid}/receipt` return HTML, not JSON or PDF. Fetch them with the bearer header and render the response in a controlled document viewer or print workflow. A PDF conversion may be performed on the device. The receipt is available only after verified bank payment. Returned HTML contains existing web navigation links; native navigation should use the app’s authenticated endpoints instead.

Financial documents must be treated as private. Private API responses use `Cache-Control: no-store`; clear temporary downloaded files when appropriate.

## Bank sandbox integration

1. Display Pay only for payable challans. Call `/challans/{id}/bank/initiate` with a persisted UUID `initiation_key`.
2. Check returned `status`. If `session_created` or `pending` and `checkout_url` is present, open the **exact URL** in the system browser/custom tab. It is a signed checkout capability valid for 10 minutes and does not need the account bearer token or a browser session. Do not log or forward it. Obtain another checkout URL through authenticated transaction status if the link expires.
3. Card entry remains on Mastercard Hosted Checkout. The mobile app never collects or stores card numbers or bank API credentials.
4. Newly created mobile sessions return to the static `/bank/return` instruction page. When the user returns to the app, or explicitly taps Verify, call `/bank-transactions/{uuid}/verify` with the member token. A pre-existing browser-created session may return to its original web login URL; the mobile verification endpoint still works independently.
5. Never accept the browser redirect or `resultIndicator` as payment proof. The existing service accepts payment only after server inquiry confirms captured status and matching order, amount and PKR currency.
6. Only `status: verified` unlocks the receipt and confirms the challan’s paid status. `pending` and `verification_required` remain unresolved; show an appropriate message and retry verification with backoff. A timeout is not proof of failure or success.
7. Verification is idempotent. The client cannot mark a challan paid. The existing bank service and its transaction audit records are reused.

The bank implementation is **test environment only**. Automated bank tests mock gateway responses. Real card completion, production onboarding, bank acceptance, refunds/reversals and settlement reconciliation require separate work and verification. No webhook contract or push payment completion is introduced. Do not show test collections as production revenue.

## Flutter and React Native implementation notes

Use a single configurable base URL. For Android local development, use emulator host `10.0.2.2`; a physical phone cannot reach the developer computer at its own `localhost`. Keep HTTP exceptions limited to development builds; production must use HTTPS.

Example JavaScript request helper (works with React Native fetch):

```javascript
const baseUrl = 'https://YOUR_APPROVED_HOST/sports-complex/api/mobile/v1';
async function api(path, { token, method = 'GET', body } = {}) {
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    },
    ...(body ? { body: JSON.stringify(body) } : {})
  });
  if (response.status === 204) return null;
  const payload = await response.json();
  if (!response.ok) throw { status: response.status, ...payload };
  return payload;
}
```

Use a separate binary/text downloader for PDF, images and HTML. On `PASSWORD_CHANGE_REQUIRED`, navigate to password change. On 401, remove secure storage credentials. Do not retry appointment creation automatically after an uncertain network result without checking the dashboard for an existing booking. For bank initiation, retry with the same UUID and inspect the existing transaction.

Reset emails use the existing web reset page. A mobile app can accept a user-entered emailed reset token and email through `/auth/reset-password`, but verified app links/deep links and push notification tokens are not configured in this release. The currently checked application default in `config/app.php` is UTC. Confirm the required deployment timezone with operations; appointment dates and times use that server timezone. ISO timestamps can be displayed in Asia/Karachi as needed.

## Backend installation and deployment handoff

Deployment has been completed for the release above. The instructions below also support future installations. The source implementation is in `routes/api.php`, `app/Http/Controllers/Mobile`, `app/Http/Middleware/EnsureMobileToken.php`, with Sanctum in Composer and one new personal-access-token migration. The existing web login/session routes remain in place.

Local setup:

```bash
composer install
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8000
php artisan route:list --path=api/mobile
php artisan test --compact
```

Provision existing member logins with the existing staff provisioning workflow; do not ship a shared default member password in the app. Use synthetic test accounts in a development database.

For deployment, back up the application and database first, upload the reviewed source and Composer lock file, install locked production dependencies, then run the new migration before serving requests. Set the approved HTTPS `APP_URL`, preserve secrets and private storage, clear/rebuild configuration and route caches, and ensure the reverse proxy forwards the `Authorization` header to PHP (for Nginx/FastCGI, verify `fastcgi_param HTTP_AUTHORIZATION $http_authorization`). Do not run the main seeder in production because it defines a default administrator password.

Existing password change/reset paths now revoke Sanctum tokens. Therefore the personal-access-token migration is required before this source is used. No new application database tables other than Sanctum’s token table are required. Schedule `sanctum:prune-expired` through the server’s existing scheduler/maintenance process if desired; expired tokens are rejected even before physical pruning.

Acceptance checks before giving a live base URL to the mobile team:

- Public discovery responds and URLs resolve under the deployed subdirectory.
- Synthetic member login, mandatory password change, logout and expired-token rejection work over HTTPS.
- A member cannot retrieve another member’s challan, appointment, dependent, notification or bank transaction.
- Member cards/photos and financial HTML download with bearer headers; browser cookie-only requests cannot access mobile endpoints.
- Bank sandbox checkout opens without session cookies; captured inquiry updates one payment once, while pending/mismatch stays unpaid.
- Existing staff/member web workflows still pass regression tests.

The deployment preserved live data, uploaded files, configuration and administrator credentials. It did not provision real member accounts or perform live SAP/bank transactions. The temporary synthetic smoke-test account and its tokens were removed. Live SQLite directory ownership was corrected so PHP can create journals and write rate-limit cache entries.
