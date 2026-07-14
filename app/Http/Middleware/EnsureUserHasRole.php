<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 5C — Parte 1 (usuarios sin rol).
 *
 * Antes, un usuario recien registrado sin ningun rol asignado podia iniciar
 * sesion pero al entrar a cualquier pantalla protegida (ej. /dashboard)
 * recibia un 403 "This action is unauthorized" generico (via
 * Gate::authorize('viewAny', Order::class) en DashboardController), sin
 * ninguna explicacion ni salida clara.
 *
 * Este middleware intercepta ANTES de llegar a esos Gates/Policies: si el
 * usuario autenticado no tiene ningun rol, se lo redirige siempre a la
 * pantalla de espera ('pending.approval'), en vez de dejar que cada
 * controller le tire un 403 distinto.
 *
 * Deliberadamente NO se aplica a la ruta 'pending.approval' en si (se declara
 * fuera de este grupo de middleware en routes/web.php), ni a las rutas de
 * perfil/logout de Jetstream/Fortify (esas se registran en grupos de rutas
 * propios del paquete, fuera del grupo de routes/web.php donde se aplica
 * este middleware), para que un usuario pendiente SIEMPRE pueda cerrar
 * sesion o ver su perfil, tal como pide el requerimiento.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /**
         * Fase 6A, seccion 3: "no debe poder iniciar sesion NI UTILIZAR la
         * aplicacion". Ademas de bloquear el login (ver
         * FortifyServiceProvider::authenticateUsing), si un usuario que YA
         * tenia una sesion abierta es desactivado mientras tanto, se le
         * corta el acceso en el siguiente request: se cierra la sesion y se
         * lo manda al login, en vez de dejarlo seguir operando.
         */
        if ($user && ! $user->is_active) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Tu cuenta fue desactivada. Contacta a un administrador.');
        }

        if ($user && $user->roles()->count() === 0) {
            if ($request->expectsJson()) {
                abort(403, 'Tu cuenta esta pendiente de autorizacion. Un administrador debe asignarte un rol.');
            }

            return redirect()->route('pending.approval');
        }

        return $next($request);
    }
}
