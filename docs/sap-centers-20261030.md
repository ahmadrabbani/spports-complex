# SAP center configuration on 30 October 2026

Released live as `sports-centers-20261030T0230PKT` at https://104.207.64.25/sports-complex/.

The supplied image distinguishes eight-digit cost centers from seven-digit profit centers. The nine single-center complexes now use the supplied values, retaining the existing SAP string widths of 10 cost-center digits and 9 profit-center digits through leading zeros. For example, CW uses cost `0021070001` and profit `002107001`. Complex names and existing records were retained.

Johar Town has four allowed pairs, stored on the complex as `sap_center_options`. No default is guessed:

| Selection | Cost center | Profit center |
|---|---|---|
| JT-1 | 0021010001 | 002101001 |
| JT-2 | 0021010002 | 002101002 |
| JT-3 | 0021010003 | 002101003 |
| JT-4 | 0021010004 | 002101004 |

In SAP Challans, select Generate challan, choose the member, and choose the cost/profit pair for Johar Town. Select fees and preview before generation. The chosen pair is revalidated server-side against the member's complex, applied only to that request, and saved in challan-item and SAP-payload snapshots. Missing/foreign/unknown choices are rejected; a Johar Town option cannot be submitted for another complex. Changing the pair invalidates the UI preview. The complex defaults remain unset for Johar Town, requiring an explicit selection. No facility names or automatic facility assignments were invented.

Migration `2026_10_30_000001_configure_sap_center_pairs` adds the options column and applies the approved mappings. Fresh-install seed configuration was updated, but the broad DatabaseSeeder was not executed on production. Existing challans, items, charges and member records were not rewritten.

Validation: 136 tests and 1,033 assertions passed; all four Johar Town pairs were generated with mocked SAP isolation and their saved payload/item snapshots checked. Missing/foreign choices and unchanged defaults were tested. Vite build and targeted Pint passed. Browser QA preview displayed JT-3 cost 0021010003 and profit 002101003 using a synthetic member in the isolated database; no challan was issued for screenshots. Live server preview code checked all 13 configurations (nine defaults and four choices) without outbound SAP requests. HTTPS homepage and compiled JS returned 200; deployed source and JS checksums matched. Production migration is applied, caches refreshed, PHP FPM active and maintenance off.

Pre-release backup: `lda-web:/var/backups/sports-centers-20261030T0230PKT/` contains application/config/private-storage archive, SQLite backup, checksums and before/after data snapshots. Backup checksum and integrity checks passed; original member and financial rows matched. Restore the application archive and SQLite/config backup together if rollback is required, preserving any subsequent live writes first. No live SAP issuance or settlement was performed.

Evidence: `output/sap-centers-20261030/` contains the verified center mapping CSV/JSON and the clearly identified QA selector and preview screenshots. Prior five-page management report describes the earlier release; this note records the subsequent center update.
