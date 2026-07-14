<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 8 (correccion): sort alfabetico por last_name+first_name y filtro
 * "Mis clientes asignados" en la pantalla de Clientes.
 */
class ClientPhase8CorrectionTest extends TestCase
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

    // ---------- ORDEN ALFABETICO POR APELLIDO + NOMBRE -----------------------

    public function test_default_sort_orders_by_last_name_then_first_name_asc(): void
    {
        $admin = $this->makeAdmin();

        Client::create(['first_name' => 'Zara', 'last_name' => 'Lopez', 'created_by' => $admin->id]);
        Client::create(['first_name' => 'Ana', 'last_name' => 'Lopez', 'created_by' => $admin->id]);
        Client::create(['first_name' => 'Bruno', 'last_name' => 'Alvarez', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.last_name', 'Alvarez')
            ->where('clients.data.1.last_name', 'Lopez')
            ->where('clients.data.1.first_name', 'Ana')    // Ana antes que Zara
            ->where('clients.data.2.last_name', 'Lopez')
            ->where('clients.data.2.first_name', 'Zara')
        );
    }

    public function test_same_last_name_sorted_by_first_name_asc(): void
    {
        $admin = $this->makeAdmin();

        Client::create(['first_name' => 'Mario', 'last_name' => 'Gomez', 'created_by' => $admin->id]);
        Client::create(['first_name' => 'Carlos', 'last_name' => 'Gomez', 'created_by' => $admin->id]);
        Client::create(['first_name' => 'Ana', 'last_name' => 'Gomez', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.first_name', 'Ana')
            ->where('clients.data.1.first_name', 'Carlos')
            ->where('clients.data.2.first_name', 'Mario')
        );
    }

    // ---------- FILTRO: MIS CLIENTES ASIGNADOS (en /clients) -----------------

    public function test_my_assigned_clients_filter_shows_only_assigned_clients(): void
    {
        $admin = $this->makeAdmin();
        $other = User::factory()->create(['is_active' => true]);

        $myClient = Client::create(['first_name' => 'Mio', 'last_name' => 'Asignado', 'created_by' => $admin->id]);
        $otherClient = Client::create(['first_name' => 'Otro', 'last_name' => 'Ajeno', 'created_by' => $admin->id]);

        ClientAssignment::create([
            'client_id' => $myClient->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $admin->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        ClientAssignment::create([
            'client_id' => $otherClient->id,
            'year_id' => $this->year->id,
            'assigned_user_id' => $other->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 1)
            ->where('clients.data.0.id', $myClient->id)
        );
    }

    public function test_my_assigned_clients_filter_respects_year(): void
    {
        $admin = $this->makeAdmin();

        $otherYear = Year::create([
            'year' => 2024,
            'label' => 'Locro 2024',
            'is_active' => false,
            'event_type' => 'locro',
        ]);

        $client = Client::create(['first_name' => 'Test', 'last_name' => 'Year', 'created_by' => $admin->id]);

        // Asignado solo en 2024, NO en el año activo.
        ClientAssignment::create([
            'client_id' => $client->id,
            'year_id' => $otherYear->id,
            'assigned_user_id' => $admin->id,
            'contact_status' => ClientAssignment::STATUS_PENDIENTE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('clients.total', 0));
    }

    public function test_my_assigned_clients_filter_is_included_in_filters_prop(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}&my_assigned_clients=1");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('filters.my_assigned_clients', '1'));
    }

    public function test_without_my_assigned_clients_filter_all_clients_are_shown(): void
    {
        $admin = $this->makeAdmin();
        $other = User::factory()->create(['is_active' => true]);

        Client::create(['first_name' => 'Uno', 'last_name' => 'A', 'created_by' => $admin->id]);
        Client::create(['first_name' => 'Dos', 'last_name' => 'B', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get("/clients?year_id={$this->year->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('clients.total', 2));
    }
}
