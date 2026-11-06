# LDA Sports Complex Management Demo Script

Target duration: 7-9 minutes.

## 1. Introduce the portal - 45 seconds

- Open the public portal.
- Point out the visible **LDA SPORTS COMPLEX - BANK TEST ENVIRONMENT** banner.
- Explain that the bank integration is connected only to MPGS sandbox and cannot create production settlements.
- Show sports complexes, facility badges, gallery imagery, and location information.

## 2. Applicant registration and appointment - 90 seconds

- Register a prepared demo applicant with a non-production CNIC, mobile number, email, password, and address.
- Select a sports complex and appointment purpose.
- Choose an available future slot and submit the appointment.
- Show the applicant's appointment number and dashboard.

## 3. Administrative processing - 90 seconds

- Sign in to the staff portal.
- Open Appointments and locate the demo application.
- Demonstrate review, arrival/file-entry processing, and controlled form-number assignment.
- Explain that application approval and payment status are separate controls.

## 4. Fee and challan generation - 75 seconds

- Open SAP Challans and select the demo member.
- Preview the fee breakdown, GL codes, previous outstanding amount, cost center, and profit center.
- Generate the challan only when the configured SAP test/integration endpoint is available.
- Show the printable challan and its unique LDA challan number.

## 5. Real MPGS sandbox session - 90 seconds

- Open **Bank Payments** and show that credentials are configured without displaying them.
- Select **Test bank connection**.
- Explain that success means MPGS authenticated a real version 73 request and created a PKR 1.00 test checkout session; it is not a paid transaction.
- Return to the member portal, open **My Challans**, and select **Pay with BOP Test**.
- Show that card entry occurs on Mastercard's hosted test page, not inside LDA's application.

## 6. Payment verification and receipt - 90 seconds

- Complete the bank-provided test-card flow if valid test-card instructions are available.
- On return, emphasize that the redirect does not mark the challan paid.
- Select **Verify payment** or use the automatic return inquiry.
- Show the actual MPGS order status and bank reference.
- If the order is `CAPTURED` and all values match, open the verified receipt.
- If it remains pending or fails, show the exact real sandbox result without changing it to success.

## 7. Financial oversight - 60 seconds

- Return to the admin Bank Payments dashboard.
- Show transaction search, pending/verified/failed totals, collected test amount, inquiry status, and last API-call metadata.
- Explain that secrets and card data are redacted from logs.
- Show that an unverified bank transaction cannot be manually marked paid.

## Closing statement

"The application separates LDA/SAP challan generation from bank collection. A browser redirect is never accepted as proof of payment. Only a trusted server-side MPGS order inquiry with matching order, amount, currency, and captured status produces a receipt and closes the challan."

## Presenter preparation checklist

- Use a disposable demo applicant and challan.
- Confirm internet access to `test-gateway.mastercard.com`.
- Confirm the MPGS test credentials are loaded in `.env`.
- Run `php artisan bank:mpgs-test-connection` before the meeting.
- Ask BOP for current test-card details; they are not included in the supplied guide.
- Keep a screenshot of a successful connectivity response as a fallback, while labeling it accurately.
- Do not use Simulation Only mode unless a separately labeled simulator is implemented later.
