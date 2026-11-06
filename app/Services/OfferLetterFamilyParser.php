<?php

namespace App\Services;

class OfferLetterFamilyParser
{
    public static function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^\pL\pN]+/u', '', $value));
    }

    public function parse(array $source): array
    {
        $text = implode("\n", array_column($source['lines'], 'text'));
        $membership = null;
        if (preg_match('/OFFER\s+LETTER\s+OF\s+MEMBERSHIP\s*[#:]?\s*(\d+)/i', $text, $m)) {
            $membership = $m[1];
        }
        $rows = [];
        $issues = [];
        $numbers = [];
        $self = null;
        $minimum = (float) config('offer_letters.minimum_confidence', 95);
        $tableEnded = false;
        foreach ($source['lines'] as $line) {
            if ($tableEnded) {
                continue;
            }
            if (! preg_match('/^\s*[|_\s]*(\d{1,2})[.\s|_:-]+(.+)\b(Son-in-law|Daughter-in-law|Daughter|Wife|Son|Self)\b(.*)$/i', $line['text'], $m)) {
                if ($self !== null && preg_match('/^\s*[|_\s]*(\d{1,2})[.\s|_:-]+/', $line['text'], $serial)) {
                    if ((int) $serial[1] > count($numbers)) {
                        $issues[] = 'A numbered family row could not be read.';
                    } else {
                        $tableEnded = true;
                    }
                }

                continue;
            }
            $number = (int) $m[1];
            $numbers[] = $number;
            $name = trim(preg_replace('/[|_]+/', ' ', $m[2]), " \t.:-");
            $name = preg_replace('/\s+/', ' ', $name);
            $relation = ucfirst(strtolower($m[3]));
            $validity = $m[4];
            if ($relation === 'Self') {
                $self = $name;
                if ($line['confidence'] < $minimum) {
                    $issues[] = 'The sponsor name needs visual confirmation.';
                }

                continue;
            }
            $expires = null;
            $kind = null;
            if (preg_match('/life\s*time/i', $validity)) {
                $kind = 'lifetime';
            } elseif (preg_match('/(\d{2})[-\/.](\d{2})[-\/.](\d{4})/', $validity, $date) && checkdate((int) $date[2], (int) $date[1], (int) $date[3])) {
                $expires = $date[3].'-'.$date[2].'-'.$date[1];
                $kind = 'unmarried_under_25';
            } else {
                $issues[] = 'A membership validity entry could not be read.';
            }
            if ($line['confidence'] < $minimum || ! preg_match('/^[\pL][\pL .\'-]+$/u', $name) || preg_match('/(?:^|\s)[A-Za-z](?:\s|$)/', $name)) {
                $issues[] = 'A dependant name or row needs visual confirmation.';
            }
            $rows[] = ['full_name' => $name, 'relationship' => $relation, 'membership_validity' => $kind, 'membership_valid_until' => $expires, 'confidence' => round($line['confidence'], 2)];
        }
        if (! $membership) {
            $issues[] = 'The offer membership number was not recognized.';
        }
        if (! $self) {
            $issues[] = 'The Self row was not recognized.';
        }
        if (! $rows) {
            $issues[] = 'No dependant rows were recognized.';
        }
        if ($numbers !== range(1, count($numbers))) {
            $issues[] = 'The table rows are incomplete or out of order.';
        }
        if (count($rows) > 25) {
            $issues[] = 'Too many table rows for automatic import.';
        }

        return ['membership_number' => $membership, 'sponsor_name' => $self, 'rows' => $rows, 'issues' => array_values(array_unique($issues)), 'method' => $source['method']];
    }
}
