<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDeleteClientsRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\ClientAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Fase 7, secciones 1 y 2: Clientes es ahora el centro unico de gestion
     * de clientes Y de su asignacion anual (antes repartido entre Clientes y
     * la pantalla separada de Asignaciones, ver ClientAssignmentController,
     * que se conserva intacta a nivel de backend/rutas para reutilizar su
     * logica y no romper nada, pero ya no tiene entrada en el menu superior).
     *
     * Soporta:
     * - year_id: edicion de la que se muestra la asignacion (por defecto la activa).
     * - search: nombre, apellido, nombre+apellido (en cualquier orden), telefono
     *   o numero historico (ver Client::scopeSearchTerm, seccion 4).
     * - sort / direction: ordenamiento de columnas.
     *
     * Se asegura (bulk insert) que cada cliente listado tenga una fila de
     * asignacion para la edicion seleccionada: asi la fusion visual con
     * Asignaciones puede mostrar y accionar sobre "responsable"/"estado de
     * seguimiento" para CUALQUIER cliente listado, incluso uno que todavia
     * nunca fue contactado (contact_status por defecto 'pendiente', mismo
     * comportamiento que ya tenia Asignaciones).
     *
     * Fase P2 (UX): sin paginacion, esta pantalla puede listar TODOS los
     * clientes de la edicion en una sola carga, asi que la creacion de
     * asignaciones faltantes ya no puede ser "como mucho 50 filas" (ver
     * comentario historico eliminado). Se hace con un UNICO insertOrIgnore
     * masivo (no N firstOrCreate en loop) para que siga siendo una sola
     * consulta de escritura sin importar cuantos clientes falten, en vez de
     * degradar a un N+1 al sacar el limite de 50.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $user = $request->user();

        $sort = in_array($request->get('sort'), ['first_name', 'last_name', 'phone', 'historical_number', 'created_at'])
            ? $request->get('sort')
            : 'last_name';
        $direction = $request->get('direction') === 'desc' ? 'desc' : 'asc';

        $clients = Client::query()
            ->when($request->filled('search'), fn ($query) => $query->searchTerm($request->get('search')))
            // Fase P2 (UX): filtro EXACTO por cliente, usado cuando se elige
            // una sugerencia del autocomplete (ver Clients/Index.vue,
            // pickSuggestion). A proposito NO reutiliza 'search': ese es un
            // LIKE tolerante (Client::scopeSearchTerm) que tambien matchea
            // contra telefono, asi que un N° historico corto podia traer de
            // rebote clientes cuyo telefono contuviera esa secuencia.
            ->when($request->filled('client_id'), fn ($query) => $query->where('id', $request->get('client_id')))
            // Fase 21: filtro por "Rover encargado" — un cliente puede
            // reasignarse a otro Rover durante la venta (el original dejo de
            // llamarlo), asi que este filtro busca DENTRO de los clientes
            // asignados a un usuario puntual en la edicion seleccionada, en
            // vez de tener que recorrer toda la lista. Mismo criterio que ya
            // usa ClientAssignmentController::index (?assigned_user_id=).
            // Convive con 'search' (ambos son ->when() sobre la misma query,
            // se combinan con AND) y con 'my_assigned_clients' de abajo (ese
            // es el atajo "a mi mismo", este permite elegir cualquier rover).
            ->when($request->filled('assigned_user_id'), function ($query) use ($year, $request) {
                $query->whereIn('id', ClientAssignment::query()
                    ->where('year_id', $year->id)
                    ->where('assigned_user_id', $request->get('assigned_user_id'))
                    ->select('client_id')
                );
            })
            // Fase 8 (correccion): "Mis clientes asignados" — clientes que
            // tienen assigned_user_id = auth user en client_year_assignments
            // para la edicion seleccionada.
            ->when($request->boolean('my_assigned_clients'), function ($query) use ($user, $year) {
                $query->whereIn('id', ClientAssignment::query()
                    ->where('year_id', $year->id)
                    ->where('assigned_user_id', $user->id)
                    ->select('client_id')
                );
            })
            ->orderBy($sort, $direction)
            // Fase 8 (correccion): orden alfabetico secundario por first_name
            // cuando se ordena por last_name (el caso por defecto), para que
            // personas con el mismo apellido queden en orden predecible.
            ->when($sort === 'last_name', fn ($q) => $q->orderBy('first_name', $direction))
            // Fase P2 (UX): se elimina la paginacion a pedido explicito del
            // usuario (una sola lista larga, igual que la pagina anterior).
            // Los filtros/busqueda ya se aplicaron arriba, asi que $clients
            // solo trae las filas que matchean, no toda la tabla.
            ->get();

        // Garantiza que cada cliente ACTIVO listado tenga asignacion para
        // $year (ver docblock de arriba). insertOrIgnore en un solo INSERT
        // (no un loop de N firstOrCreate): sigue siendo una unica consulta de
        // escritura sin importar cuantos clientes falten, y si dos requests
        // concurrentes llegaran a pisarse la unique(client_id,year_id) de la
        // tabla lo ignora en vez de tirar un error de integridad.
        //
        // Clientes con is_active=false quedan afuera de este backfill a
        // proposito (ver Client::deactivate): no se les debe generar trabajo
        // de contacto automaticamente hacia ediciones futuras. Si ya tenian
        // una asignacion de una edicion anterior (o de esta misma, creada
        // antes de desactivarse), esa fila NO se toca ni se borra: sigue
        // apareciendo intacta, esto solo evita CREAR una nueva.
        $activeClientIds = $clients->where('is_active', true)->pluck('id');

        $existingClientIds = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->whereIn('client_id', $activeClientIds)
            ->pluck('client_id');

        $missingClientIds = $activeClientIds->diff($existingClientIds);
        if ($missingClientIds->isNotEmpty()) {
            $now = now();
            ClientAssignment::insertOrIgnore($missingClientIds->map(fn ($clientId) => [
                'client_id' => $clientId,
                'year_id' => $year->id,
                'contact_status' => ClientAssignment::STATUS_PENDIENTE,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        $assignments = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->whereIn('client_id', $clients->pluck('id'))
            ->with(['assignedUser:id,name', 'lastContactedBy:id,name'])
            ->get()
            ->keyBy('client_id');

        // Fase 21: "Ultima compra" en la tabla, sin traer el historial
        // completo (eso sigue viviendo solo en Clients/History, ver
        // ClientController::history). Se resuelve con UNA sola consulta
        // agregada (MAX(year) por client_id sobre pedidos NO cancelados,
        // mismo criterio de "pedido valido" que compactCounters/roverRanking/
        // export en OrderController/ClientAssignmentController), en vez de
        // consultar por cliente uno a uno (evita N+1 sin importar cuantos
        // clientes liste la pantalla).
        $lastPurchaseYears = Order::query()
            ->join('years', 'years.id', '=', 'orders.year_id')
            ->select('orders.client_id', DB::raw('MAX(years.year) as last_purchase_year'))
            ->whereIn('orders.client_id', $clients->pluck('id'))
            ->where('orders.status', '!=', 'cancelado')
            ->groupBy('orders.client_id')
            ->pluck('last_purchase_year', 'orders.client_id');

        $clients->transform(function (Client $client) use ($assignments, $lastPurchaseYears) {
            $client->setRelation('yearAssignment', $assignments->get($client->id));
            $client->setAttribute('last_purchase_year', $lastPurchaseYears->get($client->id));

            return $client;
        });

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'year' => $year,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            'statuses' => ClientAssignment::STATUSES,
            'users' => User::query()->active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'client_id', 'sort', 'direction', 'my_assigned_clients', 'assigned_user_id'),
            // Permisos administrativos de asignacion (seccion 2): solo
            // Logistica/Jefe de Logistica/Admin (ver RolesAndPermissionsSeeder).
            'canTransfer' => $user->can('asignaciones.transferir'),
            'canBulk' => $user->can('asignaciones.masivo'),
            'canGenerate' => $user->can('asignaciones.generar'),
            'canViewFinancials' => $user->can('finanzas.ver'),
            // Fase 21: gatea el boton "Exportar Excel" (ver ClientAssignmentPolicy::export).
            'canExport' => $user->can('clientes.exportar'),
        ]);
    }

    /**
     * Autocomplete liviano para el picker de clientes en la pantalla de alta
     * de pedido (Fase 4). Devuelve JSON, no es una Inertia visit. Limitado a
     * 15 resultados: es para tipear y elegir, no un listado completo (para eso
     * ya esta /clients).
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $term = trim((string) $request->get('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        $clients = Client::query()
            ->searchTerm($term)
            ->orderBy('last_name')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'phone', 'address', 'historical_number']);

        return response()->json($clients);
    }

    public function store(StoreClientRequest $request): RedirectResponse|JsonResponse
    {
        // Fase 7, seccion 3: el numero historico se asigna automaticamente,
        // de forma atomica y sin condiciones de carrera (ver
        // Client::createWithAutoHistoricalNumber).
        $client = Client::createWithAutoHistoricalNumber([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['client' => $client]);
        }

        return back()->with('success', "Cliente {$client->full_name} creado.");
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse|JsonResponse
    {
        $client->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['client' => $client->fresh()]);
        }

        return back()->with('success', 'Cliente actualizado.');
    }

    /**
     * Fase 7, seccion 2/6: wrappers "por cliente + edicion" de las mismas
     * acciones de ClientAssignmentController, para que la pantalla fusionada
     * de Clientes no necesite conocer el id interno de la asignacion (que
     * incluso puede no existir todavia). Reutilizan la MISMA autorizacion y
     * el MISMO servicio (ClientAssignmentService); no duplican logica de
     * negocio, solo resuelven/crean la asignacion antes de delegar.
     */
    public function selfAssignForYear(Request $request, Client $client, ClientAssignmentService $assignments): RedirectResponse
    {
        $year = Year::findOrFail($request->integer('year_id'));
        Gate::authorize('mutate', $year);
        $assignment = ClientAssignment::firstOrCreate(['client_id' => $client->id, 'year_id' => $year->id]);

        Gate::authorize('selfAssign', $assignment);

        // Fase 7 (correccion): delega en el servicio unico para que tambien
        // se propague a TODOS los pedidos de este cliente en esta edicion
        // (ver ClientAssignmentService::syncResponsibleForClientYear).
        $assignments->syncResponsibleForClientYear($client->id, $year->id, $request->user()->id, $request->user()->id);

        return back()->with('success', 'Cliente autoasignado.');
    }

    public function transferForYear(Request $request, Client $client, ClientAssignmentService $assignments): RedirectResponse
    {
        $year = Year::findOrFail($request->integer('year_id'));
        Gate::authorize('mutate', $year);
        $request->validate(['assigned_user_id' => ['required', 'integer', 'exists:users,id']]);

        $assignment = ClientAssignment::firstOrCreate(['client_id' => $client->id, 'year_id' => $year->id]);

        Gate::authorize('transfer', $assignment);

        // Fase 7 (correccion): idem selfAssignForYear, propaga a todos los
        // pedidos del cliente en esta edicion (nunca a otros anios).
        $assignments->syncResponsibleForClientYear($client->id, $year->id, (int) $request->integer('assigned_user_id'), $request->user()->id);

        return back()->with('success', 'Asignacion transferida.');
    }

    public function updateContactForYear(Request $request, Client $client): RedirectResponse
    {
        $year = Year::findOrFail($request->integer('year_id'));
        Gate::authorize('mutate', $year);
        $data = $request->validate([
            'contact_status' => ['required', 'string', 'in:pendiente,no_respondio,volver_a_llamar,no_interesado,interesado,pedido_realizado'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mark_contacted_now' => ['sometimes', 'boolean'],
        ]);

        $assignment = ClientAssignment::firstOrCreate(['client_id' => $client->id, 'year_id' => $year->id]);

        Gate::authorize('updateContact', $assignment);

        $markContactedNow = (bool) ($data['mark_contacted_now'] ?? true);
        unset($data['mark_contacted_now']);

        $assignment->update([
            ...$data,
            'last_contacted_at' => $markContactedNow ? now() : $assignment->last_contacted_at,
            'last_contacted_by' => $markContactedNow ? $request->user()->id : $assignment->last_contacted_by,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Seguimiento actualizado.');
    }

    /**
     * Reemplaza "Quitar de la edicion" (ver informe de la correccion de
     * arquitectura: borrar la asignacion anual quedaba deshecho por el
     * backfill automatico de index() en la siguiente carga, ademas de ser
     * una accion por-edicion para algo que en la practica es una propiedad
     * global del cliente). Marca al cliente como fuera de la base activa: no
     * borra ni toca pedidos ni asignaciones de NINGUNA edicion (pasada o
     * presente), solo deja de generarsele trabajo de contacto automatico
     * hacia ediciones futuras (ver backfill de index() y
     * ClientAssignmentService::generateFromPreviousYear).
     *
     * Orquestacion (correccion posterior, pedido explicito del usuario):
     * Client::deactivate() SOLO persiste los campos propios del cliente, sin
     * resolver ningun servicio via el contenedor. Este metodo es el caso de
     * uso real: ata esa escritura con
     * ClientAssignmentService::clearResponsibleForActiveEdition() (saca al
     * responsable de la edicion activa, ver docblock de ese metodo) dentro
     * de una misma transaccion. DEUDA TECNICA: si aparece otro llamador que
     * necesite desactivar un cliente (job, comando, otro controller), debe
     * repetir esta misma dupla -- el dia que eso pase, corresponde extraer
     * un servicio de aplicacion dedicado (ver docblock de Client::deactivate).
     */
    public function deactivate(Request $request, Client $client, ClientAssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('deactivate', $client);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $client, $assignments, $data) {
            $client->deactivate($request->user()->id, $data['reason'] ?? null);
            $assignments->clearResponsibleForActiveEdition($client->id, $request->user()->id);
        });

        return back()->with('success', 'Cliente desactivado.');
    }

    /**
     * Reactivacion MANUAL (boton "Reactivar"). La reactivacion automatica
     * por pedido real vive en ClientAssignmentService::syncFromOrder (solo
     * cuando el pedido pertenece a la edicion activa), no aca.
     */
    public function reactivate(Client $client): RedirectResponse
    {
        Gate::authorize('reactivate', $client);

        $client->reactivate();

        return back()->with('success', 'Cliente reactivado.');
    }

    /**
     * Fase 7, seccion 2: eliminacion DEFINITIVA del registro maestro del
     * cliente. Se distingue deliberadamente de removeFromYear() (arriba):
     * si el cliente tiene pedidos (en cualquier edicion, incluidos los ya
     * soft-deleted, para no perder trazabilidad de un pedido borrado), NO se
     * permite la eliminacion definitiva, para no destruir historial ni
     * trazabilidad (ver seccion 2 y 19 del prompt de la fase). En ese caso
     * se sugiere "quitar de la edicion" en su lugar.
     */
    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);

        if ($client->orders()->withTrashed()->exists()) {
            return back()->withErrors([
                'client' => 'Este cliente tiene pedidos registrados y no puede eliminarse definitivamente (se perderia trazabilidad). Podes quitarlo de una edicion puntual desde la pantalla de Clientes.',
            ]);
        }

        $client->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    /**
     * Usa binding implicito con withTrashed() en la ruta (ver routes/web.php),
     * por eso el parametro ya se tipa como Client: Laravel resuelve el modelo
     * aunque este soft-deleted gracias al macro withTrashed() de la ruta.
     */
    public function restore(Client $client): RedirectResponse
    {
        Gate::authorize('restore', $client);

        $client->restore();

        return back()->with('success', 'Cliente restaurado.');
    }

    /**
     * Elimina varios clientes de una. Se re-valida permiso por cada operacion
     * (no se confia en que el frontend ya filtro que id puede o no borrarse).
     */
    public function bulkDestroy(BulkDeleteClientsRequest $request): RedirectResponse
    {
        $ids = $request->validated('ids');
        $count = 0;
        $skippedWithOrders = 0;

        Client::query()->whereIn('id', $ids)->get()->each(function (Client $client) use (&$count, &$skippedWithOrders) {
            Gate::authorize('delete', $client);

            if ($client->orders()->withTrashed()->exists()) {
                $skippedWithOrders++;

                return;
            }

            $client->delete();
            $count++;
        });

        $message = "{$count} clientes eliminados.";
        if ($skippedWithOrders > 0) {
            $message .= " {$skippedWithOrders} no se eliminaron por tener pedidos registrados (usa \"quitar de la edicion\" en su lugar).";
        }

        return back()->with('success', $message);
    }

    /**
     * Historial completo de un cliente: pedidos + observaciones agrupados por anio.
     * Soporta ?year_id= para ver un anio puntual, o sin parametro para ver todo
     * el historial completo (todos los anios), tal como pide la Fase 3.
     *
     * SEGURIDAD (fix de revision): un usuario sin 'pedidos.ver-todos' (ej. un Rover)
     * NUNCA debe ver pedidos (ni sus montos) de OTROS rovers, ni siquiera dentro del
     * historial de un cliente compartido. Antes de este fix, history() traia TODOS
     * los pedidos del cliente sin este filtro - era el mismo hueco que ya se evitaba
     * correctamente en OrderController::index. Se aplica la misma regla aca.
     */
    public function history(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $user = $request->user();
        $canViewAll = $user->can('pedidos.ver-todos');

        $client->load([
            'orders' => function ($query) use ($request, $canViewAll, $user) {
                $query->with(['items', 'payments.method', 'rover:id,name', 'year:id,year,label'])
                    ->when(! $canViewAll, fn ($q) => $q->where('rover_id', $user->id))
                    ->when($request->filled('year_id'), fn ($q) => $q->where('year_id', $request->get('year_id')))
                    ->orderByDesc('created_at');
            },
            'observations' => function ($query) use ($request) {
                $query->with(['year:id,year', 'createdBy:id,name'])
                    ->when($request->filled('year_id'), fn ($q) => $q->where('year_id', $request->get('year_id')))
                    ->orderByDesc('created_at');
            },
        ]);

        return Inertia::render('Clients/History', [
            'client' => $client,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label']),
            'selectedYearId' => $request->integer('year_id') ?: null,
        ]);
    }
}
