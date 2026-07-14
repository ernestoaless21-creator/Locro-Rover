<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PricingController extends Controller
{
    /**
     * FASE 4.1: preview del flujo SIMPLIFICADO. Dado un anio y una cantidad
     * de porciones de locro, devuelve unit_price/line_total/sauces/is_promo
     * SIN persistir nada. El frontend (Orders/New.vue, Orders/Edit.vue) llama
     * esto mientras el usuario tipea la cantidad de porciones, para mostrar
     * el precio y las salsas en tiempo real sin calcular nada por su cuenta.
     */
    public function previewPortions(Request $request, PricingService $pricing): JsonResponse
    {
        Gate::authorize('create', \App\Models\Order::class);

        $data = $request->validate([
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'portions' => ['required', 'integer', 'min:0'],
        ]);

        $year = Year::findOrFail($data['year_id']);
        $portions = $data['portions'];

        $result = $portions > 0
            ? $pricing->calculateLine('locro', 'normal', $portions, $year)
            : ['unit_price' => '0.00', 'line_total' => '0.00'];

        return response()->json([
            'unit_price' => $result['unit_price'],
            'line_total' => $result['line_total'],
            'sauces' => $pricing->calculateSauces($portions, $year),
            'is_promo' => $pricing->isPromoActive($portions, $year),
            'portion_price' => (string) $year->portion_price,
            'promo_unit_price' => $year->promo_unit_price !== null ? (string) $year->promo_unit_price : null,
            'amount_for_promo' => $year->amount_for_promo,
        ]);
    }

    /**
     * Preview generico para UNA linea (usado por "Opciones avanzadas":
     * regalo/personalizado). Se mantiene separado del preview de porciones
     * porque ahi el producto/tipo lo elige el usuario, aca no.
     */
    public function preview(Request $request, PricingService $pricing): JsonResponse
    {
        Gate::authorize('create', \App\Models\Order::class);

        $data = $request->validate([
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'product' => ['required', 'string'],
            'type' => ['required', 'string', 'in:normal,regalo,promocion,personalizado'],
            'quantity' => ['required', 'integer', 'min:1'],
            'custom_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Precio excepcional/personalizado: restringido por permiso, igual
        // que en StoreOrderRequest/StoreOrderItemRequest/UpdateOrderItemRequest
        // (defensa en profundidad: sin esto, alguien podria seguir viendo el
        // preview del precio aunque no pueda enviarlo despues).
        if ($data['type'] === 'personalizado' && ! $request->user()->can('pedidos.precio-excepcional')) {
            abort(403);
        }

        $year = Year::findOrFail($data['year_id']);

        $errors = $pricing->validateItemRules(
            product: $data['product'],
            type: $data['type'],
            customUnitPrice: $data['custom_unit_price'] ?? null,
            year: $year,
        );

        if ($errors) {
            return response()->json(['errors' => $errors], 422);
        }

        $result = $pricing->calculateLine(
            product: $data['product'],
            type: $data['type'],
            quantity: $data['quantity'],
            year: $year,
            customUnitPrice: $data['custom_unit_price'] ?? null,
        );

        return response()->json($result);
    }
}
