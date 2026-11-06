# Membership reconciliation and controlled challan adjustments

Implemented locally on 29 October 2026 in the existing Laravel / React membership and challan modules. Deployed on 30 October 2026 with safe production backfill and retained financial values. No real SAP mutation was performed for release verification. See [deployment evidence](deployment-20261030.md).

## Accounting model

REACON is the SAP customer reconciliation account, distinct from fee GL accounts. Fee GLs and complex cost/profit centers continue to come from the existing configuration. The legacy fee-type REACON field is retained for historical compatibility but no longer overrides member categories on new challans.

| REACON | Official category |
|---|---|
| 260108 | General Public Family Membership |
| 260110 | General Public Individual Membership |
| 260111 | Freeze Customers |
| 260112 | Govt Officers Family Membership |
| 260114 | Member's Children Membership |
| 260115 | Govt Officer Individual Membership |
| 260116 | Femergy Membership |
| 260117 | Platinum Membership |
| 260118 | Student Membership |
| 260119 | Guest User |
| 260120 | LDA Employee |

`ReconciliationAccountSeeder` inserts missing official accounts/categories without resetting administrator edits. Account codes are unique strings and are immutable through the update API. Accounts have long/short descriptions, active state and timestamps. Categories reference accounts; regular categories cannot map to 260111. Freeze billing always resolves the active 260111 master entry.

Unscoped administrators can change a regular category's account in **Reconciliation accounts**. Existing non-frozen members assigned to that category are synchronized and individually audited. Frozen original accounts and all financial snapshots remain unchanged. If a category mapping changes while a member is frozen, unfreezing requires resolving that conflict before restoring the saved account.

Generic existing plans (`Individual`, `Family`, `Annual`) are intentionally not automatically mapped: these names do not establish General Public versus Govt Officer or other categories. Administrators can explicitly map plans in the same screen. Assigning a mapped plan assigns the member category. Unmapped new members may be saved for review but cannot generate SAP challans without a known active account.

## Migration and local data evidence

Migrations:

- `2026_10_29_000001_add_membership_reconciliation_workflows`: accounts/categories, member/plan relationships, frozen statuses, account audits, freeze history, fee configuration, approvals, challan category/status snapshots and adjustment history.
- `2026_10_29_000002_add_challan_billing_period`: monthly fee period and unique member/fee/period/environment-source constraint.
- `2026_10_29_000003_index_reconciliation_filters`: member category and challan category/account/status indexes.

The existing database was backed up using the SQLite backup API to `storage/app/backups/reacon-20261029/database.sqlite` before migrations. This contains private records: keep it private and do not publish it.

```sh
php artisan migrate --force
php artisan members:backfill-reconciliation --report=storage/app/backups/reacon-20261029/review-dry-run.csv
php artisan members:backfill-reconciliation --apply --report=storage/app/backups/reacon-20261029/review-after-backfill.csv
php artisan members:backfill-reconciliation --apply
```

The default backfill is a dry run. It maps only one unambiguous active category matching an existing SAP account, or an explicit valid category; conflicts with mapped active/pending plans are reported. It never infers a category from a generic name or guesses the original category for a frozen customer. CSV reports contain only member IDs, existing accounts and review reasons and are written with mode 0600. Soft-deleted members are excluded.

Local execution results:

- First apply: **10,111 mapped, 0 unchanged, 76 require review**.
- Second apply: **0 mapped, 10,111 unchanged, 76 require review**; no extra backfill audits.
- Review: **75 legacy 260111 customers** lack verified original category/freeze history; **one 260000 customer** has an unknown account. Their original fields remain unchanged.
- Comparison against the backup confirmed all original columns and row counts unchanged in: challans (1), challan items (1), payments (0), member charges (2,262), SAP member ledger transactions (331,307), bank transactions (1).
- SQLite `integrity_check=ok`, no foreign-key errors.
- All three new migrations were rolled back and reapplied successfully on an isolated SQLite database. A production rollback should restore a coordinated database/code backup; dropping these tables after use would discard new audit/history data. Status columns deliberately remain widened on rollback.

## Freeze and unfreeze workflow

Only an administrator within the member's complex scope can freeze/unfreeze, approve a freeze fee, or change a member category. Staff can read reconciliation configuration and scoped member/challan history.

**Member profile → Membership category & reconciliation** shows category, original/effective REACON, status, controls, freeze history, fee approvals and account audits. Every action requires a reason.

Freezing locks the member locally, snapshots its category/account/status and membership statuses, records start/end/reason/actor, marks current active/pending memberships frozen and uses effective 260111. A supplied start must be today or earlier; an optional end date is recorded but does not automatically unfreeze or extend membership expiry. Regular fee generation and membership changes are blocked. Member portal/mobile facilities deny access, and membership cards show the frozen status. Legacy 260111 customers are blocked from billing and facility benefits until reviewed even if an older imported status says active.

For a legacy 260111 record, an administrator must select a **verified original category**, enter a reason and record the freeze. The `original_membership_category_id` parameter is accepted only for this legacy review path. No original category is inferred. The administrator should supply the verified freeze start date via API when it predates today; the UI's “Freeze membership now” records today's operational date.

Unfreezing restores the saved category/account and eligible membership states. Expired memberships stay expired; a member whose previously active memberships have all expired is restored inactive. Existing inactive/suspended member status is preserved. Freeze history remains available. Existing payments and SAP accounting records are not reversed, cancelled or edited.

## Freeze fee configuration and approval

No freeze fee amount or new GL is invented or seeded. An administrator must configure an existing validated fee type with `is_freeze_fee=true` and a positive `freeze_amount`; this dedicates that fee type to freeze billing and removes it from regular member fee selection. Choose the appropriate fee GL with the accounting team before changing this setting. If a separate fee type is required, provision it using the existing accounting administration process before enabling freeze billing.

Then approve that configured fee on the frozen member with a reason. Approval records its amount/actor and is usable once. On generation, the fee ID, amount, member, open freeze and unused approval must all match. The generated challan atomically consumes the approval. Reusing the same generation key returns the original challan; using another key with an already consumed approval fails. Frozen billing does not carry regular outstanding charges and uses 260111 exclusively.

## SAP generation and concurrency

New charges resolve REACON from the member category and current status, checking master/category activation before submission. Existing unmapped legacy members with a known regular account remain compatible pending explicit backfill. Unknown accounts and legacy frozen records fail closed.

The existing `fname`, `lname`, `address`, `phone`, `cnic`, aligned `amount`/`desc`/`glSAP` arrays, `area`, `type`, `reacon`, `center`, `pcenter` and `file` contract remains intact. GLs, area/type and centers retain the existing configuration; no SAP customer-master update is attempted. New challans snapshot category ID/name, member status, REACON, item accounting, fee amounts, original SAP document, response and local/SAP statuses. Printing uses saved item centers and saved category/account.

A shared-cache lock serializes generation per member across the HTTP operation; local row locks reserve charges and approvals. No application database transaction is held across SAP HTTP requests. Use a cache backend shared by all application workers (the configured database/Redis store), and ensure lock lifetime exceeds SAP request timeout. Generation keys remain unique. Monthly fees (`MONTHLY_FEE`) use the due date's `YYYY-MM` as billing period and a database uniqueness constraint. Test/live charge source types remain separate.

Charges already attached to a live SAP request are never automatically carried into another challan, including uncertain requests: without confirmed cancellation this could double-bill the member. Unbilled imported outstanding charges retain their original GL/REACON; an account mismatch or inactive/changed historical fee GL requires manual review rather than rewriting history. A live printable challan is not locally superseded merely because a new one is generated.

Network timeouts, ambiguous success and server errors require SAP verification. Automatic SAP creation retries are disabled because the supplied endpoint has no documented idempotency/no-post guarantee. In-progress or uncertain requests block further generation for that member until reconciled. No manual “force retry” bypass is added.

## Adjustments and payment verification

**Challans → Adjust challan / History** requires an administrator and a reason. Staff can view adjustment history. Filters include saved account/category, frozen-at-generation, and payment/local status.

- A true draft (`status=draft`, SAP draft/not_sent, no document, no paid evidence) can update its complete unposted, unshared item breakdown and resolved account. Both canonical charges and draft totals are recalculated in a transaction, with original/proposed snapshots retained.
- A generated/unpaid/uncertain live challan records `manual_sap_reconciliation`. Its original amount/account/document/payment status stay unchanged. The local cancel endpoint refuses to pretend SAP cancelled it.
- A paid challan records `reversal_required`; no amount/account/document/payment change is made. No automatic replacement is generated.

Adjustment records retain original challan, optional replacement ID, old/new accounts and amounts, reason, actor, timestamp, original/proposed item snapshots and explicit SAP support/acknowledgement state. The replacement ID remains empty until an actual supported SAP workflow exists; no acknowledgement is fabricated.

Payment verification uses only that challan's stored/derived 18-digit SAP verification number. It never transfers payment to a replacement. Cancelled/superseded challans cannot settle a replacement. A per-challan shared-cache lock and 30-second persisted-result cooldown deduplicate rapid checks; after cooldown a read-only inquiry may be repeated after timeout. Payment history responses remain administrator/scoped; raw SAP verification responses are encrypted at rest. Public/default challan JSON excludes raw request/response data. Error logs contain IDs/status codes, not CNICs, phones or SAP response bodies.

**Upstream boundary:** The supplied PaymentVerification response contract is still undocumented. Unknown/blank responses remain `verification_unknown`; errors/timeouts remain `verification_error`. HTTP 200 alone never marks paid or posts a payment. Existing MPGS verification retains order/amount/currency checks and idempotent payment posting. Mock generation/payment remains isolated. Real SAP paid/unpaid synchronization cannot be enabled safely until authoritative response examples, field semantics and matching rules are supplied.

## Staff API additions

Global account/category/fee/plan configuration requires an unscoped administrator; complex-scoped administrators may perform member actions only within their complex. All paths are under the existing authenticated staff `/api` group. Existing CSRF/session requirements apply. Mobile/public access is not granted to these endpoints.

| Method | Path | Access / purpose |
|---|---|---|
| GET | `/api/accounting/reconciliation` | Staff; account/category/plan master |
| POST | `/api/accounting/reconciliation-accounts` | Admin; unique string code + descriptions + active |
| PUT | `/api/accounting/reconciliation-accounts/{account}` | Admin; descriptions / active; immutable code |
| PUT | `/api/accounting/membership-categories/{category}` | Admin; account ID / active |
| PUT | `/api/accounting/membership-plans/{plan}` | Admin; nullable category ID |
| PUT | `/api/members/{member}/category` | Admin, scoped; category ID + reason |
| POST | `/api/members/{member}/freeze` | Admin, scoped; starts_on, optional ends_on, reason; verified original category for legacy review |
| POST | `/api/members/{member}/unfreeze` | Admin, scoped; reason |
| POST | `/api/members/{member}/freeze-fee-approvals` | Admin, scoped; configured fee_type_id + reason |
| POST | `/api/challans/{challan}/adjust` | Admin, scoped; reason, proposed_amount for manual requests or complete items for draft recalculation |
| GET | `/api/challans/{challan}/adjustments` | Staff, scoped; immutable adjustment history |

Existing generation accepts optional `charges[].freeze_fee_approval_id`. Example after configuring and approving a freeze fee (IDs are illustrative):

```json
{
  "member_id": 123,
  "idempotency_key": "778afc3f-9038-4bcb-8fb9-0e268521a841",
  "due_on": "2026-11-05",
  "charges": [{"fee_type_id": 12, "freeze_fee_approval_id": 7, "amount": 500}]
}
```

The amount must equal the approved configured amount. Existing fee configuration now accepts `is_freeze_fee` and `freeze_amount`; the old fee-level reconciliation override is prohibited. Member details include `category.account`, `effective_reconciliation_account`, `original_reconciliation_account`, `freezes.approvals`, `account_audits` and actors. New member creation accepts an explicit regular `membership_category_id` for administrators. Category changes on existing members use the reason-based category endpoint, not generic profile updates.

Challan list query additions: `reconciliation_account`, `membership_category_id`, `frozen=1`, `pending=1`; existing `status`, `q` and pagination remain. Category/status filters use saved generation snapshots and do not reinterpret historical records according to a later category change.

## Files

New: six models (`ReconciliationAccount`, `MembershipCategory`, `MembershipAccountAudit`, `MembershipFreeze`, `FreezeFeeApproval`, `ChallanAdjustment`); `MembershipReconciliationService`, `ChallanAdjustmentService`, `ReconciliationController`, `BackfillMemberReconciliation`, `ReconciliationAccountSeeder`; three migrations; React `ReconciliationAccounts`, `MemberAccounting`, `ChallanAdjustmentModal`; `MembershipReconciliationTest`; this document.

Updated: `Member`, `Challan`, `FeeType`; `SapChallanService`, `SapPaymentVerificationService`; `MemberController`, `MembershipController`, `AccountingConfigurationController`, `ChallanController`, `ReferenceDataController`, `PortalAccountController`, mobile `MemberController`, `MembershipCardController`, `FamilyMemberController`; `ImportZcmdMembers`; `DatabaseSeeder`; `routes/web.php`; `resources/js/app.jsx`, `SapChallans.jsx`, `resources/css/app.css`, challan print view; existing management regression tests; README; compiled Vite assets/manifest.

## Validation and release boundary

Automated feature tests cover all 11 seeded accounts, all 10 regular category SAP payloads, new-member assignment, dry-run/idempotent backfill, freeze/unfreeze/expiry, legacy review, approved/wrong/unapproved/duplicate freeze fees, draft/generated/paid adjustments, authorization/scope, mapping propagation/history preservation, duplicate master codes, facilities blocking, generation locks, monthly uniqueness, timeout and repeat verification handling. Existing payment, portal, family/media, ledger and management regression tests run with the suite. External calls in tests are faked.

Browser QA uses an isolated SQLite database at `tmp/reconciliation-ui/database.sqlite`, synthetic members and mock payment mode; no real member was frozen or billed. Validate the account screen, freeze/unfreeze and saved history, generation and adjustment UI there before release.

Before production: take a fresh production database/private-storage/code backup; confirm server schema/SQLite version and shared lock backend; run the three migrations and dry-run backfill; review the exception report; then apply safe mappings and verify retained financial values. Never run the broad default DatabaseSeeder against production: it includes starter users and unrelated configuration. Use the targeted seeder only if needed; the migration already inserts the master.

SAP owner/accounting approval is still needed for customer-master REACON compatibility, a validated freeze fee/GL policy, cancellation/replacement and paid reversal/refund endpoints with acknowledgements, and authoritative payment-response semantics. This implementation does not claim that SAP supports changing a customer master per request. The application release and migration were subsequently verified on 30 October. No live SAP issuance, cancellation, reversal or payment settlement was performed for release verification.

Final local validation: **109 tests / 764 assertions passed**, Vite production build passed (bundle-size advisory only), scoped PHP formatting passed. Browser checks confirmed freeze/unfreeze account restoration, configured fee approval, mock freeze-fee generation under 260111 and persisted manual adjustment history. No real SAP HTTP requests were issued.

Validation evidence: [machine-readable results](reconciliation-validation.json) and [synthetic mock challan screenshot](screenshots/reconciliation-challan.png). The private exception CSV is `storage/app/backups/reacon-20261029/review-after-backfill.csv`.
