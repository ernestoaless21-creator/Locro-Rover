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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 8: gráfico de porciones por Rover, filtro "Mis clientes asignados"
 * y filtro "Mis pedidos cargados".
 *
 * Patron de tests: mismo que OrderCompactIndicatorsTest (RefreshDatabase +
 * RolesAndPermissionsSeeder + Year::where('year', 2026)->firstOrFail()).
 * NO se usa Year::create() porque el seeder ya crea el anio 2026.
 * NO se asume que Order::create() dispara ClientAssignmentService
 * (eso solo ocurre a traves del controlador): los ClientAssignment se crean
 * manualmente cuando los tests los necesitan.
 */
class OrderPhase8Test extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    // ---------- helpers -------------------------------------------------------

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    protected function makeRover(): User
    {
        $role = Role::firstOrCreate(['name' => 'rover_test', 'guard_name' => 'web']);
        $role->givePermissionTo(['pedidos.ver', 'pedidos.crear', 'pedidos.editar', 'pedidos.ver-todos']);

        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('rover_test');

        return $u;
    }

    protected function makeLimitedRover(): User
    {
        $role = Role::firstOrCreate(['name' => 'rover_limited', 'guard_name' => 'web']);
        $role->givePermissionTo(['pedidos.ver', 'pedidos.crear', 'pedidos.editar']);

        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('rover_limited');

        return $u;
    }

    /**
     * Crea un pedido con PricingService para que total_portions quede correcto.
     * $roverId es el Rover responsable actual; $creatorId es quien lo registra
     * (pueden ser distintos para testear la diferencia semantica).
     */
    protected function makeOrder(int $roverId, int $creatorId, int $portions = 2, ?string $status = null, ?int $yearId = null): Order
    {
        $client = Client::create(['first_name' => 'Test', 'last_name' => (string) random_int(1, 999999)]);
        $year = $yearId ? Year::findOrFail($yearId) : $this->year;

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $year->id,
            'rover_id' => $roverId,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'take_away' => true,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, $portions, $year, $creatorId);

        if ($status !== null) {
            $order->update(['status' => $status]);
        }

        return $order->fresh();
    }

    // ---------- GRÁFICO DE PORCIONES POR ROVER --------------------------------

    public function test_rover_ranking_groups_by_rover_id_and_sums_portions(): void
    {
        $admin = $this->makeAdmin();
        $roverA = User::factory()->create(['name' => 'Rover A', 'is_active' => true]);
        $roverB = User::factory()->create(['name' => 'Rover B', 'is_active' => true]);

        $this->makeOrder($roverA->id, $roverA->id, 3);
        $this->makeOrder($roverA->id, $roverA->id, 5);
        $this->makeOrder($roverB->id, $roverB->id, 4);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('roverRanking', 2)
            ->where('roverRanking.0.rover_id', $roverA->id)
            ->where('roverRanking.0.total_portions', 8) // 3+5
            ->where('roverRanking.1.rover_id', $roverB->id)
            ->where('roverRanking.1.total_portions', 4)
        );
    }

    public function test_rover_ranking_excludes_cancelled_orders(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Rover Cancel', 'is_active' => true]);

        $this->makeOrder($rover->id, $rover->id, 5);
        $this->makeOrder($rover->id, $rover->id, 3, status: 'cancelado');

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('roverRanking', 1)
            ->where('roverRanking.0.rover_id', $rover->id)
            ->where('roverRanking.0.total_portions', 5) // cancelado excluido
        );
    }

    public function test_rover_ranking_excludes_orders_without_rover(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Rover Con', 'is_active' => true]);

        $client = Client::create(['first_name' => 'Sin', 'last_name' => 'Rover']);
        $orderWithoutRover = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($orderWithoutRover, 10, $this->year, $admin->id);

        $this->makeOrder($rover->id, $rover->id, 2);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        // Solo el rover con rover_id aparece; el pedido sin rover_id queda fuera.
        $response->assertInertia(fn ($page) => $page
            ->has('roverRanking', 1)
            ->where('roverRanking.0.rover_id', $rover->id)
        );
    }

    public function test_rover_ranking_respects_year_id(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Rover Anio', 'is_active' => true]);

        $otherYear = Year::create([
            'year' => 2024,
            'label' => 'Locro 2024',
            'is_active' => false,
            'event_type' => 'locro',
        ]);

        $this->makeOrder($rover->id, $rover->id, 10, yearId: $otherYear->id);
        $this->makeOrder($rover->id, $rover->id, 2); // en el año activo

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('roverRanking.0.total_portions', 2) // solo las del año activo
        );
    }

    public function test_rover_ranking_is_ordered_descending(): void
    {
        $admin = $this->makeAdmin();
        $roverA = User::factory()->create(['name' => 'Primer Puesto', 'is_active' => true]);
        $roverB = User::factory()->create(['name' => 'Segundo Puesto', 'is_active' => true]);
        $roverC = User::factory()->create(['name' => 'Tercer Puesto', 'is_active' => true]);

        $this->makeOrder($roverB->id, $roverB->id, 5);
        $this->makeOrder($roverA->id, $roverA->id, 10);
        $this->makeOrder($roverC->id, $roverC->id, 3);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('roverRanking.0.rover_id', $roverA->id) // 10 porciones
            ->where('roverRanking.1.rover_id', $roverB->id) // 5 porciones
            ->where('roverRanking.2.rover_id', $roverC->id) // 3 porciones
        );
    }

    public function test_rover_ranking_does_not_attribute_by_created_by(): void
    {
        $admin = $this->makeAdmin();
        $roverA = User::factory()->create(['name' => 'Rover Responsable', 'is_active' => true]);
        $roverB = User::factory()->create(['name' => 'Rover Creador', 'is_active' => true]);

        // roverB carga el pedido (created_by = roverB) pero la venta es de roverA
        $this->makeOrder($roverA->id, creatorId: $roverB->id, portions: 5);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('roverRanking', 1)
            ->where('roverRanking.0.rover_id', $roverA->id) // atribuido al responsable, no al creador
            ->where('roverRanking.0.total_portions', 5)
        );
    }

    public function test_rover_ranking_is_null_for_user_without_view_all_permission(): void
    {
        $limited = $this->makeLimitedRover();
        $this->makeOrder($limited->id, $limited->id, 3);

        $response = $this->actingAs($limited)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('roverRanking', null));
    }

    public function test_rover_ranking_is_empty_array_when_no_valid_orders(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('roverRanking', []));
    }

    // ---------- FILTRO: MIS CLIENTES ASIGNADOS --------------------------------

    public function test_my_assigned_clients_shows_orders_of_clients_assigned_to_auth_user(): void
    {
        $admin = $this->makeAdmin();
        $roverA = User::factory()->create(['is_active' => true]);

        $order = $this->makeOrder($roverA->id, $roverA->id, 2);

        // Asignar manualmente el cliente al admin en esta edicion.
        ClientAssignment::create([
            'client_id' => $order->client_id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $admin->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $order->id)
        );
    }

    public function test_my_assigned_clients_excludes_orders_of_clients_assigned_to_other_user(): void
    {
        $admin = $this->makeAdmin();
        $roverA = User::factory()->create(['is_active' => true]);
        $roverB = User::factory()->create(['is_active' => true]);

        $orderA = $this->makeOrder($roverA->id, $roverA->id, 2);
        $orderB = $this->makeOrder($roverB->id, $roverB->id, 3);

        // Solo el cliente de orderA está asignado al admin.
        ClientAssignment::create([
            'client_id' => $orderA->client_id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $admin->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        // El cliente de orderB está asignado a roverB, no al admin.
        ClientAssignment::create([
            'client_id' => $orderB->client_id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $roverB->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $roverB->id,
            'updated_by' => $roverB->id,
        ]);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('orders.total', 1));
    }

    public function test_my_assigned_clients_respects_year_id(): void
    {
        $admin = $this->makeAdmin();

        $otherYear = Year::create([
            'year' => 2024,
            'label' => 'Locro 2024',
            'is_active' => false,
            'event_type' => 'locro',
        ]);

        $order = $this->makeOrder($admin->id, $admin->id, 2);

        // Asignacion solo en el año 2024, NO en el año activo (2026).
        ClientAssignment::create([
            'client_id' => $order->client_id,
            'year_id' => $otherYear->id,
            'assigned_user_id' => $admin->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        // Filtrando en el año activo: no debe aparecer (la asignacion es de 2024).
        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('orders.total', 0));
    }

    public function test_my_assigned_clients_combines_with_existing_filter_payment_status(): void
    {
        $admin = $this->makeAdmin();

        $orderPendiente = $this->makeOrder($admin->id, $admin->id, 2);
        $orderPagado = $this->makeOrder($admin->id, $admin->id, 3);

        foreach ([$orderPendiente->client_id, $orderPagado->client_id] as $clientId) {
            ClientAssignment::create([
                'client_id' => $clientId,
                'year_id' => $this->year->id,
                'assigned_user_id' => $admin->id,
                'contact_status' => ClientAssignment::STATUS_PENDIENTE,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }

        // El año del seeder no tiene portion_price, por lo que total_amount
        // puede ser 0. Se fuerza directamente total_paid > 0 para que el
        // filtro payment_status=pendiente (where total_paid = 0) lo excluya.
        Order::where('id', $orderPagado->id)->update(['total_paid' => 1000]);

        // Con payment_status=pendiente + my_assigned_clients: solo el sin pago.
        $response = $this->actingAs($admin)->get(
            "/orders?year_id={$this->year->id}&my_assigned_clients=1&payment_status=pendiente"
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $orderPendiente->id)
        );
    }

    // ---------- FILTRO: MIS PEDIDOS CARGADOS ----------------------------------

    public function test_created_by_me_filters_by_created_by(): void
    {
        $admin = $this->makeAdmin();
        $roverOther = User::factory()->create(['is_active' => true]);

        $myOrder = $this->makeOrder($admin->id, creatorId: $admin->id, portions: 2);
        $otherOrder = $this->makeOrder($admin->id, creatorId: $roverOther->id, portions: 3);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&created_by_me=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $myOrder->id)
        );
    }

    public function test_created_by_me_does_not_confuse_created_by_with_rover_id(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['is_active' => true]);

        // Admin carga el pedido (created_by=admin) pero lo asigna al rover (rover_id=rover).
        $orderCreatedByAdmin = $this->makeOrder($rover->id, creatorId: $admin->id, portions: 5);
        // Rover carga el pedido y también es responsable.
        $orderCreatedByRover = $this->makeOrder($rover->id, creatorId: $rover->id, portions: 3);

        // Filtrando "mis pedidos cargados" como admin: solo el que creó admin.
        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&created_by_me=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $orderCreatedByAdmin->id)
        );
    }

    public function test_created_by_me_combines_with_delivery_type_filter(): void
    {
        $admin = $this->makeAdmin();
        $other = User::factory()->create(['is_active' => true]);

        // Admin crea dos pedidos: uno delivery, uno retiro.
        $client1 = Client::create(['first_name' => 'A', 'last_name' => 'One']);
        $orderDelivery = Order::create([
            'client_id' => $client1->id,
            'year_id' => $this->year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => false,
            'delivery_address' => 'Av. Test 123',
        ]);
        app(PricingService::class)->syncPortionsForOrder($orderDelivery, 2, $this->year, $admin->id);

        $client2 = Client::create(['first_name' => 'B', 'last_name' => 'Two']);
        $orderRetiro = Order::create([
            'client_id' => $client2->id,
            'year_id' => $this->year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($orderRetiro, 2, $this->year, $admin->id);

        // Pedido delivery cargado por otro (no debe aparecer).
        $this->makeOrder($other->id, creatorId: $other->id, portions: 1);

        $response = $this->actingAs($admin)->get(
            "/orders?year_id={$this->year->id}&created_by_me=1&delivery_type=delivery"
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.id', $orderDelivery->id)
        );
    }

    // ---------- COMBINACION Y REGRESION ---------------------------------------

    public function test_existing_phase7_filters_still_work(): void
    {
        $admin = $this->makeAdmin();

        $client1 = Client::create(['first_name' => 'A', 'last_name' => 'One']);
        $orderDelivery = Order::create([
            'client_id' => $client1->id,
            'year_id' => $this->year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => false,
            'delivery_address' => 'Calle Test 1',
        ]);
        app(PricingService::class)->syncPortionsForOrder($orderDelivery, 2, $this->year, $admin->id);

        $client2 = Client::create(['first_name' => 'B', 'last_name' => 'Two']);
        $orderRetiro = Order::create([
            'client_id' => $client2->id,
            'year_id' => $this->year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($orderRetiro, 2, $this->year, $admin->id);

        // Filtro delivery_type (Fase 7) sigue funcionando.
        $this->actingAs($admin)
            ->get("/orders?year_id={$this->year->id}&delivery_type=delivery")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1));

        // Filtro withdrawal_status (existente) sigue funcionando.
        $this->actingAs($admin)
            ->get("/orders?year_id={$this->year->id}&withdrawal_status=no_retirado")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 2));
    }

    public function test_pagination_preserves_new_filter_params(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(
            "/orders?year_id={$this->year->id}&my_assigned_clients=1&created_by_me=1&delivery_type=delivery"
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.my_assigned_clients', '1')
            ->where('filters.created_by_me', '1')
            ->where('filters.delivery_type', 'delivery')
        );
    }

    public function test_permissions_are_not_broken_by_phase8_changes(): void
    {
        $limited = $this->makeLimitedRover();
        $other = User::factory()->create(['is_active' => true]);

        // El usuario limitado solo puede ver sus propios pedidos (no tiene
        // 'pedidos.ver-todos'). Aunque mande my_assigned_clients=1, la
        // restriccion base (rover_id = auth user) sigue vigente.
        $this->makeOrder($other->id, $other->id, 5);
        $myOrder = $this->makeOrder($limited->id, $limited->id, 2);

        ClientAssignment::create([
            'client_id' => $myOrder->client_id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $limited->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $limited->id,
            'updated_by' => $limited->id,
        ]);

        // Sin filtros: solo ve sus propios pedidos (not 'pedidos.ver-todos').
        $this->actingAs($limited)
            ->get("/orders?year_id={$this->year->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1));

        // Con my_assigned_clients: sigue viendo solo los suyos (interseccion).
        $this->actingAs($limited)
            ->get("/orders?year_id={$this->year->id}&my_assigned_clients=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1));

        // No recibe roverRanking (sin pedidos.ver-todos).
        $this->actingAs($limited)
            ->get("/orders?year_id={$this->year->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('roverRanking', null));
    }
}
