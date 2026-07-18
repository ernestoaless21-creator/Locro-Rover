<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAssignOrdersRequest;
use App\Http\Requests\BulkPayAndWithdrawOrdersRequest;
use App\Http\Requests\BulkPayOrdersRequest;
use App\Http\Requests\BulkWithdrawOrdersRequest;
use App\Models\Order;
use App\Services\ClientAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderBulkController extends Controller
{
    /**
     * Reemplaza el equivalente historico de AssignOrderModal (antes un simple
     * axios.put a /order/assignOrders). Autorizacion: el Request ya valida el
     * permiso general 'pedidos.asignar-rover'; ADEMAS se re-valida por cada
     * pedido puntual (defensa en profundidad: nunca confiar en que el listado
     * de IDs que mando el frontend es el que el usuario realmente podia ver).
     */
    public function assignRover(BulkAssignOrdersRequest $request, ClientAssignmentService $assignments): JsonResponse
    {
        $orders = Order::whereIn('id', $request->validated('order_ids'))->get();
        $count = 0;

        DB::transaction(function () use ($orders, $request, &$count, $assignments) {
            foreach ($orders as $order) {
                Gate::authorize('assignRover', [$order, (int) $request->validated('rover_id')]);
                Gate::authorize('mutate', $order->year);
                $order->update([
                    'rover_id' => $request->validated('rover_id'),
                    'updated_by' => $request->user()->id,
                ]);

                // Fase 7, seccion 6: propaga la transferencia deliberada de
                // rover a la asignacion anual del cliente, misma razon que en
                // OrderController::update (ver ClientAssignmentService::syncResponsibleFromOrder).
                $assignments->syncResponsibleFromOrder($order->fresh());
                $count++;
            }
        });

        return response()->json(['updated' => $count]);
    }

    /**
     * Reemplaza el equivalente historico de /order/payOrders (antes un booleano
     * 'mp'). Ahora SIEMPRE crea filas reales en 'payments', nunca toca un campo
     * booleano. Soporta pago total (mode=full_balance) o lineas fijas con uno o
     * mas medios de pago (mode=fixed_lines), ver BulkPayOrdersRequest.
     */
    public function pay(BulkPayOrdersRequest $request): JsonResponse
    {
        $orders = Order::whereIn('id', $request->validated('order_ids'))->get();
        $mode = $request->validated('mode');
        $paidAt = $request->validated('paid_at');
        $notes = $request->validated('notes');
        $userId = $request->user()->id;
        $paymentsCreated = 0;

        DB::transaction(function () use ($orders, $mode, $request, $paidAt, $notes, $userId, &$paymentsCreated) {
            foreach ($orders as $order) {
                Gate::authorize('registerPayment', $order);
                Gate::authorize('mutate', $order->year);

                if ($mode === 'full_balance') {
                    if (bccomp((string) $order->balance_due, '0.00', 2) <= 0) {
                        continue; // ya esta saldado, no crear un pago de $0
                    }

                    $order->payments()->create([
                        'payment_method_id' => $request->validated('payment_method_id'),
                        'amount' => $order->balance_due,
                        'paid_at' => $paidAt,
                        'registered_by' => $userId,
                        'notes' => $notes,
                    ]);
                    $paymentsCreated++;
                } else {
                    foreach ($request->validated('lines') as $line) {
                        $order->payments()->create([
                            'payment_method_id' => $line['payment_method_id'],
                            'amount' => $line['amount'],
                            'paid_at' => $paidAt,
                            'registered_by' => $userId,
                            'notes' => $notes,
                        ]);
                        $paymentsCreated++;
                    }
                }
            }
        });

        return response()->json(['payments_created' => $paymentsCreated]);
    }

    /**
     * Reemplaza el equivalente historico de /order/withdrawOrders. A diferencia
     * de la app anterior, ahora registra QUIEN marco el retiro (withdrawn_by) y
     * una observacion opcional (ver Order::markWithdrawn).
     */
    public function withdraw(BulkWithdrawOrdersRequest $request): JsonResponse
    {
        $orders = Order::whereIn('id', $request->validated('order_ids'))->get();
        $count = 0;

        DB::transaction(function () use ($orders, $request, &$count) {
            foreach ($orders as $order) {
                Gate::authorize('withdraw', $order);
                Gate::authorize('mutate', $order->year);
                $order->markWithdrawn($request->user()->id, $request->validated('notes'));
                $count++;
            }
        });

        return response()->json(['updated' => $count]);
    }

    /**
     * Fase 7, seccion 9: contraparte de withdraw() para el checkbox de retiro
     * en la tabla de Pedidos (desmarcar = "no retirado"). Mismo permiso que
     * marcar el retiro ('pedidos.retirar'); usa Order::unmarkWithdrawn(), que
     * ya limpiaba correctamente withdrawn_at/withdrawn_by/withdrawal_notes.
     */
    public function unwithdraw(BulkWithdrawOrdersRequest $request): JsonResponse
    {
        $orders = Order::whereIn('id', $request->validated('order_ids'))->get();
        $count = 0;

        DB::transaction(function () use ($orders, &$count) {
            foreach ($orders as $order) {
                Gate::authorize('withdraw', $order);
                Gate::authorize('mutate', $order->year);
                $order->unmarkWithdrawn();
                $count++;
            }
        });

        return response()->json(['updated' => $count]);
    }

    /**
     * Fase 7, seccion 10: accion masiva principal "Cobrar y retirar
     * seleccionados". Combina pay(mode=full_balance) + withdraw en UNA sola
     * transaccion, para no obligar a volver a seleccionar los mismos pedidos
     * dos veces (el problema operativo descrito en el prompt de la fase).
     *
     * - El importe cobrado por pedido es SIEMPRE su balance_due actual (nunca
     *   un monto libre), reutilizando la MISMA logica de saldo pendiente ya
     *   probada (Order::$balance_due, mantenida por Order::recalculateTotals()).
     * - Si el saldo pendiente ya es $0, no se crea ningun pago para ese
     *   pedido (evita pagos de $0), pero SI se marca como retirado.
     * - Transaccional: si algo falla a mitad de camino (ej. un pedido sin
     *   permiso), no queda ningun pedido cobrado/retirado a medias.
     */
    public function payAndWithdraw(BulkPayAndWithdrawOrdersRequest $request): JsonResponse
    {
        $orders = Order::whereIn('id', $request->validated('order_ids'))->get();
        $paidAt = $request->validated('paid_at') ?? now();
        $notes = $request->validated('notes');
        $userId = $request->user()->id;
        $paymentsCreated = 0;
        $withdrawnCount = 0;

        DB::transaction(function () use ($orders, $request, $paidAt, $notes, $userId, &$paymentsCreated, &$withdrawnCount) {
            foreach ($orders as $order) {
                Gate::authorize('registerPayment', $order);
                Gate::authorize('withdraw', $order);
                Gate::authorize('mutate', $order->year);

                if (bccomp((string) $order->balance_due, '0.00', 2) > 0) {
                    $order->payments()->create([
                        'payment_method_id' => $request->validated('payment_method_id'),
                        'amount' => $order->balance_due,
                        'paid_at' => $paidAt,
                        'registered_by' => $userId,
                        'notes' => $notes,
                    ]);
                    $paymentsCreated++;
                }

                $order->fresh()->markWithdrawn($userId, $notes);
                $withdrawnCount++;
            }
        });

        return response()->json([
            'payments_created' => $paymentsCreated,
            'withdrawn' => $withdrawnCount,
        ]);
    }
}
