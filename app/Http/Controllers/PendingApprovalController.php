<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de espera para un usuario autenticado sin rol asignado (Fase 5C).
 * Si el usuario YA tiene un rol, lo mandamos directo al Dashboard: esta
 * pantalla no tiene sentido para el (y evita que quede "atascado" aca por un
 * link viejo o el boton "atras" del navegador).
 */
class PendingApprovalController extends Controller
{
    public function show(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if ($request->user()->roles()->count() > 0) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/PendingApproval');
    }
}
