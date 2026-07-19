<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase P2 (correccion de orden): la tabla de Pedidos dejo de ordenarse por
 * created_at DESC (orden cronologico, que con imports historicos generaba
 * bloques que solo PARECIAN alfabeticos por casualidad) y ahora se ordena
 * explicitamente por apellido/nombre del cliente, resuelto en SQL
 * (OrderController::index, ver clientNameSortExpression()).
 *
 * Criterio esperado:
 *   0. apellido "COMPLETAR" (placeholder de import, ver
 *      RowTransformer::MISSING_NAME_PLACEHOLDER) siempre primero.
 *   1. apellidos que arrancan con un caracter no alfabetico.
 *   2. el resto, alfabetico (ignorando mayusculas y acentos).
 * Dentro de cada grupo: apellido, despues nombre, despues orders.id como
 * desempate estable.
 */
class OrderClientNameSortTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    /** Crea un cliente y un pedido para el, en la edicion de este test. */
    protected function makeOrderFor(?string $lastName, ?string $firstName = 'Test'): Order
    {
        $client = Client::create(['first_name' => $firstName, 'last_name' => $lastName]);

        return Order::create(['client_id' => $client->id, 'year_id' => $this->year->id]);
    }

    protected function orderedClientIds(User $user): array
    {
        $response = $this->actingAs($user)->get("/orders?year_id={$this->year->id}");
        $response->assertOk();

        return collect($response->inertiaProps('orders'))->pluck('client_id')->all();
    }

    public function test_sorts_alphabetically_by_last_name_ignoring_case_and_accents(): void
    {
        $admin = $this->makeAdmin();

        $zapata = $this->makeOrderFor('Zapata');
        $alvarezAccented = $this->makeOrderFor('Álvarez');
        $alvarezPlainUpper = $this->makeOrderFor('ALVAREZ');
        $bermudez = $this->makeOrderFor('Bermudez');

        $ids = $this->orderedClientIds($admin);

        // "Álvarez" y "ALVAREZ" normalizan al mismo valor ("alvarez"): quedan
        // adyacentes, antes de Bermudez y Zapata. El desempate entre ambos
        // (mismo apellido+nombre "Test") cae en orders.id ascendente.
        $this->assertSame([
            $alvarezAccented->client_id,
            $alvarezPlainUpper->client_id,
            $bermudez->client_id,
            $zapata->client_id,
        ], $ids);
    }

    public function test_completar_placeholder_always_goes_first(): void
    {
        $admin = $this->makeAdmin();

        $alvarez = $this->makeOrderFor('Alvarez');
        // El placeholder real de import es 'COMPLETAR', pero Client::normalizeName
        // lo guarda como "Completar" (title case) al pasar por saving().
        $missing = $this->makeOrderFor('COMPLETAR');

        $ids = $this->orderedClientIds($admin);

        $this->assertSame([$missing->client_id, $alvarez->client_id], $ids);
    }

    public function test_last_names_starting_with_non_alphabetic_char_go_right_after_completar(): void
    {
        $admin = $this->makeAdmin();

        $alvarez = $this->makeOrderFor('Alvarez');
        $missing = $this->makeOrderFor('COMPLETAR');
        $dash = $this->makeOrderFor('-Sin apellido');
        $dot = $this->makeOrderFor('.Pendiente');

        $ids = $this->orderedClientIds($admin);

        $this->assertSame($missing->client_id, $ids[0]);
        // Los dos apellidos con caracter inicial no alfabetico van despues de
        // COMPLETAR y antes de Alvarez (letra A), en orden alfabetico entre si.
        $this->assertSame([$dash->client_id, $dot->client_id], array_slice($ids, 1, 2));
        $this->assertSame($alvarez->client_id, $ids[3]);
    }

    public function test_ties_on_last_name_break_by_first_name_then_order_id(): void
    {
        $admin = $this->makeAdmin();

        $gomezZoe = $this->makeOrderFor('Gomez', 'Zoe');
        $gomezAna = $this->makeOrderFor('Gomez', 'Ana');
        // Mismo apellido+nombre normalizado que $gomezAna: el desempate final
        // es orders.id ascendente, es decir el orden de creacion.
        $gomezAnaAgain = $this->makeOrderFor('Gomez', 'ana');

        $ids = $this->orderedClientIds($admin);

        $this->assertSame([
            $gomezAna->client_id,
            $gomezAnaAgain->client_id,
            $gomezZoe->client_id,
        ], $ids);
    }

    public function test_search_and_filters_still_work_with_the_new_order(): void
    {
        $admin = $this->makeAdmin();

        $this->makeOrderFor('Zapata', 'Carlos');
        $target = $this->makeOrderFor('Alvarez', 'Beatriz');

        $response = $this->actingAs($admin)->get("/orders?year_id={$this->year->id}&search=Beatriz");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.client_id', $target->client_id)
        );
    }
}
