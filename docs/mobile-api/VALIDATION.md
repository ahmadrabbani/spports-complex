# Validation record

18 October 2026

- Full Laravel suite: 63 tests passed, 397 assertions.
- Mobile security and integration coverage includes token login, expiry, logout, password changes and resets, registration restrictions, staff rejection, inactive accounts, ownership restrictions, facility plans, notification ownership, signed checkout expiry and mock bank verification.
- OpenAPI 3.0.3 specification validated with openapi-spec-validator.
- OpenAPI and Postman collection each contain 33 operations.
- Sanctum migration applied locally after SQLite backup and backup integrity check.
- Deployment completed as `sports-mobile-api-20261018T121835Z`. Live synthetic token login, temporary-password gate, password change, token revocation, profile/dashboard/ledger/notification/challan/facility reads, staff API rejection and logout passed. Temporary account and tokens removed.
- Live data preserved; database integrity and pre/post member, account and financial record counts checked.
- No real member provisioning, real SAP posting, real card payment or bank settlement was performed.
- HTTPS enabled on 22 October 2026. Trusted IP SAN certificate, TLS 1.3, secure session cookies, path/query-preserving HTTP 308 redirects, external synthetic bearer login/read/logout/revocation checks, and simulated renewal with the Nginx deploy hook passed. Certificate renewal timer checks every six hours.
