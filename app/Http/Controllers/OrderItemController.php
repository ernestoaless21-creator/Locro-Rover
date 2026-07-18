<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderItemRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrderItemController extends Controller
{
    /**
     * Todas las respuestas son JSON (no Inertia visits): la pagina de edicion de
     * pedido (Orders/Edit.vue) queda montada y solo actualiza su propio estado
     * local con lo que devuelve el backend, en vez de recargar toda la pagina
     * por cada linea que se agrega/edita/borra. El calculo de precio SIEMPRE lo
     * hace PricingService del lado del servidor; el frontend nunca calcula
     * line_total por su cuenta (evita divergencias entre lo que el usuario ve
     * y lo que realmente se persiste).
     */
    public function store(StoreOrderItemRequest $request, Order $order, PricingService $pricing): JsonResponse
    {
        Gate::authorize('mutate', $order->year);

        $item = $pricing->addItemToOrder(
            order: $order,
            product: $request->validated('product'),
            type: $request->validated('type'),
            quantity: $request->validated('quantity'),
            year: $order->year,
            description: $request->validated('description'),
            customUnitPrice: $request->filled('custom_unit_price') ? (float) $request->validated('custom_unit_price') : null,
            createdBy: $request->user()->id,
        );

        // addItemToOrder ya dispara recalculateTotals via el evento 'saved' del
        // modelo (no se envolvio en withoutEvents aca, a diferencia del alta
        // masiva de OrderController::store: es UNA sola linea, no hay N+1 real).
        return response()->json([
            'item' => $item,
            'order' => $order->fresh(['items', 'payments']),
        ]);
    }

    /**
     * Verifica que la linea pertenezca realmente al pedido de la URL antes de
     * tocar nada (evita que alguien edite/borre una linea de OTRO pedido
     * adivinando su ID, aunque tenga permiso sobre el pedido que puso en la URL).
     */
    private function assertBelongsToOrder(Order $order, OrderItem $item): void
    {
        abort_if($item->order_id !== $order->id, 404);
    }

    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item, PricingService $pricing): JsonResponse
    {
        $this->assertBelongsToOrder($order, $item);
        Gate::authorize('mutate', $order->year);

        $calculated = $pricing->calculateLine(
            product: $request->validated('product'),
            type: $request->validated('type'),
            quantity: $request->validated('quantity'),
            year: $order->year,
            customUnitPrice: $request->filled('custom_unit_price') ? (float) $request->validated('custom_unit_price') : null,
        );

        $item->update([
            'product' => $request->validated('product'),
            'type' => $request->validated('type'),
            'quantity' => $request->validated('quantity'),
            'description' => $request->validated('description'),
            'unit_price' => $calculated['unit_price'],
            'line_total' => $calculated['line_total'],
        ]);

        return response()->json([
            'item' => $item->fresh(),
            'order' => $order->fresh(['items', 'payments']),
        ]);
    }

    public function destroy(Order $order, OrderItem $item): JsonResponse
    {
        $this->assertBelongsToOrder($order, $item);
        Gate::authorize('update', $order);
        Gate::authorize('mutate', $order->year);

        $item->delete();

        return response()->json([
            'order' => $order->fresh(['items', 'payments']),
        ]);
    }
}
