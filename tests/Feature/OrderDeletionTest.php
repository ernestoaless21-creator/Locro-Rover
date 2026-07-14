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
 * Fase 7 (correccion 2), seccion 4: "no puedo eliminar pedidos".
 *
 * Causa real encontrada: 'pedidos.eliminar' era un permiso definido pero
 * NUNCA otorgado a ningun rol salvo 'admin' (ver RolesAndPermissionsSeeder,
 * ni $commonOperational ni el $logisticsOnly original lo incluian). Un
 * usuario logistica/jefe_logistica recibia un 403 al intentar borrar, y el
 * frontend (Orders/Edit.vue) no mostraba ningun error (usaba router.delete
 * de Inertia sin manejar el fallo). Se corrigieron ambas causas:
 * 1) se agrego 'pedidos.eliminar' a $logisticsOnly (ver seeder).
 * 2) Orders/Edit.vue ahora usa axios + try/catch y siempre muestra un
 *    mensaje de error si falla.
 *
 * Ademas se confirma que el borrado es un SOFT DELETE (Order::SoftDeletes):
 * pagos e items NO se destruyen fisicamente.
 */
class OrderDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeOrder(User $rover, ?float $payment = null): Order
    {
        $client = Client::create(['first_name' => 'Cliente', 'last_name' => (string) random_int(1, 999999)]);

        $order = Order::create([
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'rover_id' => $rover->id,
            'created_by' => $rover->id,
            'updated_by' => $rover->id,
        ]);

        app(PricingService::class)->syncPortionsForOrder($order, 2, $this->year, $rover->id);

        if ($payment !== null) {
            $method = PaymentMethod::firstOrCreate(['slug' => 'efectivo'], ['name' => 'Efectivo', 'is_active' => true]);
            $order->payments()->create([
                'payment_method_id' => $method->id,
                'amount' => $payment,
                'paid_at' => now(),
                'registered_by' => $rover->id,
            ]);
        }

        return $order->fresh();
    }

    public function test_admin_can_delete_an_order(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $order = $this->makeOrder($admin);

        $response = $this->actingAs($admin)->deleteJson("/orders/{$order->id}");

        $response->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_logistica_can_now_delete_an_order(): void
    {
        // Antes de esta correccion, 'logistica' NO tenia 'pedidos.eliminar' y
        // esto fallaba con 403 silenciosamente.
        $logistica = User::factory()->create(['is_active' => true]);
        $logistica->assignRole('logistica');

        $order = $this->makeOrder($logistica);

        $response = $this->actingAs($logistica)->deleteJson("/orders/{$order->id}");

        $response->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_user_without_permission_gets_a_clear_json_error_instead_of_silent_failure(): void
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => 'sin_permiso_eliminar', 'guard_name' => 'web']);
        $role->givePermissionTo(['pedidos.ver', 'pedidos.crear', 'pedidos.editar', 'pedidos.ver-todos']);

        $limited = User::factory()->create(['is_active' => true]);
        $limited->assignRole('sin_permiso_eliminar');

        $order = $this->makeOrder($limited);

        $response = $this->actingAs($limited)->deleteJson("/orders/{$order->id}");

        $response->assertForbidden();
        $response->assertJsonStructure(['message']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    public function test_deleting_an_order_is_a_soft_delete_and_preserves_payments(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $order = $this->makeOrder($admin, payment: 20000);
        $paymentId = $order->payments()->first()->id;

        $this->actingAs($admin)->deleteJson("/orders/{$order->id}")->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        // El pago NO se borra ni fisica ni logicamente: sigue intacto.
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'deleted_at' => null]);

        // Un pedido soft-deleted desaparece de los listados normales.
        $this->assertNull(Order::find($order->id));
        $this->assertNotNull(Order::withTrashed()->find($order->id));
    }

    public function test_a_rover_can_only_delete_their_own_order(): void
    {
        $roverA = User::factory()->create(['is_active' => true]);
        $roverA->assignRole('logistica');
        $roverB = User::factory()->create(['is_active' => true]);
        $roverB->assignRole('logistica');

        // 'logistica' tiene 'pedidos.ver-todos', asi que este caso puntual no
        // aplica el recorte por rover propio; se prueba igual la regla de
        // OrderPolicy::delete con un rol SIN 'pedidos.ver-todos'.
        $role = \Spatie\Permission\Models\Role::create(['name' => 'rover_acotado', 'guard_name' => 'web']);
        $role->givePermissionTo(['pedidos.ver', 'pedidos.crear', 'pedidos.editar', 'pedidos.eliminar']);

        $roverLimitado = User::factory()->create(['is_active' => true]);
        $roverLimitado->assignRole('rover_acotado');

        $order = $this->makeOrder($roverB); // pedido de OTRO rover

        $response = $this->actingAs($roverLimitado)->deleteJson("/orders/{$order->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
    }
}

