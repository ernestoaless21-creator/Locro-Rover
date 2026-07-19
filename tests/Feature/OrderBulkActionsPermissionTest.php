<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Year;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 20 (bug de permisos): un usuario con rol Compras (u otro rol operativo
 * sin 'pedidos.acciones-masivas') puede cobrar/retirar UN pedido a la vez
 * ("todos venden", checkbox individual de la fila), pero NO puede invocar las
 * acciones sobre VARIOS pedidos seleccionados a la vez: eso queda exclusivo
 * de Logistica. Este test cubre el backend (el frontend ya oculta la UI, pero
 * el 403 debe sostenerse igual si se llama al endpoint directamente).
 */
class OrderBulkActionsPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', (int) date('Y'))->firstOrFail();
        $this->year->update([
            'label' => 'Locro '.date('Y'),
            'is_active' => true,
            'portion_price' => 18000,
            'promo_unit_price' => 15000,
            'amount_for_promo' => 3,
        ]);

        $this->cash = PaymentMethod::firstOrCreate(['slug' => 'efectivo'], ['name' => 'Efectivo', 'is_active' => true]);
    }

    protected function makeOrder(User $rover, int $portions = 1): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => $rover->id,
            'created_by' => $rover->id,
            'updated_by' => $rover->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, $portions, $this->year, $rover->id);

        return $order->fresh();
    }

    public function test_compras_can_withdraw_and_pay_a_single_own_order(): void
    {
        $compras = User::factory()->create(['is_active' => true]);
        $compras->assignRole('compras');

        $order = $this->makeOrder($compras);

        $withdrawResponse = $this->actingAs($compras)->postJson('/orders/bulk-withdraw', [
            'order_ids' => [$order->id],
        ]);
        $withdrawResponse->assertOk();

        $payResponse = $this->actingAs($compras)->postJson('/orders/bulk-pay', [
            'order_ids' => [$order->id],
            'mode' => 'full_balance',
            'payment_method_id' => $this->cash->id,
            'paid_at' => now()->toDateString(),
        ]);
        $payResponse->assertOk();
    }

    public function test_compras_cannot_bulk_withdraw_multiple_orders(): void
    {
        $compras = User::factory()->create(['is_active' => true]);
        $compras->assignRole('compras');

        $orderA = $this->makeOrder($compras);
        $orderB = $this->makeOrder($compras);

        $response = $this->actingAs($compras)->postJson('/orders/bulk-withdraw', [
            'order_ids' => [$orderA->id, $orderB->id],
        ]);

        $response->assertForbidden();
        $this->assertSame('no_retirado', $orderA->fresh()->withdrawal_status);
        $this->assertSame('no_retirado', $orderB->fresh()->withdrawal_status);
    }

    public function test_compras_cannot_bulk_pay_multiple_orders(): void
    {
        $compras = User::factory()->create(['is_active' => true]);
        $compras->assignRole('compras');

        $orderA = $this->makeOrder($compras);
        $orderB = $this->makeOrder($compras);

        $response = $this->actingAs($compras)->postJson('/orders/bulk-pay', [
            'order_ids' => [$orderA->id, $orderB->id],
            'mode' => 'full_balance',
            'payment_method_id' => $this->cash->id,
            'paid_at' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertSame('0.00', number_format((float) $orderA->fresh()->total_paid, 2, '.', ''));
    }

    public function test_compras_cannot_bulk_pay_and_withdraw_multiple_orders(): void
    {
        $compras = User::factory()->create(['is_active' => true]);
        $compras->assignRole('compras');

        $orderA = $this->makeOrder($compras);
        $orderB = $this->makeOrder($compras);

        $response = $this->actingAs($compras)->postJson('/orders/bulk-pay-and-withdraw', [
            'order_ids' => [$orderA->id, $orderB->id],
            'payment_method_id' => $this->cash->id,
        ]);

        $response->assertForbidden();
    }

    public function test_logistica_can_bulk_withdraw_multiple_orders(): void
    {
        $logistica = User::factory()->create(['is_active' => true]);
        $logistica->assignRole('logistica');

        $orderA = $this->makeOrder($logistica);
        $orderB = $this->makeOrder($logistica);

        $response = $this->actingAs($logistica)->postJson('/orders/bulk-withdraw', [
            'order_ids' => [$orderA->id, $orderB->id],
        ]);

        $response->assertOk();
        $this->assertSame('retirado', $orderA->fresh()->withdrawal_status);
        $this->assertSame('retirado', $orderB->fresh()->withdrawal_status);
    }

    public function test_orders_index_hides_bulk_permission_flag_for_compras(): void
    {
        $compras = User::factory()->create(['is_active' => true]);
        $compras->assignRole('compras');

        $response = $this->actingAs($compras)->get('/orders?year_id='.$this->year->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canBulkActions', false));
    }

    public function test_orders_index_exposes_bulk_permission_flag_for_logistica(): void
    {
        $logistica = User::factory()->create(['is_active' => true]);
        $logistica->assignRole('logistica');

        $response = $this->actingAs($logistica)->get('/orders?year_id='.$this->year->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canBulkActions', true));
    }
}
