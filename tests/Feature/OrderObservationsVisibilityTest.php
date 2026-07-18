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
 * Fase 7 (correccion 2), seccion 3.
 *
 * Decision tomada (ver informe): Client.general_notes, ClientAssignment.notes
 * (seguimiento cliente+edicion) y Order.observations (logistica de ESE
 * pedido puntual) son 3 campos distintos y NO se fusionan en uno solo (el
 * usuario pidio explicitamente no sincronizar campos semanticamente
 * distintos sin analizarlo). En cambio, Pedidos ahora tambien MUESTRA (solo
 * lectura) la nota de seguimiento del cliente/edicion, para que no quede
 * "escondida" — sin dejar de editarse desde /clients como corresponde.
 */
class OrderObservationsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_index_surfaces_the_client_year_note_without_touching_order_observations(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $year = Year::where('year', 2026)->firstOrFail();
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => 'Prueba']);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'observations' => 'Retira la hermana',
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 1, $year, $admin->id);

        ClientAssignment::updateOrCreate(
            [
                'client_id' => $client->id,
                'year_id' => $year->id,
            ],
            [
                'assigned_user_id' => $admin->id,
                'notes' => 'No atiende el telefono, insistir',
            ]
        );

        $response = $this->actingAs($admin)->get('/orders?year_id='.$year->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.0.observations', 'Retira la hermana')
            ->where('orders.0.client_assignment_notes', 'No atiende el telefono, insistir')
        );
    }
}
