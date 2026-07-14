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
 * Fase 7, seccion 10 y 15: "Cobrar y retirar seleccionados" debe cobrar
 * exactamente el saldo pendiente de cada pedido (reutilizando la logica de
 * balance_due ya probada), marcar retirado, no crear pagos de $0, y hacerlo
 * todo de forma transaccional.
 */
class OrderBulkPayAndWithdrawTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Year $year;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

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

    protected function makeOrder(int $portions, float $prePaid = 0): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, $portions, $this->year, $this->admin->id);

        if ($prePaid > 0) {
            $order->payments()->create([
                'payment_method_id' => $this->cash->id,
                'amount' => $prePaid,
                'paid_at' => now(),
                'registered_by' => $this->admin->id,
            ]);
        }

        return $order->fresh();
    }

    public function test_charges_exactly_the_pending_balance_and_withdraws(): void
    {
        $orderA = $this->makeOrder(2); // 2 * 18000 = 36000, sin pagos
        $orderB = $this->makeOrder(3, prePaid: 20000); // 3*15000(promo)=45000, pago parcial 20000 -> saldo 25000

        $response = $this->actingAs($this->admin)->postJson('/orders/bulk-pay-and-withdraw', [
            'order_ids' => [$orderA->id, $orderB->id],
            'payment_method_id' => $this->cash->id,
        ]);

        $response->assertOk();
        $response->assertJson(['payments_created' => 2, 'withdrawn' => 2]);

        $orderA->refresh();
        $orderB->refresh();

        $this->assertSame('36000.00', number_format((float) $orderA->total_paid, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $orderA->balance_due, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $orderB->balance_due, 2, '.', ''));
        $this->assertSame('retirado', $orderA->withdrawal_status);
        $this->assertSame('retirado', $orderB->withdrawal_status);
        $this->assertNotNull($orderA->withdrawn_by);
    }

    public function test_zero_balance_order_is_withdrawn_without_creating_a_payment(): void
    {
        $order = $this->makeOrder(2, prePaid: 36000); // ya saldado

        $response = $this->actingAs($this->admin)->postJson('/orders/bulk-pay-and-withdraw', [
            'order_ids' => [$order->id],
            'payment_method_id' => $this->cash->id,
        ]);

        $response->assertOk();
        $response->assertJson(['payments_created' => 0, 'withdrawn' => 1]);

        $this->assertSame('retirado', $order->fresh()->withdrawal_status);
    }

    public function test_requires_both_payment_and_withdrawal_permissions(): void
    {
        // Rol de prueba con SOLO 'pagos.registrar' (y lo minimo para pasar el
        // middleware/autorizacion basica), deliberadamente SIN 'pedidos.retirar',
        // para probar que la accion combinada exige AMBOS permisos.
        $role = \Spatie\Permission\Models\Role::create(['name' => 'solo_cobra', 'guard_name' => 'web']);
        $role->givePermissionTo(['pagos.registrar', 'pedidos.ver', 'pedidos.ver-todos']);

        $limited = User::factory()->create(['is_active' => true]);
        $limited->assignRole('solo_cobra');

        $order = $this->makeOrder(1);

        $response = $this->actingAs($limited)->postJson('/orders/bulk-pay-and-withdraw', [
            'order_ids' => [$order->id],
            'payment_method_id' => $this->cash->id,
        ]);

        $response->assertForbidden();
        $this->assertSame('no_retirado', $order->fresh()->withdrawal_status);
        $this->assertSame('0.00', number_format((float) $order->fresh()->total_paid, 2, '.', ''));
    }
}

