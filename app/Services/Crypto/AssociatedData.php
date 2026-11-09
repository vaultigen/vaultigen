<?php

namespace App\Services\Crypto;

/**
 * Builds canonical, length-prefixed associated-data byte strings.
 *
 * Length prefixes make the encoding unambiguous: no field value can be
 * rearranged to produce the same bytes as a different field set.
 */
final class AssociatedData
{
    /**
     * @param  array<string, int|string>  $fields
     */
    public static function encode(array $fields): string
    {
        ksort($fields, SORT_STRING);

        $encoded = '';
        foreach ($fields as $name => $value) {
            $value = (string) $value;
            $encoded .= pack('N', strlen($name)).$name.pack('N', strlen($value)).$value;
        }

        return $encoded;
    }
}
