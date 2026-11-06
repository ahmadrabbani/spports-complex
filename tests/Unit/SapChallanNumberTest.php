<?php

namespace Tests\Unit;

use App\Services\SapChallanNumber;
use PHPUnit\Framework\TestCase;

class SapChallanNumberTest extends TestCase
{
    public function test_company_and_year_are_appended_and_leading_zeroes_preserved(): void
    {
        $this->assertSame('170016070320002026', SapChallanNumber::make('1700160703', '2000', '2026'));
        $this->assertSame('000016070300202026', SapChallanNumber::make('0000160703', '0020', '2026'));
    }

    public function test_malformed_document_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SapChallanNumber::make('170016070320002026', '2000', '2026');
    }
}
