<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug: cambiar la relacion de salsas (sauce_portions_per_block /
 * sauce_units_per_block) en Parametros y elegir "Guardar y recalcular
 * pedidos" actualizaba el precio de los pedidos existentes pero NUNCA la
 * cantidad de salsas ya persistida en cada pedido -- quedaba calculada para
 * siempre con la relacion vieja. Causa: PricingService::recalculatePricedLinesForOrder
 * solo recalculaba las lineas de producto 'locro', ignorando la linea
 * 'salsas' (que es un valor derivado, no una decision manual del usuario).
 */
class SauceParameterRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    public function test_saving_new_sauce_ratio_with_recalculate_updates_existing_orders_sauce_quantity(): void
    {
        $admin = $this->makeAdmin();
        $year = Year::where('year', 2026)->firstOrFail();

        // Regla original: 1 salsa cada 2 porciones (default de la migracion).
        $client = Client::create(['first_name' => 'Test', 'last_name' => 'Sauce']);
        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $year->id,
            'rover_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'take_away' => true,
        ]);
        app(PricingService::class)->syncPortionsForOrder($order, 4, $year, $admin->id);

        $saucesItem = $order->items()->where('product', 'salsas')->first();
        $this->assertSame(2, $saucesItem->quantity); // 4 porciones / 2 = 2 salsas

        // Cambia la regla a "1 salsa por porcion" y pide recalcular pedidos.
        $response = $this->actingAs($admin)->put("/years/{$year->id}", [
            'label' => $year->label,
            'portion_price' => $year->portion_price,
            'made_portions' => $year->made_portions,
            'sauce_portions_per_block' => 1,
            'sauce_units_per_block' => 1,
            'recalculate_orders' => true,
        ]);

        $response->assertRedirect();

        $year->refresh();
        $this->assertSame(1, $year->sauce_portions_per_block);
        $this->assertSame(1, $year->sauce_units_per_block);

        $saucesItem->refresh();
        $this->assertSame(4, $saucesItem->quantity); // 4 porciones / 1 = 4 salsas, ya no 2
    }
}
