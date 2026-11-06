# SAP challan PHP proxy replacement

Replace the existing `sapchallan/index.php` on the PHP proxy host with the adjacent `index.php`, after backing up the current file. This is a standalone PHP 5.5+ script requiring cURL; it does not require Laravel. It has not been uploaded to the public/internal proxy servers.

Required GET parameters: `fname`, `lname`, `address`, `phone`, `cnic`, `amount`, `desc`, `area`, `type`, `glSAP`, `reacon`, `center`, `pcenter`, `file`.

All accounting values and the file number come from the request. There are no ballot/application defaults. Existing callers must now provide these fields; omissions return HTTP 422 rather than posting to the wrong account. Comma-separated amount/description/GL lines must align. Descriptions keep their spaces, query values are decoded once by PHP, and RFC3986 encoding preserves literal plus signs and leading zeros.

The upstream URL remains fixed at `http://172.16.70.8:8080/SAPVERIFICATION/Challan`. Success retains the existing plain-text document-number response. This contract accepts 10-digit SAP document numbers or 18-digit combined identifiers. Invalid upstream JSON, errors, redirects, HTTP failures and timeouts return HTTP 502 with an error message and `sap_url` containing the exact encoded internal SAP request URL, as requested for Invalid Data diagnostics. Errors before valid parameters exist have no URL. Treat these error responses as private because the URL contains applicant data. No automatic retries are performed.

The Laravel consumer was updated to accept the plain numeric response. Its existing handling marks upstream 5xx or ambiguous success as verification_required and blocks another creation until reconciled. The creation endpoint/fallback configuration and this consumer change must be released with the proxy; they remain local in this handoff.

Validation: `php -l integrations/sapchallan/index.php` and `php artisan test --compact`. Unit tests include this file without running its HTTP entry point. No live challan was created during testing.

Retain the host's existing access restrictions. This script does not introduce authentication or establish the caller's entitlement to a particular accounting code. Do not test connectivity by opening a complete creation URL: a valid GET issues a challan.
