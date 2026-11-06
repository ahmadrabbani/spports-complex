<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class CnicSearch
{
    public static function digits(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^[0-9\s\p{Z}\p{Pd}\x{2212}]+$/u', $value)) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $value);

        return strlen($digits) >= 1 && strlen($digits) <= 13 ? $digits : null;
    }

    public static function orWhereMatches(Builder $query, string $input): void
    {
        $digits = self::digits($input);
        if ($digits === null) {
            return;
        }
        $column = $query->getModel()->qualifyColumn('cnic');
        $sql = $query->getQuery()->getGrammar()->wrap($column);
        $bindings = [];
        // Read-only normalization: do not rewrite legacy identities or imported financial data.
        foreach (['-', ' ', "\t", "\r", "\n", "\u{00A0}", "\u{2010}", "\u{2011}", "\u{2013}", "\u{2014}", "\u{2212}"] as $separator) {
            $sql = "REPLACE($sql, ?, '')";
            $bindings[] = $separator;
        }
        // A complete CNIC is an exact identity match; partial digits remain searchable.
        $operator = strlen($digits) === 13 ? '=' : 'LIKE';
        $bindings[] = strlen($digits) === 13 ? $digits : '%'.$digits.'%';
        $query->orWhereRaw("$sql $operator ?", $bindings);
    }
}
