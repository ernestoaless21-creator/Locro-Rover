<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Gift;
use App\Models\Loss;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Year;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7 (correccion 4): tests SOLO para la logica backend nueva de esta
 * entrega (filtro Delivery/Retiro y los indicadores compactos nuevos:
 * gifts_count, losses_count, y el desglose de recaudacion gateado por
 * 'finanzas.ver'). No se tocan/repiten tests de fases anteriores.
 *
 * IMPORTANTE (indicado explicitamente por el usuario, respetado aca):
 * RolesAndPermissionsSeeder ya crea el año 2026 (usa date('Y') internamente,
 * que en este momento resuelve a 2026). Por eso NO se hace
 * Year::create(['year' => 2026, ...]) — se reutiliza el año ya sembrado con
 * Year::where('year', 2026)->firstOrFail().
 */
class OrderCompactIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeOrder(User $rover, bool $takeAway, ?string $deliveryAddress = null): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => $rover->id,
            'created_by' => $rover->id,
            'updated_by' => $rover->id,
            'take_away' => $takeAway,
            'delivery_address' => $deliveryAddress,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->year, $rover->id);

        return $order->fresh();
    }

    public function test_delivery_type_filter_respects_take_away_semantics(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // take_away = true -> "retira en mano" (Retiro).
        $pickup = $this->makeOrder($admin, takeAway: true);
        // take_away = false -> Delivery.
        $delivery = $this->makeOrder($admin, takeAway: false, deliveryAddress: 'Av. Siempre Viva 742');

        $retiroResponse = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&delivery_type=retiro");
        $retiroResponse->assertOk();
        $retiroResponse->assertInertia(fn ($page) => $page
            ->where('orders.data.0.id', $pickup->id)
            ->where('orders.total', 1)
        );

        $deliveryResponse = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&delivery_type=delivery");
        $deliveryResponse->assertOk();
        $deliveryResponse->assertInertia(fn ($page) => $page
            ->where('orders.data.0.id', $delivery->id)
            ->where('orders.total', 1)
        );

        // Sin filtro: ambos pedidos.
        $allResponse = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");
        $allResponse->assertOk();
        $allResponse->assertInertia(fn ($page) => $page->where('orders.total', 2));
    }

    public function test_delivery_type_filter_does_not_reset_other_existing_filters(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $delivery = $this->makeOrder($admin, takeAway: false, deliveryAddress: 'Calle Falsa 123');

        $response = $this->actingAs($admin)->get(
            "/orders?year_id={$this->year->id}&delivery_type=delivery&payment_status=pendiente&withdrawal_status=no_retirado"
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.delivery_type', 'delivery')
            ->where('filters.payment_status', 'pendiente')
            ->where('filters.withdrawal_status', 'no_retirado')
            ->where('orders.data.0.id', $delivery->id)
        );
    }

    public function test_compact_counters_include_gift_and_loss_record_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Gift::create(['year_id' => $this->year->id, 'recipient_name' => 'Carsten', 'quantity' => 2, 'created_by' => $admin->id]);
        Gift::create(['year_id' => $this->year->id, 'recipient_name' => 'Otro', 'quantity' => 1, 'created_by' => $admin->id]);
        Loss::create(['year_id' => $this->year->id, 'quantity' => 3, 'reason' => 'Se cayo una olla', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('counters.gifts_count', 2)
            ->where('counters.losses_count', 1)
        );
    }

    public function test_collected_breakdown_is_hidden_without_finanzas_ver_permission(): void
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => 'sin_finanzas', 'guard_name' => 'web']);
        $role->givePermissionTo(['pedidos.ver', 'pedidos.ver-todos']);

        $limited = User::factory()->create(['is_active' => true]);
        $limited->assignRole('sin_finanzas');

        $response = $this->actingAs($limited)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->missing('counters.collected'));
    }

    public function test_collected_breakdown_sums_real_registered_payments_by_method(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin'); // 'admin' tiene 'finanzas.ver'.

        $efectivo = PaymentMethod::where('slug', 'efectivo')->firstOrFail();
        $transferencia = PaymentMethod::where('slug', 'transferencia')->firstOrFail();

        $order = $this->makeOrder($admin, takeAway: true);
        // Anticipo parcial en efectivo + otro pago en transferencia: la
        // recaudacion debe basarse en los PAGOS reales (incluye parciales),
        // no en el total teorico del pedido.
        $order->payments()->create(['payment_method_id' => $efectivo->id, 'amount' => 5000, 'paid_at' => now(), 'registered_by' => $admin->id]);
        $order->payments()->create(['payment_method_id' => $transferencia->id, 'amount' => 7000, 'paid_at' => now(), 'registered_by' => $admin->id]);

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('counters.collected.efectivo', 5000)
            ->where('counters.collected.banco', 7000)
            ->where('counters.collected.total', 12000)
        );
    }
}
