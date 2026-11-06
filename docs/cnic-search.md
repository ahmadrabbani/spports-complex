# CNIC search correction - 29 October 2026

The customer directory previously compared the entered text directly with `members.cnic`. Existing CNICs are stored without dashes, so formatted input such as `35202-0000000-1` did not match `3520200000001`. A read-only check against a valid existing local record reproduced zero matches with formatting and one without; after the fix both returned the same one record. No real CNIC was logged in validation output.

The shared `CnicSearch` helper removes supported spaces/dashes from the query and from stored values during matching. Full 13-digit CNICs use exact normalized matching; partial digit searches remain available. Letters in a name/customer-number search are not stripped to create an unrelated numeric identity search. Existing stored identifiers, customer data and accounting records are not rewritten. No migration or backfill is required.

The shared member search is used by the directory, challan-generation member lookup, challan list and bank transaction search. Appointment search applies the same helper to its linked user's CNIC. Name, customer number, membership number and existing phone search remain available. Search predicates remain grouped underneath the authenticated complex scope.

The directory and challan member lookup cancel obsolete requests; older responses cannot overwrite a newer search. The directory shows searching, no-results and error states. Member search responses use `Cache-Control: no-store`.

Validation: 132 tests / 932 assertions passed, including formatted/raw inputs and storage, whitespace and copied Unicode dashes, partial CNICs, exact full identity matching, foreign-complex exclusion, existing text/identifier searches, bank/appointment/challan integration and invalid query handling. Vite production build passed with the existing bundle-size advisory. Browser QA uses synthetic records in an isolated database; the same synthetic CNIC in another complex remains excluded.

Files: `app/Support/CnicSearch.php`, `app/Models/Member.php`, member/challan/bank/admin-appointment controllers, `resources/js/app.jsx`, `resources/js/SapChallans.jsx`, `tests/Feature/CnicSearchTest.php`, and compiled Vite assets.

Deployed on 30 October 2026; live authenticated HTTP confirmed formatted/raw CNIC equivalence. No real SAP request was performed. See [deployment evidence](deployment-20261030.md). Customers with a missing or incorrect stored CNIC still require record correction from verified source information; search does not invent or repair identities.

Browser verification: formatted `35202-0000000-1` returned the expected synthetic Canal Bank customer while another complex's customer with the same synthetic CNIC was excluded. [Screenshot](screenshots/cnic-search.png). A read-only existing-record check also confirmed digits-only, dashed and space-separated inputs return the same record IDs. No migration, account or financial data change was made.
