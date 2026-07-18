<?php

namespace App\Services\Import;

use App\Models\User;

/**
 * Resuelve "Rover encargado" contra users.name. Deliberadamente SIN fuzzy
 * matching: si hay 0 o mas de 1 candidato para un nombre, queda "sin
 * resolver" y el usuario tiene que elegirlo a mano antes de confirmar la
 * importacion (pedido explicito: nunca asignar adivinando).
 */
class RoverMatcher
{
    /**
     * @param  string[]  $names  nombres tal cual vienen del archivo (se
     *                           deduplican/normalizan aca)
     * @return array{matched: array<string,int>, unresolved: string[]}
     */
    public function matchMany(array $names): array
    {
        $uniqueNames = collect($names)
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values();

        if ($uniqueNames->isEmpty()) {
            return ['matched' => [], 'unresolved' => []];
        }

        $users = User::query()->active()->get(['id', 'name']);

        $matched = [];
        $unresolved = [];

        foreach ($uniqueNames as $name) {
            $normalized = $this->normalize($name);
            $candidates = $users->filter(fn (User $u) => $this->normalize($u->name) === $normalized);

            if ($candidates->count() === 1) {
                $matched[$name] = $candidates->first()->id;
            } else {
                $unresolved[] = $name;
            }
        }

        return ['matched' => $matched, 'unresolved' => $unresolved];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value), 'UTF-8');
    }
}
