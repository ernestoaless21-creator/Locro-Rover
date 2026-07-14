<?php

namespace App\Http\Controllers;

use App\Models\Gift;
use App\Models\Loss;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Year;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * FASE 5A/6A. Dashboard operativo de la edicion activa (o la indicada
     * por ?year_id=).
     *
     * AUTORIZACION:
     * - Requiere 'pedidos.ver' para entrar (Gate::authorize('viewAny', Order::class)).
     * - SCOPE: desde Fase 6A TODOS los roles operativos tienen
     *   'pedidos.ver-todos' ("todos venden", ver seccion 2 de la fase), asi
     *   que las metricas globales (vendidas globalmente, ranking sin $) se
     *   calculan sobre TODOS los pedidos de la edicion para cualquier
     *   usuario operativo. Las metricas PERSONALES (mis ventas, mi progreso)
     *   siempre se calculan aparte, filtradas por rover_id = usuario actual,
     *   sin importar el permiso de ver-todos.
     * - PRODUCCION REAL (seccion 11, sensible): elaboradas/regaladas/perdidas/
     *   aptas para la venta/disponibles restantes NO deben viajar en el
     *   payload para quien no tiene 'produccion.ver' (admin/jefe_logistica/
     *   logistica). No se calculan siquiera si el usuario no tiene el permiso.
     * - FINANCIERO: igual que antes, solo con 'finanzas.ver' (importes
     *   agregados y columnas $ del ranking).
     *
     * PERFORMANCE: todo se resuelve con agregaciones SQL (count/sum/groupBy).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Order::class);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $user = $request->user();
        $canViewAll = $user->can('pedidos.ver-todos');
        $canViewFinancials = Gate::allows('viewGlobalFinancials', Order::class);
        $canViewProduction = $user->can('produccion.ver');

        // Base de pedidos de la edicion, ya scopeada por rover si corresponde
        // (relevante solo para un eventual usuario sin 'pedidos.ver-todos',
        // hoy ninguno de los roles operativos de Fase 6A cae en ese caso).
        $base = Order::query()
            ->where('year_id', $year->id)
            ->when(! $canViewAll, fn ($q) => $q->where('rover_id', $user->id));

        // --- 1) Conteo de pedidos por estado (1 query) ---
        $statusCounts = (clone $base)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $pendientes = (int) ($statusCounts['pendiente'] ?? 0);
        $confirmados = (int) ($statusCounts['confirmado'] ?? 0);
        $cancelados = (int) ($statusCounts['cancelado'] ?? 0);
        $totalPedidosActivos = $pendientes + $confirmados;

        // Pedidos NO cancelados: base para todo lo que sigue.
        $activeBase = (clone $base)->where('status', '!=', 'cancelado');

        // --- 2) Porciones vendidas globalmente + personales (Fase 6A: la
        // personal SIEMPRE se calcula, sin importar canViewAll). ---
        $portionsSold = (int) (clone $activeBase)->sum('total_portions');
        $personalPortionsSold = (int) (clone $activeBase)->where('rover_id', $user->id)->sum('total_portions');

        // --- 3) Salsas ---
        $saucesTotal = (int) OrderItem::query()
            ->where('product', 'salsas')
            ->whereIn('order_id', (clone $activeBase)->select('id'))
            ->sum('quantity');

        // --- 4) Estado de retiro ---
        $withdrawalRows = (clone $activeBase)
            ->select('withdrawal_status', DB::raw('count(*) as total'), DB::raw('sum(total_portions) as portions'))
            ->groupBy('withdrawal_status')
            ->get()
            ->keyBy('withdrawal_status');

        $withdrawal = [
            'no_retirado' => ['count' => (int) ($withdrawalRows['no_retirado']->total ?? 0), 'portions' => (int) ($withdrawalRows['no_retirado']->portions ?? 0)],
            'parcial' => ['count' => (int) ($withdrawalRows['parcial']->total ?? 0), 'portions' => (int) ($withdrawalRows['parcial']->portions ?? 0)],
            'retirado' => ['count' => (int) ($withdrawalRows['retirado']->total ?? 0), 'portions' => (int) ($withdrawalRows['retirado']->portions ?? 0)],
        ];

        // --- 5) Estado de pago ---
        $paidCount = (clone $activeBase)->whereColumn('total_paid', '>=', 'total_amount')->where('total_amount', '>', 0)->count();
        $unpaidCount = (clone $activeBase)->where('total_paid', 0)->count();
        $partialCount = (clone $activeBase)->where('total_paid', '>', 0)->whereColumn('total_paid', '<', 'total_amount')->count();

        // --- Metas de venta (Fase 6A, seccion 12): division por cero /
        // porcentajes invalidos evitados con metas nullable. ---
        $globalGoal = $year->sales_goal_global;
        $individualGoal = $year->sales_goal_individual_default;

        $goals = [
            'global_goal' => $globalGoal,
            'global_sold' => $portionsSold,
            'global_progress_pct' => $globalGoal ? round(min(999, $portionsSold / $globalGoal * 100), 1) : null,
            'individual_goal' => $individualGoal,
            'individual_sold' => $personalPortionsSold,
            'individual_progress_pct' => $individualGoal ? round(min(999, $personalPortionsSold / $individualGoal * 100), 1) : null,
        ];

        $data = [
            // Fase 6A: NUNCA se manda el modelo Year completo (made_portions es
            // sensible, ver Year::toPublicArray). 'years' (para el selector) ya
            // solo trae columnas no sensibles.
            'year' => $year->toPublicArray($canViewProduction),
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            'canViewAll' => $canViewAll,
            'canViewFinancials' => $canViewFinancials,
            'canViewProduction' => $canViewProduction,
            'canCreateOrder' => $user->can('pedidos.crear'),
            'canManageParameters' => $user->can('parametros.gestionar'),
            'canManageGifts' => $user->can('regalos.gestionar'),
            'canManageLosses' => $user->can('perdidas.gestionar'),

            'metrics' => [
                'total_pedidos' => $totalPedidosActivos,
                'pendientes' => $pendientes,
                'confirmados' => $confirmados,
                'cancelados' => $cancelados,
                'portions_sold' => $portionsSold,
                'personal_portions_sold' => $personalPortionsSold,
                'sauces_total' => $saucesTotal,
            ],

            'goals' => $goals,

            'withdrawal' => $withdrawal,

            'payments' => [
                'paid_count' => $paidCount,
                'partial_count' => $partialCount,
                'unpaid_count' => $unpaidCount,
            ],

            'attention' => [
                'pending_balance_count' => $partialCount + $unpaidCount,
                'not_withdrawn_count' => $withdrawal['no_retirado']['count'] + $withdrawal['parcial']['count'],
                'unassigned_count' => (clone $activeBase)->whereNull('rover_id')->count(),
            ],
        ];

        // --- Produccion real (Fase 6A, seccion 11): SOLO si 'produccion.ver'.
        // No se calcula ni se manda nada de esto si el usuario no tiene el
        // permiso (ni siquiera las queries se ejecutan). ---
        if ($canViewProduction) {
            $madePortions = (int) ($year->made_portions ?? 0);
            $giftedPortions = (int) Gift::where('year_id', $year->id)->sum('quantity');
            $lostPortions = (int) Loss::where('year_id', $year->id)->sum('quantity');
            $eligiblePortions = $madePortions - $giftedPortions - $lostPortions;
            $portionsRemaining = $eligiblePortions - $portionsSold;

            $data['production'] = [
                'made_portions' => $madePortions,
                'gifted_portions' => $giftedPortions,
                'lost_portions' => $lostPortions,
                'eligible_portions' => $eligiblePortions,
                'portions_remaining' => $portionsRemaining,
            ];
        }

        // --- Financiero GLOBAL: solo si 'finanzas.ver'. ---
        if ($canViewFinancials) {
            $sums = (clone $activeBase)
                ->selectRaw('COALESCE(SUM(total_amount),0) as total_amount, COALESCE(SUM(total_paid),0) as total_paid, COALESCE(SUM(balance_due),0) as balance_due')
                ->first();

            $data['financials'] = [
                'total_amount' => (string) $sums->total_amount,
                'total_paid' => (string) $sums->total_paid,
                'balance_due' => (string) $sums->balance_due,
            ];

            $methods = PaymentMethod::where('is_active', true)->get(['id', 'name']);
            $collectedByMethod = Payment::query()
                ->select('payment_method_id', DB::raw('sum(amount) as total'))
                ->whereIn('order_id', (clone $activeBase)->select('id'))
                ->groupBy('payment_method_id')
                ->pluck('total', 'payment_method_id');

            $data['financials']['by_method'] = $methods->map(fn ($m) => [
                'name' => $m->name,
                'total' => (string) ($collectedByMethod[$m->id] ?? '0'),
            ])->values();
        }

        // --- Ranking por Rover: visible para cualquiera con 'pedidos.ver-todos'
        // (con Fase 6A, todos los roles operativos). Las columnas $ solo se
        // agregan si ademas hay 'finanzas.ver'. No se filtra por rol
        // especifico: refleja quien esta asignado como responsable en
        // rover_id, sea cual sea su rol (ver seccion 2: cualquier rol vende). ---
        if ($canViewAll) {
            $rankingQuery = (clone $activeBase)
                ->whereNotNull('rover_id')
                ->select('rover_id', DB::raw('count(*) as orders_count'), DB::raw('sum(total_portions) as portions'));

            if ($canViewFinancials) {
                $rankingQuery->addSelect(
                    DB::raw('sum(total_amount) as amount'),
                    DB::raw('sum(total_paid) as paid'),
                    DB::raw('sum(balance_due) as balance'),
                );
            }

            $ranking = $rankingQuery
                ->groupBy('rover_id')
                ->orderByDesc('portions')
                ->with('rover:id,name')
                ->get()
                ->map(function ($row) use ($canViewFinancials) {
                    $item = [
                        'rover' => $row->rover?->name ?? '(sin nombre)',
                        'orders_count' => (int) $row->orders_count,
                        'portions' => (int) $row->portions,
                    ];
                    if ($canViewFinancials) {
                        $item['amount'] = (string) $row->amount;
                        $item['paid'] = (string) $row->paid;
                        $item['balance'] = (string) $row->balance;
                    }

                    return $item;
                });

            $data['ranking'] = $ranking;
        }

        return Inertia::render('Dashboard', $data);
    }
}
