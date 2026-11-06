# Optional accounting defaults — 4 November 2026

Standalone replacement for the user's supplied PHP 5.5 proxy. Install index.php on the SAP proxy host after backing up the current sapchallan/index.php. This variant is separate from the strict sports-only proxy and does not change the Laravel application configuration.

For GET requests, omission uses the existing defaults:

- company_code: 1000
- glSAP: 0000610338
- reacon: 260100
- center: 0011050015
- pcenter: 001105001
- file: DAM-BALLOT-APPL-2024
- baseline: 2026-11-18 (unchanged from supplied file)

A supplied parameter overrides only its own default. Values remain strings; leading zeros are preserved. Explicitly empty/array/malformed accounting overrides are rejected, rather than silently posting to the default account. Multi-fee amounts must have corresponding GL codes. The original applicant parameters, debug output, JSON/XML response parsing and internal URL on SAP failure are retained. Success returns the document number only. No retry is performed.

company_code is forwarded to the Java servlet as a query parameter. The Java/SAP integration must separately support it and use the selected company in customer creation/extension and all SOAP CompCode fields. This PHP file cannot alter the servlet's hardcoded company.

Syntax checked with installed PHP; four focused tests cover defaults, overrides/encoding, invalid arrays and explicit empty values. PHP 5.5 runtime and actual SAP posting were not executed. The file is not deployed to the proxy host. Error/debug URLs contain applicant information: retain existing proxy access restrictions.
