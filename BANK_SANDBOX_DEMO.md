# BOP MPGS Sandbox Integration

## Scope and evidence

This application integrates Bank of Punjab's Mastercard Payment Gateway Services (MPGS) **test environment** using Hosted Checkout API version 73.

The supplied three-page bank guide documents:

- Test gateway host: `https://test-gateway.mastercard.com`
- NVP session endpoint: `POST /api/nvp/version/73`
- Authentication using `apiUsername=merchant.<merchant id>` and `apiPassword`
- `INITIATE_CHECKOUT` with `interaction.operation=PURCHASE`
- PKR order amount, description, unique order ID, and Hosted Checkout JavaScript

The implementation also uses MPGS's official `RETRIEVE_ORDER` NVP operation for server-side verification. A browser redirect never marks a payment paid.

The supplied guide does **not** document a BOP webhook payload/signature contract, webhook header, retry behavior, payment-link request, refund operation, settlement export, or reversal API. Those capabilities are not represented as working.

## Secure configuration

Copy the following values into the local `.env`. Do not commit real credentials.

```dotenv
BOP_MPGS_ENABLED=true
BOP_MPGS_ENVIRONMENT=test
BOP_MPGS_BASE_URL=https://test-gateway.mastercard.com
BOP_MPGS_API_VERSION=73
BOP_MPGS_MERCHANT_ID=
BOP_MPGS_API_PASSWORD=
BOP_MPGS_MERCHANT_NAME="LDA Sports Complex"
BOP_MPGS_WEBHOOK_KEY=
BOP_MPGS_TIMEOUT=30
```

Then clear cached configuration:

```bash
php artisan config:clear
```

Credentials are used only server-side. Audit records redact passwords, secrets, keys, tokens, card data, and account data. TLS verification remains enabled.

## Database and application setup

```bash
php artisan migrate --force
npm run build
php artisan test
```

## Test connectivity

In the admin portal, open **Bank Payments** and select **Test bank connection**. This sends a real PKR 1.00 `INITIATE_CHECKOUT` request and creates a sandbox checkout session; it does not complete a card payment.

The same diagnostic is available from the command line:

```bash
php artisan bank:mpgs-test-connection
```

Success is reported only when MPGS returns `result=SUCCESS` and a non-empty session ID. The diagnostic order uses the prefix `LDA-DIAG-`.

## Create a challan and initiate payment

1. Complete the existing applicant, appointment, file-entry, and form-number workflow.
2. Generate the SAP/LDA challan through the existing challan module.
3. Sign in as the linked member and open **My Challans**.
4. Select **Pay with BOP Test**.
5. The server creates an idempotent local bank transaction and requests an MPGS checkout session.
6. The member continues to Mastercard's hosted test page. Card data is not entered into or stored by this application.

## Verify payment

After Hosted Checkout returns, the application calls `RETRIEVE_ORDER` server-to-server. The return `resultIndicator` is recorded as supporting context only and is not treated as payment proof.

A payment is recorded only when all conditions are true:

- MPGS inquiry returns `result=SUCCESS`.
- MPGS order status is `CAPTURED`.
- Returned order ID equals the stored order ID.
- Returned amount equals the challan amount.
- Returned currency is `PKR`.

Pending, failed, cancelled, refunded, mismatched, timed-out, and unknown responses never mark the challan paid. Repeated verification is idempotent.

## Receipts and transaction history

- Members can print a receipt only after server-side verification.
- Admin users can search by order, challan, member, CNIC, customer number, or bank reference.
- The Bank Payments dashboard shows pending, verified, failed, reversed, and reconciliation-required records.
- Each MPGS call stores a correlation ID, operation, endpoint path, timestamps, HTTP status, duration, sanitized request, and sanitized response.

## Safe demo-data reset

Do not delete real member, challan, payment, or imported ledger data. For a dedicated disposable demo database, make a backup first, then remove only records whose bank order ID starts with `LDA-DIAG-` or belongs to explicitly identified demo challans. There is intentionally no broad reset button in the UI.

## Automated and real testing boundaries

- Automated tests mock MPGS responses and verify request construction, idempotency, amount/currency/order matching, trusted payment confirmation, and manual-status blocking.
- `bank:mpgs-test-connection` is the real sandbox connectivity test.
- A successful connectivity test proves authentication and session creation, not a completed payment or settlement.
- A complete verified payment requires a user to finish the hosted test-card flow and then run the order inquiry.
- Webhook/callback signature verification is unavailable because the supplied guide provides a key but no callback contract.
- Simulation mode was not enabled: the application never fabricates a bank success response.

## Production warning

This build is deliberately restricted to `BOP_MPGS_ENVIRONMENT=test`. Production activation requires a separate bank-approved production guide, production credentials, callback contract, penetration/security review, deployment configuration, and end-to-end bank acceptance testing.
