<?php

namespace App\Support;

class Rib
{
    public static function compact(string $codeBanque, string $codeAgence, string $compte, string $cle): string
    {
        $c = strtoupper(preg_replace('/\s+/', '', $codeBanque));
        $a = strtoupper(preg_replace('/\s+/', '', $codeAgence));
        $n = strtoupper(preg_replace('/\s+/', '', $compte));
        $k = strtoupper(preg_replace('/\s+/', '', $cle));

        // Longueurs attendues CEMAC: 5-5-11-2
        if (strlen($c) !== 5 || strlen($a) !== 5 || strlen($n) !== 11 || strlen($k) !== 2) {
            abort(422, 'RIB invalide (longueurs)');
        }
        return $c . $a . $n . $k;
    }

    public static function compactFromRaw(string $rib): string
    {
        $x = strtoupper(preg_replace('/\s+/', '', $rib));
        if (strlen($x) !== 23) {
            abort(422, 'RIB invalide (longueur)');
        }
        return $x;
    }

    public static function isValid(string $codeBanque, string $codeAgence, string $compte, string $cle): bool
    {
        // transformer lettres → chiffres (A=10, ..., Z=35)
        $num = self::toDigits($codeBanque . $codeAgence . $compte) . strtoupper($cle);
        return self::mod97($num) === 0;
    }

    public static function computeKey(string $codeBanque, string $codeAgence, string $compte): string
    {
        $num  = self::toDigits($codeBanque . $codeAgence . $compte);
        $rest = self::mod97($num . '00');
        $key  = 97 - $rest;
        return str_pad((string) $key, 2, '0', STR_PAD_LEFT);
    }

    private static function toDigits(string $s): string
    {
        $out = '';
        foreach (str_split(strtoupper(preg_replace('/\s+/', '', $s))) as $ch) {
            $out .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        return $out;
    }

    /**
     * mod97 streaming pour éviter tout overflow (parcours chiffre par chiffre)
     */
    private static function mod97(string $num): int
    {
        $res = 0;
        $num = preg_replace('/\s+/', '', $num);
        $len = strlen($num);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $num[$i];
            $res = ($res * 10 + $digit) % 97;
        }
        return $res;
    }
}
