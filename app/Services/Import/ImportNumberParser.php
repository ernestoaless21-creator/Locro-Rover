<?php

namespace App\Services\Import;

/**
 * Coercion tolerante de celdas de Excel a numero/booleano, compartida por
 * todos los adapters. Nunca "adivina": si el valor no es interpretable
 * devuelve null (RowValidator es quien decide si eso es un error).
 */
class ImportNumberParser
{
    public static function toNullableInt(mixed $value): ?int
    {
        $float = self::toNullableFloat($value);

        return $float !== null ? (int) round($float) : null;
    }

    public static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // Quita simbolos de moneda/espacios: "$ 1.234,50" -> "1.234,50".
        $str = preg_replace('/[^\d,.\-]/', '', $str) ?? '';

        if ($str === '' || $str === '-') {
            return null;
        }

        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $str)) {
            // Formato AR: "." de miles, "," decimal (ej "1.234,50").
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } elseif (str_contains($str, ',') && ! str_contains($str, '.')) {
            // Solo coma presente: se asume separador decimal (ej "1234,50").
            $str = str_replace(',', '.', $str);
        }

        return is_numeric($str) ? (float) $str : null;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $str = mb_strtolower(trim((string) $value), 'UTF-8');

        return in_array($str, ['si', 'sí', 'yes', 'true', '1', 'x'], true);
    }
}
