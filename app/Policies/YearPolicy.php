<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Year;

/**
 * Fase 19: unico punto de entrada para la regla "edicion historica de solo
 * lectura". Cualquier controlador que mute un registro perteneciente a una
 * edicion resuelve su Year y llama Gate::authorize('mutate', $year); la
 * condicion real vive en Year::isEditableBy (no aca), este metodo es solo el
 * adaptador que permite usar Gate::authorize contra una instancia de Year
 * via auto-discovery de Laravel (misma convencion que OrderPolicy/ClientPolicy).
 */
class YearPolicy
{
    public function mutate(User $user, Year $year): bool
    {
        return $year->isEditableBy($user);
    }
}
