<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\ClientAssignmentService;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7 (correccion 2), seccion 1: "orders.rover_id" y
 * "client_year_assignments.assigned_user_id" deben representar al MISMO
 * responsable y mantenerse SIEMPRE sincronizados, propagando a TODOS los
 * pedidos del mismo cliente/edicion (nunca a otros anios). Ver
 * ClientAssignmentService::syncResponsibleForClientYear.
 */
class ClientAssignmentPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $juan;

    protected User $ernesto;

    protected Client $client;

    protected Year $year2025;

    protected Year $year2026;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->juan = User::factory()->create(['is_active' => true]);
        $this->juan->assignRole('logistica');

        $this->ernesto = User::factory()->create(['is_active' => true]);
        $this->ernesto->assignRole('logistica');

        $this->year2025 = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false, 'portion_price' => 15000]);
        $this->year2026 = Year::where('year', 2026)->firstOrFail();

        $this->client = Client::create(['first_name' => 'Pepito', 'last_name' => 'Cliente']);
    }

    protected function makeOrder(Year $year, User $rover): Order
    {
        $order = Order::create([
            'client_id' => $this->client->id,
            'year_id' => $year->id,
            'rover_id' => $rover->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 1, $year, $this->admin->id);

        app(ClientAssignmentService::class)->syncFromOrder($order);

        return $order->fresh();
    }

    public function test_transfer_from_clients_propagates_to_all_orders_of_the_same_client_and_year(): void
    {
        $orderA = $this->makeOrder($this->year2026, $this->juan);
        $orderB = $this->makeOrder($this->year2026, $this->juan);

        $this->actingAs($this->admin)->post("/clients/{$this->client->id}/assignment/transfer", [
            'year_id' => $this->year2026->id,
            'assigned_user_id' => $this->ernesto->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->ernesto->id, $orderA->fresh()->rover_id);
        $this->assertSame($this->ernesto->id, $orderB->fresh()->rover_id);

        $assignment = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2026->id)->first();
        $this->assertSame($this->ernesto->id, $assignment->assigned_user_id);
    }

    public function test_rover_change_from_orders_propagates_to_all_other_orders_of_the_same_client_and_year(): void
    {
        $orderA = $this->makeOrder($this->year2026, $this->juan);
        $orderB = $this->makeOrder($this->year2026, $this->juan);

        $this->actingAs($this->admin)->put("/orders/{$orderA->id}", [
            'client_id' => $this->client->id,
            'year_id' => $this->year2026->id,
            'rover_id' => $this->ernesto->id,
            'take_away' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->ernesto->id, $orderA->fresh()->rover_id);
        $this->assertSame($this->ernesto->id, $orderB->fresh()->rover_id, 'El OTRO pedido del mismo cliente/año debe seguir al nuevo responsable.');

        $assignment = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2026->id)->first();
        $this->assertSame($this->ernesto->id, $assignment->assigned_user_id);
    }

    public function test_sync_never_touches_orders_of_other_years(): void
    {
        $order2025 = $this->makeOrder($this->year2025, $this->juan);
        $order2026 = $this->makeOrder($this->year2026, $this->juan);

        $this->actingAs($this->admin)->post("/clients/{$this->client->id}/assignment/transfer", [
            'year_id' => $this->year2026->id,
            'assigned_user_id' => $this->ernesto->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->ernesto->id, $order2026->fresh()->rover_id);
        $this->assertSame($this->juan->id, $order2025->fresh()->rover_id, 'Un pedido de OTRO año nunca debe tocarse.');

        $assignment2025 = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2025->id)->first();
        $this->assertSame($this->juan->id, $assignment2025->assigned_user_id);
    }

    public function test_creating_an_order_without_prior_assignment_creates_it_automatically(): void
    {
        $this->assertNull(ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2026->id)->first());

        $order = $this->makeOrder($this->year2026, $this->ernesto);

        $assignment = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2026->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($this->ernesto->id, $assignment->assigned_user_id);
        $this->assertSame($order->rover_id, $assignment->assigned_user_id);
    }

    public function test_generate_from_previous_year_inherits_the_last_effective_responsible(): void
    {
        // Pepito compra en 2025 con Juan, y despues Ernesto le vende (pasa a
        // ser su responsable efectivo) en 2025 tambien.
        $this->makeOrder($this->year2025, $this->juan);
        $this->makeOrder($this->year2025, $this->ernesto);

        $assignment2025 = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year2025->id)->first();
        $this->assertSame($this->ernesto->id, $assignment2025->assigned_user_id, 'La ultima venta real (Ernesto) debe ser el responsable efectivo de 2025.');

        $year2027 = Year::create(['year' => 2027, 'label' => 'Locro 2027', 'is_active' => false, 'portion_price' => 20000]);

        app(ClientAssignmentService::class)->executeGenerateFromPreviousYear($this->year2025, $year2027, $this->admin->id);

        $assignment2027 = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $year2027->id)->first();
        $this->assertNotNull($assignment2027);
        $this->assertSame($this->ernesto->id, $assignment2027->assigned_user_id, 'La edicion nueva debe heredar al responsable EFECTIVO (Ernesto), no al primero que lo contacto (Juan).');
    }

    public function test_created_by_is_never_overwritten_by_the_sync(): void
    {
        $order = $this->makeOrder($this->year2026, $this->juan);
        $this->assertSame($this->admin->id, $order->created_by);

        $this->actingAs($this->admin)->post("/clients/{$this->client->id}/assignment/transfer", [
            'year_id' => $this->year2026->id,
            'assigned_user_id' => $this->ernesto->id,
        ]);

        $fresh = $order->fresh();
        $this->assertSame($this->ernesto->id, $fresh->rover_id);
        $this->assertSame($this->admin->id, $fresh->created_by, 'created_by es un registro de auditoria inmutable, nunca debe cambiar.');
    }
}



