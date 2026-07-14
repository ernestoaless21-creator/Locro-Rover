<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'address', 'postal_code',
        'general_notes', 'historical_number', 'created_by', 'updated_by',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ClientObservation::class);
    }

    /**
     * Asignaciones anuales (Fase 6A): que usuario es responsable de contactar
     * a este cliente en cada edicion, y su estado de seguimiento/call center.
     * NO son pedidos (ver ClientAssignment).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientAssignment::class);
    }

    public function assignmentForYear(int $yearId): ?ClientAssignment
    {
        return $this->assignments()->where('year_id', $yearId)->first();
    }

    public function ordersForYear(int $yearId): HasMany
    {
        return $this->orders()->where('year_id', $yearId);
    }

    public function observationsForYear(int $yearId): HasMany
    {
        return $this->observations()->where('year_id', $yearId);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Fase 7, seccion 4: busqueda tolerante por nombre, apellido, nombre+apellido
     * (en cualquier orden), telefono o numero historico. Centralizada aca (no
     * duplicada en cada Controller) porque Clients, Assignments y Orders la
     * necesitan por igual.
     *
     * - Un solo token: matchea parcialmente contra first_name, last_name,
     *   phone o historical_number (igual que antes).
     * - Dos o mas tokens (ej. "Jose Perez" o "Perez Jose"): exige que CADA
     *   token matchee parcialmente contra first_name O last_name, sin importar
     *   el orden en que se escribieron.
     */
    public static function scopeSearchTerm($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', $term)));

        return $query->where(function ($outer) use ($term, $tokens) {
            $outer->where('phone', 'like', "%{$term}%")
                ->orWhere('historical_number', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%");

            if (count($tokens) >= 2) {
                $outer->orWhere(function ($allTokens) use ($tokens) {
                    foreach ($tokens as $token) {
                        $allTokens->where(function ($perToken) use ($token) {
                            $perToken->where('first_name', 'like', "%{$token}%")
                                ->orWhere('last_name', 'like', "%{$token}%");
                        });
                    }
                });
            }
        });
    }

    /**
     * Normaliza nombre/apellido: colapsa espacios y capitaliza cada palabra.
     * "jUaN   PEREZ" -> "Juan Perez"
     */
    public static function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = trim(preg_replace('/\s+/', ' ', $value));

        if ($collapsed === '') {
            return null;
        }

        return mb_convert_case(mb_strtolower($collapsed, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Normaliza telefonos argentinos a formato "AA-BBBB-CCCC" cuando es posible.
     *
     * Fase 7, seccion 5: la version anterior solo reconocia 10 digitos "planos"
     * (ej. "11 1234 5678"), por lo que un numero en formato internacional
     * (+54 9 11..., 5491112345678, etc.) perdia la homogeneizacion. Ahora se
     * reconocen y despojan, en cualquier combinacion, los siguientes prefijos
     * antes de intentar el formato final:
     *   - prefijo internacional argentino "54" (con o sin "+", el "+" ya se
     *     descarta al quedarnos solo con digitos).
     *   - marcador de celular internacional "9" inmediatamente despues del 54.
     *   - prefijo nacional de larga distancia "0" (ej. numeros escritos como
     *     "011...").
     *   - uso historico local del "15" para celulares dentro del mismo codigo
     *     de area (ej. "011 15 1234-5678"), SOLO para el codigo de area AMBA
     *     "11" (el unico que la aplicacion reconoce explicitamente hoy), para
     *     no adivinar donde termina un codigo de area de 3 o 4 digitos.
     *
     * IMPORTANTE (no adivinar/truncar): NO se intenta separar codigo de area
     * de numero para longitudes/prefijos que la app no reconoce con certeza
     * (codigos de area de 3 o 4 digitos distintos de "11", o cantidades de
     * digitos ambiguas). En esos casos se preserva el comportamiento anterior:
     * solo se colapsan espacios, sin forzar un formato ni convertir el numero
     * en otro distinto. Esto evita "normalizar" incorrectamente un fijo de
     * otra provincia partiendolo en un lugar equivocado.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return null;
        }

        $national = static::extractArgentineNationalNumber($digits);

        if ($national !== null) {
            return substr($national, 0, 2).'-'.substr($national, 2, 4).'-'.substr($national, 6, 4);
        }

        // No matchea ningun patron reconocido con certeza: se devuelve solo con
        // espacios colapsados, sin forzar formato, para no destruir un telefono
        // valido con formato excepcional (fijo con otro codigo de area, etc).
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Devuelve el numero nacional significativo de 10 digitos (codigo de area +
     * numero, sin 0/54/9/15) si se lo puede reconocer con certeza, o null si no.
     *
     * IMPORTANTE sobre codigos de area de distinta longitud: si el numero YA
     * viene "pelado" (sin 0/54 de por medio) y tiene 10 digitos, se preserva
     * el comportamiento HISTORICO de la app (formatearlo igual, sin importar
     * el codigo de area real: esa es una limitacion preexistente, no algo
     * que esta fase deba resolver). Lo nuevo aca es reconocer los prefijos
     * 54/9/0/15; PERO esas transformaciones SOLO se aceptan cuando el
     * resultado final corresponde al codigo de area AMBA "11" (el unico que
     * la aplicacion reconoce con certeza hoy). Si despues de sacar esos
     * prefijos el resultado no empieza con "11", se descarta (se devuelve
     * null y el llamador cae al fallback no destructivo) en vez de asumir
     * arbitrariamente donde termina un codigo de area de 3 o 4 digitos.
     */
    protected static function extractArgentineNationalNumber(string $digits): ?string
    {
        $hadPrefix = false;

        // Prefijo internacional argentino "54".
        if (str_starts_with($digits, '54') && strlen($digits) > 10) {
            $digits = substr($digits, 2);
            $hadPrefix = true;

            // Marcador de celular internacional "9" inmediatamente despues del 54.
            if (str_starts_with($digits, '9')) {
                $digits = substr($digits, 1);
            }
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 10) {
            // Prefijo nacional de larga distancia "0" (ej. "011 1234-5678").
            $digits = substr($digits, 1);
            $hadPrefix = true;
        }

        // Uso historico local del "15" para celulares, solo reconocido para el
        // codigo de area AMBA "11" (2 digitos): "11" + "15" + 8 digitos = 12.
        if (strlen($digits) === 12 && str_starts_with($digits, '1115')) {
            $digits = '11'.substr($digits, 4);
            $hadPrefix = true;
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        // Si se despojo algun prefijo nuevo (0/54/9/15), solo se acepta el
        // resultado con certeza para el codigo de area AMBA "11": para
        // cualquier otro codigo de area no se puede saber, sin una tabla de
        // codigos, donde termina (podria ser de 2, 3 o 4 digitos).
        if ($hadPrefix && ! str_starts_with($digits, '11')) {
            return null;
        }

        return $digits;
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client) {
            $client->first_name = static::normalizeName($client->first_name);
            $client->last_name = static::normalizeName($client->last_name);
            $client->phone = static::normalizePhone($client->phone);
        });
    }

    /**
     * Fase 7, seccion 3: numero historico automatico, unico y permanente.
     *
     * IMPORTANTE sobre concurrencia: este metodo NO alcanza por si solo para
     * evitar una condicion de carrera entre dos altas simultaneas (leer el
     * MAX y sumarle 1 no es atomico). Por eso SIEMPRE debe invocarse dentro
     * de una transaccion de base de datos (ver ClientController::store),
     * donde `lockForUpdate()` mantiene bloqueadas las filas de `clients`
     * hasta el commit, serializando altas concurrentes. La restriccion UNIQUE
     * de la columna (ver migracion) actua ademas como proteccion final ante
     * cualquier carrera residual: si dos transacciones lograran calcular el
     * mismo numero, la segunda fallaria con un error de integridad en vez de
     * duplicar el numero historico silenciosamente.
     */
    public static function nextHistoricalNumber(): int
    {
        $max = (int) static::query()->lockForUpdate()->max('historical_number');

        return $max + 1;
    }

    /**
     * Crea un cliente asignandole automaticamente el proximo numero historico
     * disponible (a menos que ya se haya indicado uno explicitamente, por
     * ejemplo desde un import o desde tests). Reintenta ante una eventual
     * violacion de la restriccion UNIQUE (carrera extrema), nunca deja un
     * cliente sin numero historico por una carrera.
     */
    public static function createWithAutoHistoricalNumber(array $attributes): self
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($attributes) {
                    $attributes['historical_number'] ??= static::nextHistoricalNumber();

                    return static::create($attributes);
                });
            } catch (QueryException $e) {
                if ($attempts >= 5 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
                // Carrera detectada por la restriccion UNIQUE: se reintenta con
                // un nuevo MAX() dentro de una nueva transaccion.
            }
        }
    }
}
