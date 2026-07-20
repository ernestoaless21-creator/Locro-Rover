<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Gift;
use App\Models\Loss;
use App\Models\Order;
use App\Models\TeamTask;
use App\Models\User;
use App\Models\Year;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 19: "ediciones historicas de solo lectura". Cubre los 4 escenarios
 * pedidos explicitamente por el usuario, con un modulo representativo cada
 * uno (no se duplica para los ~40 endpoints que ya tocan Gate::authorize('mutate', $year)):
 *  - usuario operativo puede editar la edicion activa.
 *  - usuario operativo NO puede modificar una edicion historica.
 *  - un usuario con 'anios.gestionar' (admin o jefe_logistica) si puede.
 *  - OrderController::store sigue forzando la edicion activa para quien no
 *    tiene 'anios.gestionar', sin importar que year_id mande.
 */
class HistoricalEditionReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected Year $activeYear;

    protected Year $historicalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->activeYear = Year::where('year', 2026)->firstOrFail();
        $this->historicalYear = Year::create(['year' => 2024, 'is_active' => false]);
    }

    protected function makeOrder(User $rover, Year $year): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $year->id,
            'rover_id' => $rover->id,
            'created_by' => $rover->id,
            'updated_by' => $rover->id,
            'take_away' => true,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 2, $year, $rover->id);

        return $order->fresh();
    }

    // ---------- Pedidos (Orders) -----------------------------------------

    public function test_operational_user_can_edit_an_order_in_the_active_edition(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('logistica'); // 'pedidos.editar', sin 'anios.gestionar'

        $order = $this->makeOrder($rover, $this->activeYear);

        $response = $this->actingAs($rover)->put("/orders/{$order->id}", ['status' => 'confirmado']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame('confirmado', $order->fresh()->status);
    }

    public function test_operational_user_cannot_modify_an_order_in_a_historical_edition(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('logistica');

        $order = $this->makeOrder($rover, $this->historicalYear);

        $response = $this->actingAs($rover)->put("/orders/{$order->id}", ['status' => 'confirmado']);

        $response->assertForbidden();
        $this->assertNotSame('confirmado', $order->fresh()->status);
    }

    public function test_user_with_anios_gestionar_can_modify_an_order_in_a_historical_edition(): void
    {
        $jefe = User::factory()->create(['is_active' => true]);
        $jefe->assignRole('jefe_logistica'); // tiene 'anios.gestionar'

        $order = $this->makeOrder($jefe, $this->historicalYear);

        $response = $this->actingAs($jefe)->put("/orders/{$order->id}", ['status' => 'confirmado']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame('confirmado', $order->fresh()->status);
    }

    public function test_order_store_always_uses_active_edition_for_user_without_anios_gestionar(): void
    {
        $rover = User::factory()->create(['is_active' => true]);
        $rover->assignRole('logistica');
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => 'Test']);

        $response = $this->actingAs($rover)->post('/orders', [
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id, // intenta forzar la historica
            'portions' => 2,
            'take_away' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame($this->activeYear->id, $order->year_id);
    }

    /**
     * Fase 21 (correccion): un usuario con 'anios.gestionar' SI puede elegir
     * explicitamente una edicion historica y crear un pedido nuevo ahi --
     * caso de uso real: un pedido se escapo durante la importacion historica
     * (HistoricalImportController) y hace falta cargarlo a mano, sin
     * reimportar todo el Excel. Mismo criterio centralizado que el resto de
     * la app (Year::isEditableBy / Gate::authorize('mutate', $year)), sin
     * excepcion especial para este endpoint.
     */
    public function test_user_with_anios_gestionar_can_create_an_order_in_a_historical_edition(): void
    {
        $jefe = User::factory()->create(['is_active' => true]);
        $jefe->assignRole('jefe_logistica');
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => 'Test']);

        $response = $this->actingAs($jefe)->post('/orders', [
            'client_id' => $client->id,
            'year_id' => $this->historicalYear->id,
            'portions' => 2,
            'take_away' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame($this->historicalYear->id, $order->year_id);
    }

    // ---------- Regalos / Perdidas ----------------------------------------

    public function test_operational_user_cannot_modify_a_gift_in_a_historical_edition(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica'); // 'regalos.gestionar', sin 'anios.gestionar'

        $gift = Gift::create(['year_id' => $this->historicalYear->id, 'recipient_name' => 'Josefina', 'quantity' => 2, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->put("/gifts/{$gift->id}", ['recipient_name' => 'Otro', 'quantity' => 3]);

        $response->assertForbidden();
        $this->assertSame(2, $gift->fresh()->quantity);
    }

    public function test_operational_user_can_modify_a_gift_in_the_active_edition(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica');

        $gift = Gift::create(['year_id' => $this->activeYear->id, 'recipient_name' => 'Josefina', 'quantity' => 2, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->put("/gifts/{$gift->id}", ['recipient_name' => 'Josefina', 'quantity' => 4]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(4, $gift->fresh()->quantity);
    }

    public function test_admin_can_modify_a_loss_in_a_historical_edition(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $loss = Loss::create(['year_id' => $this->historicalYear->id, 'quantity' => 1, 'reason' => 'x', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->put("/losses/{$loss->id}", ['quantity' => 5, 'reason' => 'corregido']);

        $response->assertSessionHasNoErrors();
        $this->assertSame(5, $loss->fresh()->quantity);
    }

    // ---------- Produccion (parametros de la edicion) ----------------------

    public function test_user_with_only_parametros_gestionar_cannot_edit_historical_year_params(): void
    {
        $role = Role::firstOrCreate(['name' => 'solo_parametros', 'guard_name' => 'web']);
        $role->givePermissionTo(['parametros.gestionar']); // deliberadamente SIN 'anios.gestionar'

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('solo_parametros');

        $response = $this->actingAs($user)->put("/years/{$this->historicalYear->id}", ['portion_price' => 999]);

        $response->assertForbidden();
        $this->assertNotEquals(999, $this->historicalYear->fresh()->portion_price);
    }

    public function test_user_with_only_parametros_gestionar_can_edit_active_year_params(): void
    {
        $role = Role::firstOrCreate(['name' => 'solo_parametros', 'guard_name' => 'web']);
        $role->givePermissionTo(['parametros.gestionar']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('solo_parametros');

        $response = $this->actingAs($user)->put("/years/{$this->activeYear->id}", ['portion_price' => 1500]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals(1500, $this->activeYear->fresh()->portion_price);
    }

    // ---------- Equipos (representante del patron "team modules") ----------

    public function test_operational_user_cannot_toggle_a_team_task_in_a_historical_edition(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica'); // teamSlug() === 'logistica', 'tareas.gestionar-propio-equipo'

        $task = TeamTask::create([
            'team' => 'logistica',
            'year_id' => $this->historicalYear->id,
            'title' => 'Tarea vieja',
            'sort_order' => 1,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $response->assertForbidden();
        $this->assertFalse($task->fresh()->is_completed);
    }

    public function test_operational_user_can_toggle_a_team_task_in_the_active_edition(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('logistica');

        $task = TeamTask::create([
            'team' => 'logistica',
            'year_id' => $this->activeYear->id,
            'title' => 'Tarea nueva',
            'sort_order' => 1,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $response->assertSessionHasNoErrors();
        $this->assertTrue($task->fresh()->is_completed);
    }
}
