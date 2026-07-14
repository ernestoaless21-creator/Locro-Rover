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
 * Fase 7, secciones 6 y 8 (ver informe de la fase para la decision de
 * arquitectura completa).
 */
class ClientAssignmentSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $roverB;

    protected Year $year;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->roverB = User::factory()->create(['is_active' => true]);
        $this->roverB->assignRole('logistica');

        $this->year = Year::where('year', (int) date('Y'))->firstOrFail();
        $this->year->update([
            'label' => 'Locro 2026',
            'is_active' => true,
            'portion_price' => 18000,
        ]);

        $this->client = Client::create(['first_name' => 'Cliente', 'last_name' => 'Test']);
    }

    protected function makeOrderFor(User $rover): Order
    {
        $order = Order::create([
            'client_id' => $this->client->id,
            'year_id' => $this->year->id,
            'rover_id' => $rover->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->year, $this->admin->id);

        return $order->fresh();
    }

    public function test_creating_an_order_creates_the_client_year_assignment(): void
    {
        $response = $this->actingAs($this->admin)->post('/orders', [
            'client_id' => $this->client->id,
            'year_id' => $this->year->id,
            'take_away' => true,
            'portions' => 2,
        ]);

        $response->assertSessionHasNoErrors();

        $assignment = ClientAssignment::where('client_id', $this->client->id)
            ->where('year_id', $this->year->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($this->admin->id, $assignment->assigned_user_id);
        $this->assertSame(
            ClientAssignment::STATUS_PEDIDO_REALIZADO,
            $assignment->contact_status
        );
    }

    public function test_deliberate_rover_transfer_propagates_to_the_client_assignment(): void
    {
        $order = $this->makeOrderFor($this->admin);

        // Transferencia deliberada via el endpoint de edicion de pedido
        // (requiere 'pedidos.asignar-rover', que 'admin' tiene).
        $this->actingAs($this->admin)->put("/orders/{$order->id}", [
            'client_id' => $this->client->id,
            'year_id' => $this->year->id,
            'rover_id' => $this->roverB->id,
            'take_away' => true,
        ])->assertSessionHasNoErrors();

        $assignment = ClientAssignment::where('client_id', $this->client->id)->where('year_id', $this->year->id)->first();

        $this->assertSame($this->roverB->id, $assignment->assigned_user_id, 'El responsable mostrado en Clientes debe seguir a la transferencia deliberada del pedido.');
    }

    public function test_check_existing_warns_about_duplicate_orders_without_blocking(): void
    {
        $existing = $this->makeOrderFor($this->admin);

        $response = $this->actingAs($this->admin)->getJson(
            "/orders/check-existing?client_id={$this->client->id}&year_id={$this->year->id}"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'orders');
        $response->assertJsonPath('orders.0.id', $existing->id);

        // No bloquea: se puede seguir creando otro pedido para el mismo cliente/edicion.
        $second = $this->actingAs($this->admin)->post('/orders', [
            'client_id' => $this->client->id,
            'year_id' => $this->year->id,
            'take_away' => true,
            'portions' => 1,
        ]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(2, Order::where('client_id', $this->client->id)->count());
    }
}


