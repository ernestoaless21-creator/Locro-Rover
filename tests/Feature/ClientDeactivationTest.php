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
 * Cubre la regla de negocio de "gestion de clientes" que reemplaza a
 * "Quitar de la edicion" (ver informe de la correccion de arquitectura):
 *  - is_active es global al cliente, nunca borra historial.
 *  - el backfill automatico de ClientController::index() y
 *    ClientAssignmentService::generateFromPreviousYear() saltean clientes
 *    inactivos.
 *  - un pedido en la edicion ACTIVA reactiva automaticamente; uno en una
 *    edicion historica (import o carga manual) NUNCA reactiva.
 */
class ClientDeactivationTest extends TestCase
{
    use RefreshDatabase;

    protected Year $activeYear;

    protected Year $historicalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->activeYear = Year::where('year', 2026)->firstOrFail();
        $this->activeYear->update(['portion_price' => 18000]);
        $this->historicalYear = Year::create(['year' => 2024, 'is_active' => false, 'portion_price' => 15000]);
    }

    protected function makeLogistica(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica'); // tiene 'asignaciones.transferir'

        return $user;
    }

    // ---------- Desactivar / reactivar (endpoints) --------------------------

    public function test_logistica_can_deactivate_a_client_with_optional_reason(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Ana', 'last_name' => 'Gomez']);

        $response = $this->actingAs($user)->post("/clients/{$client->id}/deactivate", [
            'reason' => 'Pidió no ser contactada',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $client->refresh();
        $this->assertFalse($client->is_active);
        $this->assertNotNull($client->deactivated_at);
        $this->assertSame($user->id, $client->deactivated_by);
        $this->assertSame('Pidió no ser contactada', $client->deactivation_reason);
    }

    public function test_deactivate_without_reason_is_allowed(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Bruno', 'last_name' => 'Diaz']);

        $response = $this->actingAs($user)->post("/clients/{$client->id}/deactivate", []);

        $response->assertSessionHasNoErrors();
        $this->assertFalse($client->fresh()->is_active);
        $this->assertNull($client->fresh()->deactivation_reason);
    }

    public function test_operational_user_without_asignaciones_transferir_cannot_deactivate(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('compras'); // sin 'asignaciones.transferir'
        $client = Client::create(['first_name' => 'Carla', 'last_name' => 'Ruiz']);

        $response = $this->actingAs($rover)->post("/clients/{$client->id}/deactivate", []);

        $response->assertForbidden();
        $this->assertTrue($client->fresh()->is_active);
    }

    public function test_reactivate_clears_deactivation_fields(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Dario', 'last_name' => 'Paz']);
        $client->deactivate($user->id, 'Se mudó');

        $response = $this->actingAs($user)->post("/clients/{$client->id}/reactivate");

        $response->assertSessionHasNoErrors();
        $client->refresh();
        $this->assertTrue($client->is_active);
        $this->assertNull($client->deactivated_at);
        $this->assertNull($client->deactivated_by);
        $this->assertNull($client->deactivation_reason);
    }

    // ---------- Historial nunca se pierde ------------------------------------

    public function test_deactivating_a_client_does_not_touch_existing_orders_or_assignments(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Elena', 'last_name' => 'Vega']);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
            'rover_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->historicalYear, $user->id);
        $order->recalculateTotals();
        app(ClientAssignmentService::class)->syncFromOrder($order->fresh());

        $assignmentBefore = ClientAssignment::where('client_id', $client->id)->where('year_id', $this->historicalYear->id)->first();
        $this->assertNotNull($assignmentBefore);

        $client->deactivate($user->id, 'Compra con otra persona');

        // El pedido y la asignacion de la edicion historica siguen intactos.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'client_id' => $client->id]);
        $this->assertDatabaseHas('client_year_assignments', [
            'id' => $assignmentBefore->id,
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
        ]);

        // Y el cliente sigue apareciendo en el historial de esa edicion.
        $response = $this->actingAs($user)->get("/clients/{$client->id}/history");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('client.id', $client->id));
    }

    // ---------- Backfill automatico (ClientController::index) ---------------

    public function test_index_backfill_skips_inactive_clients(): void
    {
        $user = $this->makeLogistica();
        $active = Client::create(['first_name' => 'Fede', 'last_name' => 'Lopez']);
        $inactive = Client::create(['first_name' => 'Gina', 'last_name' => 'Nu']);
        $inactive->deactivate($user->id);

        $this->actingAs($user)->get('/clients?year_id='.$this->activeYear->id)->assertOk();

        $this->assertDatabaseHas('client_year_assignments', [
            'client_id' => $active->id,
            'year_id' => $this->activeYear->id,
        ]);
        $this->assertDatabaseMissing('client_year_assignments', [
            'client_id' => $inactive->id,
            'year_id' => $this->activeYear->id,
        ]);
    }

    public function test_index_still_lists_inactive_clients(): void
    {
        $user = $this->makeLogistica();
        $inactive = Client::create(['first_name' => 'Hugo', 'last_name' => 'Ortiz']);
        $inactive->deactivate($user->id);

        $response = $this->actingAs($user)->get('/clients?year_id='.$this->activeYear->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.0.id', $inactive->id)
            ->where('clients.0.is_active', false)
        );
    }

    // ---------- Generar desde edicion anterior -------------------------------

    public function test_generate_from_previous_year_skips_inactive_clients(): void
    {
        $user = $this->makeLogistica();
        $active = Client::create(['first_name' => 'Ines', 'last_name' => 'Cruz']);
        $inactive = Client::create(['first_name' => 'Juan', 'last_name' => 'Bel']);
        $inactive->deactivate($user->id);

        ClientAssignment::create(['client_id' => $active->id, 'year_id' => $this->historicalYear->id]);
        ClientAssignment::create(['client_id' => $inactive->id, 'year_id' => $this->historicalYear->id]);

        app(ClientAssignmentService::class)->executeGenerateFromPreviousYear($this->historicalYear, $this->activeYear, $user->id);

        $this->assertDatabaseHas('client_year_assignments', [
            'client_id' => $active->id,
            'year_id' => $this->activeYear->id,
        ]);
        $this->assertDatabaseMissing('client_year_assignments', [
            'client_id' => $inactive->id,
            'year_id' => $this->activeYear->id,
        ]);

        // La asignacion vieja del inactivo en la edicion historica sigue intacta.
        $this->assertDatabaseHas('client_year_assignments', [
            'client_id' => $inactive->id,
            'year_id' => $this->historicalYear->id,
        ]);
    }

    // ---------- Reactivacion automatica: solo en la edicion ACTIVA ----------

    public function test_order_in_active_edition_reactivates_client(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Karen', 'last_name' => 'Diez']);
        $client->deactivate($user->id, 'No contactar');

        $client_id = $client->id;
        $order = Order::create([
            'client_id' => $client_id,
            'year_id' => $this->activeYear->id,
            'rover_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->activeYear, $user->id);
        app(ClientAssignmentService::class)->syncFromOrder($order->fresh());

        $client->refresh();
        $this->assertTrue($client->is_active);
        $this->assertNull($client->deactivated_at);
        $this->assertNull($client->deactivation_reason);
    }

    public function test_order_in_historical_edition_does_not_reactivate_client(): void
    {
        $user = $this->makeLogistica();
        $client = Client::create(['first_name' => 'Leo', 'last_name' => 'Funes']);
        $client->deactivate($user->id, 'Se mudó');

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
            'rover_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->historicalYear, $user->id);
        app(ClientAssignmentService::class)->syncFromOrder($order->fresh());

        $client->refresh();
        $this->assertFalse($client->is_active);
        $this->assertNotNull($client->deactivated_at);
        $this->assertSame('Se mudó', $client->deactivation_reason);
    }

    public function test_historical_excel_import_does_not_reactivate_client(): void
    {
        $jefe = User::factory()->create(['is_active' => true]);
        $jefe->assignRole('jefe_logistica');

        $client = Client::create(['first_name' => 'Mora', 'last_name' => 'Diaz', 'phone' => '11-2222-3333']);
        $client->deactivate($jefe->id, 'No contactar');

        // Reconstruye exactamente lo que hace la importacion historica para un
        // cliente EXISTENTE (matcheado por telefono) con una compra real, sin
        // pasar por el parseo de Excel: llama al mismo servicio que persist()
        // usa internamente para sincronizar la asignacion de una fila con compra.
        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
            'rover_id' => null,
            'created_by' => $jefe->id,
            'updated_by' => $jefe->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->historicalYear, $jefe->id);
        app(ClientAssignmentService::class)->syncFromOrder($order->fresh());

        $this->assertFalse($client->fresh()->is_active);
    }

    // ---------- destroy() se mantiene solo para clientes sin historial -------

    public function test_destroy_still_blocked_for_client_with_orders_regardless_of_is_active(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $client = Client::create(['first_name' => 'Nora', 'last_name' => 'Sosa']);
        $client->deactivate($admin->id);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 1, $this->historicalYear, $admin->id);

        $response = $this->actingAs($admin)->delete("/clients/{$client->id}");

        $response->assertSessionHasErrors('client');
        $this->assertNull($client->fresh()->deleted_at);
    }

    public function test_destroy_still_works_for_inactive_client_without_orders(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $client = Client::create(['first_name' => 'Omar', 'last_name' => 'Vidal']);
        $client->deactivate($admin->id);

        $response = $this->actingAs($admin)->delete("/clients/{$client->id}");

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($client->fresh()->deleted_at);
    }
}
