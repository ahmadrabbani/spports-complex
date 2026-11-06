<?php

namespace Tests\Unit;

use App\Console\Commands\VerifySapLedgerPayments;
use PHPUnit\Framework\TestCase;

class SapLedgerPaymentResponseTest extends TestCase
{
    public function test_observed_document_is_paid_but_blank_is_unknown(): void
    {
        $this->assertSame(['paid', '1800013055'], VerifySapLedgerPayments::classify(200, "\n 1800013055\n"));
        foreach (['', '  ', 'unpaid', '<html>1800013055</html>', 'error 1800013055'] as $body) {
            $this->assertSame(['verification_unknown', null], VerifySapLedgerPayments::classify(200, $body));
        }
        $this->assertSame(['verification_error', null], VerifySapLedgerPayments::classify(500, '1800013055'));
    }
}
