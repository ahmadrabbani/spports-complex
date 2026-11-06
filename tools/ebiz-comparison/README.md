# Oracle 11g / eBiz comparison API

Standalone PHP 5.5-compatible source; requires OCI8 and cURL with TLS certificate verification. Tested locally on PHP 8.2; actual PHP 5.5, Oracle and live eBiz access have not been verified. No Laravel dependency or schema migration. No writes to either system; login creates an eBiz authentication session.

## Install

1. Deploy this directory on your PHP/OCI8 server behind HTTPS. Make `compare.php` and `browser.php` web accessible. Keep configuration and tests outside the web root where possible.
2. Copy `config.example.php` to `config.php` beside `compare.php`. Alternatively keep the file outside the web root and set server environment `EBIZ_COMPARE_CONFIG` to its absolute path. A config.php beside the scripts takes precedence. EBIZ_COMPARE_CONFIG is used only when that local file does not exist. The file must return the configuration array shown in the template. Set `COMPARE_API_KEY` to a new random secret, and set `EBIZ_USERNAME`, `EBIZ_PASSWORD`, `ORACLE_USER`, `ORACLE_PASSWORD`, `ORACLE_DSN` in the PHP server environment. Alternatively enter these values in that private config file. Never put credentials in query strings.
3. Use an Oracle user with SELECT permission on LRS.PLOT_CASE. The endpoint also starts an Oracle read-only transaction.
4. Inspect the login JSON privately: default `token_path` is `data.token`. If the response uses `token`, `access_token`, or `data.access_token`, set that exact dot path. The applications request assumes `Authorization: Bearer <token>`; adjust ebiz_request if the login contract requires another scheme. No login response was supplied, so this part is unverified.
5. Allow enough PHP/FastCGI/proxy time and memory for all pages (sample: 129 pages / 12,869 records). No partial reports are returned. A file lock prevents simultaneous full scans in this deployment. Token expiry, HTTP errors, malformed pagination or page limits fail the run; retry after correcting the cause. This synchronous implementation may need a background job for restrictive hosting timeouts.

## Call

```sh
curl --fail-with-body --max-time 1800 \
  -H "X-Comparison-Key: $COMPARE_API_KEY" \
  'https://YOUR-SERVER/ebiz-comparison/compare.php' \
  --output comparison.json
```

GET only. Returns JSON with summary counts and issue records carrying raw eBiz and Oracle values. A 401 means invalid caller key; 409 means another run is active; 502 means the comparison could not complete. Reports contain application identifiers: restrict access and store securely. Credentials are never returned. Rotate the password shared in chat before production use.

## Confirmed match and status rules

Exact case-sensitive match after trimming leading/trailing whitespace: eBiz `application_number` to Oracle `LRS.PLOT_CASE.OWC_NO`. Numeric-looking identifiers stay strings; no substring, case-folding or public/private eBiz number matching.

| eBiz ID / status | Accepted CASE_PROGRESS_ID |
| --- | --- |
| 4 In Progress | 1 In Process |
| 6 Resubmitted | 1 In Process |
| 5 Observation | 4 Objection |
| 7 Approved | 3 Approved, 5 Complete - Approved |
| 11 Rejected | 6 Complete - Disapproved |

All other eBiz statuses (including Submitted, Closed, Payment Required and Delayed) are `unmapped_status` when a local match exists. Pending (Oracle 2) has no confirmed eBiz equivalent. Edit the private config's status_map only when business rules are confirmed. Null/blank local status is a mismatch for mapped eBiz statuses.

## Issue types and limits

- `status_mismatch`: unique match with a different local status; includes expected local status IDs.
- `unmapped_status`: unique match requiring a business mapping decision.
- `missing_in_oracle`: application number absent from Oracle results.
- `ebiz_missing_application_number`: null/blank application number, including draft/payment-pending records; cannot be matched and does not establish a sync failure.
- `ambiguous_duplicate`: repeated application number in either system. All candidates are included; no arbitrary match is chosen.
- `local_missing_application_number`: local record without OWC_NO, when using an explicit local scope.
- `absent_from_api_scope` / `local_duplicate_absent_from_api`: enabled only with local_scope_sql. Absence from this API account's visible population is a candidate for investigation, not proof of deletion or failure.

By default only Oracle records matching API application numbers are loaded, using bound string parameters in batches of 500 (below Oracle 11g's IN-list limit). This supports API-to-Oracle checks without scanning unrelated historical Oracle cases. `local_records_loaded` describes that subset.

For bidirectional comparison, set `local_scope_sql` in the private config to a reviewed SELECT returning CASE_ID, OWC_NO, CASE_PROGRESS_ID, EBIZ_ID for exactly the Oracle population that should be visible to this eBiz account. No safe eBiz-only population filter was supplied; the default intentionally leaves reverse comparison disabled. Use server-side SQL, never caller-supplied SQL.

The endpoint follows changing pagination totals and finishes at the latest reported final page within max_pages. If totals change, download.population_changed is true, warnings mark results as provisional, and reverse missing-in-eBiz checks are disabled. Page-size changes, wrong page numbers, repeated pages and incomplete counts with stable totals still fail the run. These checks cannot guarantee a point-in-time API snapshot while upstream records change. Oracle reads use a single consistent read-only transaction after API download; timestamps show the comparison window. A mismatch does not identify which system is authoritative. Only identifiers and status are compared: the supplied payload does not establish equivalent local timing/applicant fields.

## Local verification

```sh
php test.php
php -l compare.php
```

Tests cover mappings (including both approved states), status mismatch, unmapped statuses, missing identifiers/records, duplicates on both sides, scoped reverse checks, all-page fetching and failure on repeated pages, incomplete totals, changed totals in strict mode, wrong page numbers and page limits; tolerant-mode tests cover growing populations and drift metadata. These are synthetic tests, not live Oracle/eBiz verification.

## Open from WhatsApp or a browser

Deploy `browser.php` and `config-loader.php` beside `compare.php` and `compare-lib.php`, then open:

`https://YOUR-SERVER/ebiz-comparison/browser.php`

Enter the exact configured `api_key` in the sign-in form. If the configuration uses double Base64 encoding, the key is the double-encoded result; a strong random key stored directly is simpler. Do not use the Oracle or eBiz password to sign in here. Click **Run comparison and download** to receive the JSON report. Some WhatsApp embedded browsers may require opening the link in the device browser for file downloads.

The form uses PHP sessions, a regenerated session ID on login, POST requests with CSRF tokens, a 30-minute idle expiry and HttpOnly cookies. OpenSSL is required for secure CSRF randomness on PHP 5.5. Serve over HTTPS; if TLS terminates at a proxy, configure the server HTTPS indicator so secure session cookies are enabled. Configure web-server rate limits or an IP allowlist for public deployments. Sign out after use. The existing header-based comparison endpoint still works. Configured key changes are checked again when the comparison runs. Handled comparison failures in the browser flow appear as an HTML error page with no report download. The browser error page uses HTTP 200 to avoid generic browser/proxy error replacements; it explicitly says no complete report was produced. Direct compare.php calls retain JSON errors and their HTTP status codes. PHP crashes and proxy timeouts still require server logs. Connection errors include the cURL numeric code and indicate whether login or application download failed.

Browser-flow checks on PHP 8.2 passed for login form, wrong key, successful login, CSRF rejection, logout and idle expiry. Actual PHP 5.5 execution, phone-browser downloads and live Oracle/eBiz calls remain unverified.

## Web results view

Upload report-view.php along with all runtime PHP files, retaining your private config.php. Run comparison in browser.php to view summary cards, warnings, searchable issue table, issue-type filter, and 50-row pagination. Download JSON exports the displayed report without a second API scan. Direct compare.php calls continue returning JSON. The browser view uses JavaScript; no report is persisted server-side. Server rendering and embedded JavaScript syntax were checked locally; live phone/desktop interaction remains unverified.

## eBiz private-number check

After matching application_number to OWC_NO, compare ebiz_private_number to PLOT_CASE.EBIZ_ID as strings with outer whitespace trimmed. A difference (including a blank Oracle ID) produces ebiz_id_mismatch independently of status_mismatch; one application can produce both issues. The web table shows Oracle EBIZ_ID beside the eBiz private number. No ID is updated, split, truncated, or concatenated. Custom local_scope_sql must now select EBIZ_ID too. Matching records count only uniquely matched applications with accepted status and matching private number.

## Draft exclusion

eBiz Draft rows (status_id 1, numeric or string) are excluded after all pages are downloaded and checked, before Oracle matching. The web report and JSON summary show drafts_skipped; api_records counts only remaining rows, while download.downloaded_rows counts all downloaded rows. Oracle rows whose OWC_NO appears only on draft applications are excluded from scoped reverse checks. If an application number appears on both draft and non-draft rows, the non-draft row is compared. Other statuses remain included.
