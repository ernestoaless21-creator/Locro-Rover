<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 21: 3 mejoras de Clientes/Permisos pedidas juntas.
 *  - Filtro "Rover encargado" en /clients (?assigned_user_id=), analogo al
 *    que ya tenia /assignments (ver ClientAssignmentController::index).
 *  - Columna "Ultima compra" (solo el AÑO, sin traer el historial completo).
 *  - /assignments/export (boton "Exportar Excel" de Clientes/Asignaciones)
 *    ahora requiere 'clientes.exportar' en vez de 'asignaciones.ver' (comun
 *    a todos los roles operativos).
 */
class ClientRoverFilterExportAndLastPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    // ---------- Filtro por Rover encargado --------------------------------

    public function test_assigned_user_id_filter_shows_only_clients_assigned_to_that_rover(): void
    {
        $admin = $this->makeAdmin();
        $pedro = User::factory()->create(['is_active' => true, 'name' => 'Pedro']);
        $otro = User::factory()->create(['is_active' => true, 'name' => 'Otro']);

        $clientePedro = Client::create(['first_name' => 'Cliente', 'last_name' => 'DePedro', 'created_by' => $admin->id]);
        $clienteOtro = Client::create(['first_name' => 'Cliente', 'last_name' => 'DeOtro', 'created_by' => $admin->id]);

        ClientAssignment::create([
            'client_id' => $clientePedro->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $pedro->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        ClientAssignment::create([
            'client_id' => $clienteOtro->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $otro->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}&assigned_user_id={$pedro->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clients', 1)
            ->where('clients.0.id', $clientePedro->id)
        );
    }

    /**
     * El filtro por Rover debe convivir con el buscador: el buscador solo
     * debe encontrar resultados DENTRO de los clientes ya filtrados por Rover.
     */
    public function test_assigned_user_id_filter_combines_with_search(): void
    {
        $admin = $this->makeAdmin();
        $pedro = User::factory()->create(['is_active' => true]);

        $match = Client::create(['first_name' => 'Josefina', 'last_name' => 'Pedro', 'created_by' => $admin->id]);
        $sameNameDifferentRover = Client::create(['first_name' => 'Josefina', 'last_name' => 'Ajena', 'created_by' => $admin->id]);

        ClientAssignment::create([
            'client_id' => $match->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $pedro->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}&assigned_user_id={$pedro->id}&search=Josefina");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clients', 1)
            ->where('clients.0.id', $match->id)
        );

        $this->assertNotSame($match->id, $sameNameDifferentRover->id);
    }

    // ---------- Ultima compra ----------------------------------------------

    public function test_last_purchase_year_reflects_most_recent_non_cancelled_order(): void
    {
        $admin = $this->makeAdmin();
        $pricing = app(PricingService::class);

        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false, 'event_type' => 'locro']);
        $recentYear = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false, 'event_type' => 'locro']);

        $withOrders = Client::create(['first_name' => 'Con', 'last_name' => 'Pedidos', 'created_by' => $admin->id]);
        $never = Client::create(['first_name' => 'Sin', 'last_name' => 'Pedidos', 'created_by' => $admin->id]);

        foreach ([$oldYear, $recentYear] as $year) {
            $order = Order::create([
                'client_id' => $withOrders->id,
                'year_id' => $year->id,
                'rover_id' => $admin->id,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
                'take_away' => true,
            ]);
            $pricing->syncPortionsForOrder($order, 2, $year, $admin->id);
        }

        // Pedido cancelado en la edicion activa: NO debe contar como "ultima compra".
        $cancelledOrder = Order::create([
            'client_id' => $withOrders->id,
            'year_id' => $this->year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
            'status' => 'cancelado',
        ]);
        $pricing->syncPortionsForOrder($cancelledOrder, 2, $this->year, $admin->id);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}");

        $response->assertOk();

        $payload = $response->viewData('page')['props']['clients'];
        $byId = collect($payload)->keyBy('id');

        $this->assertSame(2025, $byId[$withOrders->id]['last_purchase_year']);
        $this->assertNull($byId[$never->id]['last_purchase_year']);
    }

    // ---------- Permiso de exportacion -------------------------------------

    public function test_operational_user_without_clientes_exportar_cannot_export(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('publicidad'); // rol operativo comun, sin clientes.exportar

        $response = $this->actingAs($user)->get("/assignments/export?year_id={$this->year->id}");

        $response->assertForbidden();
    }

    public function test_logistica_can_export(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica');

        $response = $this->actingAs($user)->get("/assignments/export?year_id={$this->year->id}");

        $response->assertOk();
    }

    public function test_admin_can_export(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get("/assignments/export?year_id={$this->year->id}");

        $response->assertOk();
    }
}
