<?php

namespace App\Services\Import;

use App\Models\Client;

/**
 * Reglas de negocio de una fila ya parseada (ImportRow), puro y sin acceso a
 * la base. Separa "errores" (bloqueantes: si aparece alguno en TODO el
 * archivo, no se importa nada) de "warnings" (informativos, no bloquean).
 */
class RowValidator
{
    /**
     * @param  array<string,int>  $duplicatePhoneCounts  telefono normalizado (o crudo si no normalizo) => cantidad de filas del archivo con ese telefono
     * @return array{errors: array<int,array{code:string,message:string}>, warnings: array<int,array{code:string,message:string}>}
     */
    public function validate(ImportRow $row, array $duplicatePhoneCounts = []): array
    {
        $errors = [];
        $warnings = [];

        $hasName = trim((string) $row->firstName) !== '' || trim((string) $row->lastName) !== '';
        if (! $hasName) {
            $errors[] = ['code' => 'missing_name', 'message' => 'Falta nombre y apellido.'];
        }

        $phoneRaw = trim((string) $row->phoneRaw);
        if ($phoneRaw === '') {
            $errors[] = ['code' => 'missing_phone', 'message' => 'Falta el teléfono (es la clave para identificar al cliente).'];
        }

        // QTY/Importe vacios NO son un error: la pagina vieja registraba
        // tambien a los clientes contactados que no compraron ese año (ver
        // RowTransformer, que arma esas filas como un pedido de 0 porciones,
        // sin lineas, en estado 'cancelado'). Solo un valor NEGATIVO sigue
        // siendo un dato invalido que bloquea la importacion.
        if ($row->portions !== null && $row->portions < 0) {
            $errors[] = ['code' => 'invalid_portions', 'message' => 'La cantidad de porciones no puede ser negativa.'];
        }

        if ($row->totalAmount !== null && $row->totalAmount < 0) {
            $errors[] = ['code' => 'invalid_amount', 'message' => 'El importe no puede ser negativo.'];
        }

        if ($phoneRaw !== '') {
            $normalized = Client::normalizePhone($phoneRaw);
            if ($normalized === null || ! preg_match('/^\d{2}-\d{4}-\d{4}$/', $normalized)) {
                $warnings[] = ['code' => 'invalid_phone_format', 'message' => "Formato de teléfono no reconocido: \"{$phoneRaw}\"."];
            }

            $key = $normalized ?? $phoneRaw;
            if (($duplicatePhoneCounts[$key] ?? 0) > 1) {
                $warnings[] = ['code' => 'duplicate_phone', 'message' => 'Este teléfono aparece más de una vez en el archivo.'];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
