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
 * Fase 18.1 (correccion): un Rover comun (cualquier rol operativo SIN
 * 'pedidos.asignar-rover' / 'asignaciones.transferir', ej. 'publicidad',
 * 'compras', 'infraestructura') solo puede editar SUS PROPIOS pedidos y los
 * clientes donde el es el responsable asignado en la edicion activa
 * (client_year_assignments.assigned_user_id), y nunca puede reasignar
 * responsable (ni de pedidos ni de clientes). Admin y Logistica conservan
 * acceso total. Ver OrderPolicy::update/assignRover y ClientPolicy::update.
 */
class RoverOwnershipRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeOrder(User $rover): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => $rover->id,
            'take_away' => true,
            'created_by' => $rover->id,
            'updated_by' => $rover->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->year, $rover->id);
        app(ClientAssignmentService::class)->syncFromOrder($order);

        return $order->fresh();
    }

    protected function makeClientAssignedTo(User $rover): Client
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        ClientAssignment::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $rover->id,
        ]);

        return $client;
    }

    // ── Pedidos ─────────────────────────────────────────────────────────────

    public function test_rover_cannot_edit_another_rovers_order(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $order = $this->makeOrder($roverB);

        $response = $this->actingAs($roverA)->put("/orders/{$order->id}", [
            'observations' => 'intento de edicion ajena',
        ]);

        $response->assertForbidden();
        $this->assertNotSame('intento de edicion ajena', $order->fresh()->observations);
    }

    public function test_rover_can_edit_their_own_order(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');

        $order = $this->makeOrder($rover);

        $response = $this->actingAs($rover)->put("/orders/{$order->id}", [
            'observations' => 'edicion propia',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('edicion propia', $order->fresh()->observations);
    }

    public function test_rover_cannot_reassign_order_rover_via_update(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $order = $this->makeOrder($roverA);

        $response = $this->actingAs($roverA)->put("/orders/{$order->id}", [
            'rover_id' => $roverB->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($roverA->id, $order->fresh()->rover_id);
    }

    public function test_rover_cannot_reassign_order_via_bulk_assign(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $order = $this->makeOrder($roverA);

        $response = $this->actingAs($roverA)->postJson('/orders/bulk-assign', [
            'order_ids' => [$order->id],
            'rover_id' => $roverB->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($roverA->id, $order->fresh()->rover_id);
    }

    public function test_admin_can_edit_and_reassign_any_order(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');
        $otherRover = User::factory()->create(['is_active' => true]);
        $otherRover->assignRole('publicidad');

        $order = $this->makeOrder($rover);

        $this->actingAs($admin)->put("/orders/{$order->id}", [
            'observations' => 'editado por admin',
            'rover_id' => $otherRover->id,
        ])->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('editado por admin', $fresh->observations);
        $this->assertSame($otherRover->id, $fresh->rover_id);
    }

    public function test_logistica_can_edit_and_reassign_any_order(): void
    {
        $logistica = User::factory()->create(['is_active' => true]);
        $logistica->assignRole('logistica');
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');
        $otherRover = User::factory()->create(['is_active' => true]);
        $otherRover->assignRole('publicidad');

        $order = $this->makeOrder($rover);

        $this->actingAs($logistica)->put("/orders/{$order->id}", [
            'observations' => 'editado por logistica',
            'rover_id' => $otherRover->id,
        ])->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('editado por logistica', $fresh->observations);
        $this->assertSame($otherRover->id, $fresh->rover_id);
    }

    // ── Clientes ────────────────────────────────────────────────────────────

    public function test_rover_cannot_edit_a_client_assigned_to_another_rover(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($roverB);

        $response = $this->actingAs($roverA)->put("/clients/{$client->id}", [
            'first_name' => 'Editado',
            'last_name' => $client->last_name,
        ]);

        $response->assertForbidden();
        $this->assertNotSame('Editado', $client->fresh()->first_name);
    }

    public function test_rover_can_edit_their_own_assigned_client(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($rover);

        $response = $this->actingAs($rover)->put("/clients/{$client->id}", [
            'first_name' => 'Editado',
            'last_name' => $client->last_name,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('Editado', $client->fresh()->first_name);
    }

    public function test_rover_cannot_transfer_client_assignment(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($roverA);

        $response = $this->actingAs($roverA)->post("/clients/{$client->id}/assignment/transfer", [
            'year_id' => $this->year->id,
            'assigned_user_id' => $roverB->id,
        ]);

        $response->assertForbidden();
        $assignment = ClientAssignment::where('client_id', $client->id)->where('year_id', $this->year->id)->first();
        $this->assertSame($roverA->id, $assignment->assigned_user_id);
    }

    public function test_rover_cannot_transfer_via_assignments_endpoint(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('publicidad');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($roverA);
        $assignment = ClientAssignment::where('client_id', $client->id)->where('year_id', $this->year->id)->firstOrFail();

        $response = $this->actingAs($roverA)->post("/assignments/{$assignment->id}/transfer", [
            'assigned_user_id' => $roverB->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($roverA->id, $assignment->fresh()->assigned_user_id);
    }

    public function test_admin_can_edit_and_transfer_any_client(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');
        $otherRover = User::factory()->create(['is_active' => true]);
        $otherRover->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($rover);

        $this->actingAs($admin)->put("/clients/{$client->id}", [
            'first_name' => 'Editado',
            'last_name' => $client->last_name,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Editado', $client->fresh()->first_name);

        $this->actingAs($admin)->post("/clients/{$client->id}/assignment/transfer", [
            'year_id' => $this->year->id,
            'assigned_user_id' => $otherRover->id,
        ])->assertSessionHasNoErrors();

        $assignment = ClientAssignment::where('client_id', $client->id)->where('year_id', $this->year->id)->first();
        $this->assertSame($otherRover->id, $assignment->assigned_user_id);
    }

    public function test_logistica_can_edit_and_transfer_any_client(): void
    {
        $logistica = User::factory()->create(['is_active' => true]);
        $logistica->assignRole('logistica');
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('publicidad');
        $otherRover = User::factory()->create(['is_active' => true]);
        $otherRover->assignRole('publicidad');

        $client = $this->makeClientAssignedTo($rover);

        $this->actingAs($logistica)->put("/clients/{$client->id}", [
            'first_name' => 'Editado',
            'last_name' => $client->last_name,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Editado', $client->fresh()->first_name);

        $this->actingAs($logistica)->post("/clients/{$client->id}/assignment/transfer", [
            'year_id' => $this->year->id,
            'assigned_user_id' => $otherRover->id,
        ])->assertSessionHasNoErrors();

        $assignment = ClientAssignment::where('client_id', $client->id)->where('year_id', $this->year->id)->first();
        $this->assertSame($otherRover->id, $assignment->assigned_user_id);
    }
}
