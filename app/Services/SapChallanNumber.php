<?php

namespace App\Services;

use InvalidArgumentException;

class SapChallanNumber
{
    public static function make(string $document, string $company, string $year): string
    {
        if (! preg_match('/^\d{10}$/D', $document) || ! preg_match('/^\d{4}$/D', $company) || ! preg_match('/^\d{4}$/D', $year)) {
            throw new InvalidArgumentException('SAP requires a 10-digit document, 4-digit company code and 4-digit financial year.');
        }

        return $document.$company.$year;
    }
}
