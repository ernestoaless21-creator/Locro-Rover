<?php

namespace App\Services\Import;

/**
 * Limpieza/combinacion de observaciones que pueden traer HTML suelto (ej
 * "<br>" de una carga vieja hecha en un textarea rico). Utilidad chica y
 * generica (no depende de ningun formato puntual), por eso vive suelta en
 * vez de duplicada dentro de un adapter.
 */
class ObservationsCleaner
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $text = strip_tags($text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Combina dos observaciones (ej "Observaciones 2025"/"Observaciones
     * 2026") en un solo texto. Si ambas tienen contenido, se etiquetan para
     * no perder de que edicion viene cada una; si solo una tiene contenido,
     * se usa tal cual (sin etiqueta redundante).
     */
    public static function merge(?string $a, ?string $b, ?string $labelA = null, ?string $labelB = null): ?string
    {
        $a = self::clean($a);
        $b = self::clean($b);

        if ($a === null && $b === null) {
            return null;
        }

        if ($a !== null && $b !== null) {
            $partA = $labelA !== null ? "{$labelA}: {$a}" : $a;
            $partB = $labelB !== null ? "{$labelB}: {$b}" : $b;

            return "{$partA}\n{$partB}";
        }

        return $a ?? $b;
    }
}
