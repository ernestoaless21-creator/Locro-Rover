<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkAssignClientAssignmentsRequest;
use App\Http\Requests\BulkDistributeClientAssignmentsRequest;
use App\Http\Requests\GenerateAssignmentsRequest;
use App\Http\Requests\TransferClientAssignmentRequest;
use App\Http\Requests\UpdateClientAssignmentContactRequest;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\ClientAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase 6A. Asignaciones anuales de clientes / call center: quien es
 * responsable de contactar a cada cliente en una edicion, y en que estado de
 * seguimiento esta. Ver app/Models/ClientAssignment.php para la distincion
 * estructural CLIENTE != ASIGNACION ANUAL != PEDIDO.
 */
class ClientAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ClientAssignment::class);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $user = $request->user();

        $assignments = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->with([
                'client:id,historical_number,first_name,last_name,phone,address',
                'assignedUser:id,name',
                'lastContactedBy:id,name',
            ])
            ->when($request->filled('assigned_user_id'), fn ($q) => $q->where('assigned_user_id', $request->get('assigned_user_id')))
            ->when($request->boolean('unassigned_only'), fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($request->filled('contact_status'), fn ($q) => $q->where('contact_status', $request->get('contact_status')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('client', fn ($cq) => $cq->searchTerm($request->get('search'))))
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('ClientAssignments/Index', [
            'assignments' => $assignments,
            'year' => $year,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            'statuses' => ClientAssignment::STATUSES,
            'users' => User::query()->active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('assigned_user_id', 'unassigned_only', 'contact_status', 'search'),
            'canTransfer' => $user->can('asignaciones.transferir'),
            'canBulk' => $user->can('asignaciones.masivo'),
            'canGenerate' => $user->can('asignaciones.generar'),
            'canViewFinancials' => $user->can('finanzas.ver'),
        ]);
    }

    /**
     * Actualiza SOLO el seguimiento (estado + observaciones). NUNCA toca
     * assigned_user_id (ver docblock de UpdateClientAssignmentContactRequest).
     */
    public function updateContact(UpdateClientAssignmentContactRequest $request, ClientAssignment $assignment): RedirectResponse|JsonResponse
    {
        Gate::authorize('mutate', $assignment->year);

        $data = $request->validated();
        $markContactedNow = (bool) ($data['mark_contacted_now'] ?? true);
        unset($data['mark_contacted_now']);

        $assignment->update([
            ...$data,
            'last_contacted_at' => $markContactedNow ? now() : $assignment->last_contacted_at,
            'last_contacted_by' => $markContactedNow ? $request->user()->id : $assignment->last_contacted_by,
            'updated_by' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['assignment' => $assignment->fresh(['assignedUser:id,name', 'lastContactedBy:id,name'])]);
        }

        return back()->with('success', 'Seguimiento actualizado.');
    }

    /**
     * Autoasignarse una asignacion SIN responsable (seccion 2 y 9).
     * Fase 7 (correccion): delega en el servicio unico, que ademas propaga
     * el nuevo responsable a TODOS los pedidos de este cliente en esta
     * edicion (nunca en otras), para que /assignments y /clients nunca
     * queden desincronizados entre si ni con Pedidos.
     */
    public function selfAssign(Request $request, ClientAssignment $assignment, ClientAssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('selfAssign', $assignment);
        Gate::authorize('mutate', $assignment->year);

        $assignments->syncResponsibleForClientYear($assignment->client_id, $assignment->year_id, $request->user()->id, $request->user()->id);

        return back()->with('success', 'Cliente autoasignado.');
    }

    /**
     * Transferir una asignacion a otro usuario (tenga o no responsable
     * actual). Solo Logistica/Jefe de Logistica/Admin.
     */
    public function transfer(TransferClientAssignmentRequest $request, ClientAssignment $assignment, ClientAssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('mutate', $assignment->year);

        $assignments->syncResponsibleForClientYear(
            $assignment->client_id,
            $assignment->year_id,
            (int) $request->validated('assigned_user_id'),
            $request->user()->id
        );

        return back()->with('success', 'Asignacion transferida.');
    }

    /**
     * Asignacion manual masiva: solo aplica a asignaciones sin responsable.
     */
    public function bulkAssign(BulkAssignClientAssignmentsRequest $request, ClientAssignmentService $service): JsonResponse
    {
        $this->authorizeMutableAssignments($request->validated('assignment_ids'));

        $result = $service->bulkAssign(
            $request->validated('assignment_ids'),
            (int) $request->validated('assigned_user_id'),
            $request->user()->id,
        );

        return response()->json($result);
    }

    /**
     * Reparto equitativo entre los usuarios activos seleccionados.
     */
    public function bulkDistribute(BulkDistributeClientAssignmentsRequest $request, ClientAssignmentService $service): JsonResponse
    {
        $this->authorizeMutableAssignments($request->validated('assignment_ids'));

        $result = $service->bulkDistribute(
            $request->validated('assignment_ids'),
            $request->validated('user_ids'),
            $request->user()->id,
        );

        return response()->json($result);
    }

    /**
     * Fase 19: las acciones masivas reciben una lista de IDs que, en teoria,
     * podrian mezclar asignaciones de distintas ediciones (no hay nada que lo
     * impida a nivel de request). Se resuelven todos los años involucrados
     * (sin duplicados) y se autoriza cada uno con la misma regla centralizada
     * -- si UNA sola asignacion pertenece a una edicion no editable para este
     * usuario, toda la accion masiva se rechaza antes de tocar nada.
     */
    private function authorizeMutableAssignments(array $assignmentIds): void
    {
        $years = ClientAssignment::query()
            ->whereIn('id', $assignmentIds)
            ->with('year')
            ->get()
            ->pluck('year')
            ->filter()
            ->unique('id');

        foreach ($years as $year) {
            Gate::authorize('mutate', $year);
        }
    }

    /**
     * Previsualizacion de "Generar asignaciones desde edicion anterior"
     * (seccion 8): no persiste nada, solo devuelve el resumen esperado.
     */
    public function generatePreview(GenerateAssignmentsRequest $request, ClientAssignmentService $service): JsonResponse
    {
        $from = Year::findOrFail($request->validated('from_year_id'));
        $to = Year::findOrFail($request->validated('to_year_id'));

        return response()->json($service->generateFromPreviousYearPreview($from, $to));
    }

    /**
     * Ejecuta la generacion (transaccional e idempotente, ver
     * ClientAssignmentService::executeGenerateFromPreviousYear).
     */
    public function generate(GenerateAssignmentsRequest $request, ClientAssignmentService $service): JsonResponse
    {
        $from = Year::findOrFail($request->validated('from_year_id'));
        $to = Year::findOrFail($request->validated('to_year_id'));

        $summary = $service->executeGenerateFromPreviousYear($from, $to, $request->user()->id);

        return response()->json($summary);
    }

    /**
     * Exportacion a Excel (seccion 10). Incluye TODOS los clientes asignados
     * de la edicion, incluso sin pedido/sin responder/sin responsable.
     * Respeta los MISMOS filtros que index() si vienen en la query string
     * (?assigned_user_id=, ?unassigned_only=, ?contact_status=, ?search=);
     * sin filtros, exporta la lista completa de la edicion.
     *
     * IMPORTANTE: importe vendido / total pagado / saldo SOLO se agregan si
     * el usuario tiene 'finanzas.ver' (nunca se calculan ni se escriben al
     * archivo si no tiene el permiso).
     *
     * Requiere la dependencia phpoffice/phpspreadsheet (ver composer.json:
     * no estaba instalada en el proyecto, ver README de esta fase para el
     * comando exacto a correr).
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', ClientAssignment::class);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $canViewFinancials = $request->user()->can('finanzas.ver');

        // Fase 7, seccion 13: defensa en profundidad contra el bug detectado
        // (un pedido real cuyo cliente no tenia fila en client_year_assignments
        // quedaba fuera de esta exportacion). Ademas del backfill historico
        // (ver migracion 2026_07_15_000002), se garantiza aca mismo que TODO
        // cliente con al menos un pedido de esta edicion tenga su asignacion,
        // para que ninguna via futura pueda volver a producir el mismo hueco.
        $clientIdsWithOrders = Order::query()
            ->where('year_id', $year->id)
            ->pluck('client_id')
            ->unique();

        $clientIdsWithAssignment = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->whereIn('client_id', $clientIdsWithOrders)
            ->pluck('client_id');

        $clientIdsWithOrders->diff($clientIdsWithAssignment)->each(
            fn ($clientId) => ClientAssignment::firstOrCreate(['client_id' => $clientId, 'year_id' => $year->id])
        );

        $assignments = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->with([
                'client:id,historical_number,first_name,last_name,phone,address',
                'assignedUser:id,name',
            ])
            ->when($request->filled('assigned_user_id'), fn ($q) => $q->where('assigned_user_id', $request->get('assigned_user_id')))
            ->when($request->boolean('unassigned_only'), fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($request->filled('contact_status'), fn ($q) => $q->where('contact_status', $request->get('contact_status')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('client', fn ($cq) => $cq->searchTerm($request->get('search'))))
            ->orderBy('client_id')
            ->get();

        // Pedidos NO cancelados de estos clientes en esta edicion, para las
        // columnas "Compro" y "Cantidad de porciones" (y $ si corresponde).
        $orders = Order::query()
            ->where('year_id', $year->id)
            ->where('status', '!=', 'cancelado')
            ->whereIn('client_id', $assignments->pluck('client_id'))
            ->get(['client_id', 'total_portions', 'total_amount', 'total_paid', 'balance_due'])
            ->groupBy('client_id');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asignaciones');

        $headers = [
            'Numero historico', 'Nombre', 'Apellido', 'Telefono', 'Direccion',
            'Rover/usuario asignado', 'Estado de contacto', 'Ultimo contacto', 'Observaciones',
            'Compro', 'Cantidad de porciones',
        ];
        if ($canViewFinancials) {
            $headers = [...$headers, 'Importe vendido', 'Total pagado', 'Saldo pendiente'];
        }
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($assignments as $assignment) {
            $clientOrders = $orders->get($assignment->client_id, collect());
            $portions = (int) $clientOrders->sum('total_portions');
            $bought = $clientOrders->isNotEmpty();

            $line = [
                $assignment->client->historical_number,
                $assignment->client->first_name,
                $assignment->client->last_name,
                $assignment->client->phone,
                $assignment->client->address,
                $assignment->assignedUser?->name,
                ClientAssignment::STATUSES[$assignment->contact_status] ?? $assignment->contact_status,
                optional($assignment->last_contacted_at)->format('d/m/Y H:i'),
                $assignment->notes,
                $bought ? 'Si' : 'No',
                $portions,
            ];

            if ($canViewFinancials) {
                $line = [
                    ...$line,
                    (string) $clientOrders->sum('total_amount'),
                    (string) $clientOrders->sum('total_paid'),
                    (string) $clientOrders->sum('balance_due'),
                ];
            }

            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "asignaciones-{$year->year}-".now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
