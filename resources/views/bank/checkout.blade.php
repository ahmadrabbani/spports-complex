<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>BOP Test Payment - {{ $transaction->challan->challan_no }}</title>
    <style>body{margin:0;background:#f4f7fb;color:#14263d;font-family:Arial,sans-serif}.shell{max-width:760px;margin:50px auto;padding:24px}.test{background:#fff0cc;color:#76510b;padding:12px 18px;border-radius:12px;font-weight:800;text-align:center}.card{background:#fff;border:1px solid #dde5ef;border-radius:20px;padding:30px;margin-top:18px;box-shadow:0 18px 50px #18334d12}.brand{color:#176b4d;font-size:13px;font-weight:800;letter-spacing:.12em}.row{display:flex;justify-content:space-between;border-top:1px solid #edf1f5;padding:14px 0}.row span{color:#69798c}.pay{display:block;width:100%;border:0;border-radius:12px;padding:15px;background:#176b4d;color:#fff;font-size:16px;font-weight:800;margin-top:20px;cursor:pointer}.note{font-size:12px;color:#738095;line-height:1.6}</style>
    <script src="{{ $checkoutScript }}" data-error="paymentError" data-cancel="paymentCancelled"></script>
    <script>
        function paymentError(error){ document.getElementById('message').textContent='The bank checkout reported an error. No payment status has been changed.'; }
        function paymentCancelled(){ document.getElementById('message').textContent='Payment was cancelled. The challan remains unpaid.'; }
        Checkout.configure({session:{id:@json($transaction->session_id)}});
    </script>
</head>
<body><main class="shell"><div class="test">LDA SPORTS COMPLEX - BANK TEST ENVIRONMENT</div><section class="card"><div class="brand">BANK OF PUNJAB - MPGS SANDBOX</div><h1>Secure test payment</h1><div class="row"><span>Applicant</span><b>{{ $transaction->member->full_name }}</b></div><div class="row"><span>Sports complex</span><b>{{ $transaction->challan->sportsComplex->name }}</b></div><div class="row"><span>Challan</span><b>{{ $transaction->challan->challan_no }}</b></div><div class="row"><span>Bank order</span><b>{{ $transaction->order_id }}</b></div><div class="row"><span>Amount</span><b>PKR {{ number_format((float)$transaction->amount,2) }}</b></div><button class="pay" onclick="Checkout.showPaymentPage()">Continue to BOP test payment page</button><p id="message" class="note">Card details are entered only on Mastercard's hosted test page. LDA does not receive or store card numbers.</p></section></main></body>
</html>
