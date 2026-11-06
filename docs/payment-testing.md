# SAP and isolated payment testing — 28 October 2026

## Local operation

Set `PAYMENT_MODE=mock` (the configuration default), then run:

```sh
php artisan migrate
php artisan config:clear
php artisan serve
npm run build
php artisan test --compact
```

Log in as an existing administrator. Open **Challans**, select an existing applicant/member with complete address, CNIC, phone and configured accounting centers, add fee components, preview the calculated total and generate. The created challan has `environment=mock`, a MOCK document number, and printable TEST MODE labeling. Existing live challans remain live and cannot enter the simulator.

Choose **Run Mock Payment**. Available scenarios: success, failed, pending, cancelled, timeout, duplicate_callback, amount_mismatch, invalid_transaction, delayed. Open **History / Response** for transactions and encrypted verification history. For pending/delayed transactions select **Confirm delayed mock payment**. After verification select **MOCK Receipt**, then print/save as PDF. Mock payments never create real `payments` ledger entries. Only mock-generated charge records are settled.

The simulator is an authenticated admin test harness, not a public bank callback listener. `duplicate_callback` uses the success outcome; replay protection is exercised by submitting the same UUID idempotency key. Cancellation is stored as failed with the original scenario in the audit record. Mismatch/invalid scenarios record verification_required without settlement. A timeout is a simulated outcome rather than an intentionally stalled HTTP request.

## Routes

Existing generation/print: `POST /api/challans`, `POST /api/members/{member}/challan-preview`, `GET /challans/{challan}/print`.

Admin-only, complex-scoped:

- `POST /api/challans/{challan}/mock-payment`: scenario and UUID idempotency_key
- `POST /api/mock-transactions/{transaction}/confirm`
- `GET /api/mock-transactions/{transaction}/receipt`
- `POST /api/challans/{challan}/verify-payment` (10 requests/minute)
- `GET /api/challans/{challan}/payment-history`

`PUT /api/challans/{challan}` no longer accepts manual settlement. Manual payment entry linked to a challan is blocked. Existing standalone cash receipt functionality remains available.

## Configuration

`config/services.php` reads PAYMENT_MODE, SAP_BASE_URL, SAP_CHALLAN_PATH, SAP_VERIFICATION_PATH, SAP_TIMEOUT, SAP_CONNECT_TIMEOUT. Existing SAP_CHALLAN_URL and SAP_CHALLAN_TIMEOUT remain supported. An explicitly set SAP_CHALLAN_URL takes precedence over the base/path combination. See `.env.example`. Existing BOP_MPGS variables and server-side MPGS inquiry are reused. Never publish credentials.

`SapChallanService` contains the existing fee/accounting generation logic. `SportsComplexChallanService` is its compatibility entry point. Leading-zero codes remain strings, requests use encoded HTTP query parameters, and uncertain connection failures cannot be blindly retried. There is no supplied SAP lookup-by-application contract, so uncertain generation requires external SAP reconciliation. Existing local printing remains available; no SAP-issued PDF URL contract was observed.

## Verified network evidence and external blockers

A read-only GET to the supplied PaymentVerification endpoint with challan 170046514510002026 returned HTTP 200 and a 38-byte whitespace-only body from this workstation. No payment evidence was present. No SAP challan creation was sent during diagnostics.

`SapPaymentVerificationService` preserves unknown responses encrypted with Laravel APP_KEY and returns verification_unknown. HTTP/network errors return verification_error. Existing paid records are never downgraded. A paid/unpaid/pending parser cannot safely be enabled until SAP supplies representative authoritative responses, field semantics, amount/currency matching rules and authentication requirements. Therefore real SAP settlement remains unavailable; a 200 status alone never authorizes payment.

Existing BOP MPGS test code supports hosted checkout and server-side inquiry. Actual bank-issued UAT credentials, merchant activation, authoritative bank documentation and callback signing specification were not established by this work. No bank callback authentication contract was invented. No live bank or SAP settlement is claimed.

The existing registration/member architecture is reused for generation; automatic challan creation immediately on public registration and an application approval gate are not added by this change. Browser E2E, signed callback scenarios, full SAP-format coverage, and the complete requested acceptance checklist remain outstanding.

## Storage and audit

Migration `2026_10_28_000001_add_payment_verification_history.php` adds indexed challan environment and verification timestamp plus payment_verifications with foreign keys, actor and timestamps, and encrypted response payloads. Existing challan_items and bank_transactions are reused; bank initiation keys and order IDs retain unique constraints. Challan SAP payload/request URL/response are hidden from ordinary model serialization; responses are restricted to the admin history action.

Local migration applied after backup at `database/backups/database-before-payment-testing-20261028.sqlite`. No production deployment or production migration was performed. Preserve APP_KEY for encrypted history recovery.

`php artisan payments:verify-pending --limit=10` checks at most 10 live numeric SAP documents. Hourly scheduler uses withoutOverlapping, capped at 50 per invocation; the host must run Laravel's scheduler. It only records results and never assumes paid. This is restricted to the server console.

## Changed files

Services: SapChallanService, SportsComplexChallanService, SapPaymentVerificationService, MockBankService, BopMpgsService.
Models: Challan, PaymentVerification.
Controllers: ChallanController, PaymentController, PaymentTestingController.
Configuration/routes: config/services.php, .env.example, routes/web.php, routes/console.php.
UI: resources/js/SapChallans.jsx; resources/views/challans/print.blade.php; resources/views/bank/mock-receipt.blade.php; generated public/build assets.
Tests: MockBankTest and SportsComplexManagementTest. Migration and this guide are new.
