<?php

namespace Tests\Feature;

use App\Models\InfrastructureInventoryItem;
use App\Models\InfrastructureItem;
use App\Models\InfrastructureLoan;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructurePhase15Test extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->year = Year::where('is_active', true)->firstOrFail();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');
        return $u;
    }

    protected function makeJefeInfraestructura(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('jefe_infraestructura');
        return $u;
    }

    protected function makeMember(string $team = 'infraestructura'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);
        return $u;
    }

    protected function makeItem(array $overrides = []): InfrastructureItem
    {
        return InfrastructureItem::create(array_merge(['name' => 'Olla de 100 litros'], $overrides));
    }

    protected function makeInventoryRow(InfrastructureItem $item, array $overrides = []): InfrastructureInventoryItem
    {
        $admin = $this->makeAdmin();
        return InfrastructureInventoryItem::create(array_merge([
            'year_id'                => $this->year->id,
            'infrastructure_item_id' => $item->id,
            'created_by'             => $admin->id,
        ], $overrides));
    }

    protected function makeLoan(InfrastructureItem $item, array $overrides = []): InfrastructureLoan
    {
        $admin = $this->makeAdmin();
        return InfrastructureLoan::create(array_merge([
            'year_id'                => $this->year->id,
            'infrastructure_item_id' => $item->id,
            'quantity'                => 1,
            'lender'                  => 'Juan',
            'created_by'              => $admin->id,
        ], $overrides));
    }

    // ─── Permisos y acceso ────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_see_inventory(): void
    {
        $this->get(route('infrastructure.index', ['team' => 'infraestructura']))->assertRedirect('/login');
    }

    public function test_member_of_other_team_can_view_inventory(): void
    {
        // El inventario se puede VER ampliamente, no solo desde Infraestructura.
        $member = $this->makeMember('logistica');
        $this->actingAs($member)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page->component('Infrastructure/Index'));
    }

    public function test_infraestructura_member_can_manage_inventory(): void
    {
        // Cualquier integrante de Infraestructura puede gestionar, no solo el jefe.
        $member = $this->makeMember('infraestructura');
        $item = $this->makeItem();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'needed_quantity'        => 9,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_inventory_items', [
            'infrastructure_item_id' => $item->id,
            'year_id'                => $this->year->id,
        ]);
    }

    public function test_jefe_infraestructura_can_manage_inventory(): void
    {
        $jefe = $this->makeJefeInfraestructura();
        $item = $this->makeItem();

        $this->actingAs($jefe)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
            ])
            ->assertRedirect();
    }

    public function test_other_team_member_cannot_manage_inventory(): void
    {
        $member = $this->makeMember('compras');
        $item = $this->makeItem();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_manage_inventory(): void
    {
        $admin = $this->makeAdmin();
        $item = $this->makeItem();

        $this->actingAs($admin)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
            ])
            ->assertRedirect();
    }

    public function test_only_infraestructura_team_slug_is_routable(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'compras']))
            ->assertNotFound();
    }

    // ─── Aislamiento por edición ──────────────────────────────────────────────

    public function test_inventory_is_isolated_by_year(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['year_id' => $other->id]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('infrastructure.index', ['team' => 'infraestructura']));

        $response->assertInertia(fn ($page) =>
            $page->component('Infrastructure/Index')->where('inventoryRows', fn ($rows) => count($rows) === 0)
        );
    }

    public function test_switching_year_shows_that_years_inventory(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $item = $this->makeItem();
        $rowThisYear = $this->makeInventoryRow($item, ['needed_quantity' => 9]);
        $rowOtherYear = $this->makeInventoryRow($item, ['year_id' => $other->id, 'needed_quantity' => 5]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura', 'year_id' => $this->year->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.id', $rowThisYear->id)
                ->where('inventoryRows.0.needed_quantity', 9)
            );

        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura', 'year_id' => $other->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.id', $rowOtherYear->id)
                ->where('inventoryRows.0.needed_quantity', 5)
            );
    }

    // ─── Catálogo ─────────────────────────────────────────────────────────────

    public function test_create_item_inline_with_inventory_row(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'new_item_name'   => 'Hornallón simple',
                'needed_quantity' => 8,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_items', ['name' => 'Hornallón simple']);
        $this->assertDatabaseHas('infrastructure_inventory_items', ['needed_quantity' => 8]);
    }

    public function test_duplicate_item_name_is_rejected(): void
    {
        $this->makeItem(['name' => 'Cajón negro']);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'new_item_name' => 'cajón negro',
            ])
            ->assertSessionHasErrors('new_item_name');

        $this->assertDatabaseCount('infrastructure_items', 1);
    }

    public function test_member_can_edit_catalog_item(): void
    {
        $item = $this->makeItem(['name' => 'Cuba', 'description' => null]);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.items.update', ['team' => 'infraestructura', 'item' => $item->id]), [
                'name'        => 'Cuba grande',
                'description' => 'Para lavar los cubos',
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertEquals('Cuba grande', $item->name);
        $this->assertEquals('Para lavar los cubos', $item->description);
    }

    public function test_editing_item_does_not_lose_historical_inventory(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $item = $this->makeItem(['name' => 'Pallet']);
        $rowOld = $this->makeInventoryRow($item, ['year_id' => $other->id, 'needed_quantity' => 4]);
        $rowNew = $this->makeInventoryRow($item, ['needed_quantity' => 6]);

        $member = $this->makeMember();
        $this->actingAs($member)
            ->put(route('infrastructure.items.update', ['team' => 'infraestructura', 'item' => $item->id]), [
                'name' => 'Pallet de madera',
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertEquals('Pallet de madera', $item->name);
        $this->assertEquals(4, $rowOld->fresh()->needed_quantity);
        $this->assertEquals(6, $rowNew->fresh()->needed_quantity);
    }

    public function test_removing_from_inventory_does_not_delete_global_item(): void
    {
        $item = $this->makeItem(['name' => 'Garrafa']);
        $row = $this->makeInventoryRow($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->delete(route('infrastructure.inventory.destroy', ['team' => 'infraestructura', 'inventory' => $row->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('infrastructure_inventory_items', ['id' => $row->id]);
        $this->assertDatabaseHas('infrastructure_items', ['id' => $item->id, 'name' => 'Garrafa']);
    }

    public function test_cannot_add_same_item_twice_in_same_year(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
            ])
            ->assertSessionHasErrors('infrastructure_item_id');

        $this->assertDatabaseCount('infrastructure_inventory_items', 1);
    }

    // ─── Cantidades y validaciones ──────────────────────────────────────────────

    public function test_stores_needed_own_and_repair_quantities(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'needed_quantity'        => 8,
                'own_available_quantity' => 8,
                'own_to_repair_quantity' => 2,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_inventory_items', [
            'needed_quantity'        => 8,
            'own_available_quantity' => 8,
            'own_to_repair_quantity' => 2,
        ]);
    }

    public function test_empty_repair_quantity_defaults_to_zero_on_create(): void
    {
        // Bug reportado: dejar "En reparación" vacío enviaba NULL y violaba
        // el NOT NULL de la columna. Vacío debe tratarse como 0.
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'needed_quantity'        => 8,
                'own_available_quantity' => 8,
                'own_to_repair_quantity' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_inventory_items', [
            'needed_quantity'        => 8,
            'own_available_quantity' => 8,
            'own_to_repair_quantity' => 0,
        ]);
    }

    public function test_all_empty_quantities_default_to_zero_on_create(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'needed_quantity'        => '',
                'own_available_quantity' => '',
                'own_to_repair_quantity' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_inventory_items', [
            'infrastructure_item_id' => $item->id,
            'needed_quantity'        => 0,
            'own_available_quantity' => 0,
            'own_to_repair_quantity' => 0,
        ]);
    }

    public function test_empty_repair_quantity_defaults_to_zero_on_update(): void
    {
        $item = $this->makeItem();
        $row = $this->makeInventoryRow($item, ['own_available_quantity' => 5, 'own_to_repair_quantity' => 2]);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.inventory.update', ['team' => 'infraestructura', 'inventory' => $row->id]), [
                'own_available_quantity' => 5,
                'own_to_repair_quantity' => '',
            ])
            ->assertRedirect();

        $this->assertEquals(0, $row->fresh()->own_to_repair_quantity);
    }

    public function test_repair_quantity_cannot_exceed_own_available_on_create(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.inventory.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'own_available_quantity' => 3,
                'own_to_repair_quantity' => 5,
            ])
            ->assertSessionHasErrors('own_to_repair_quantity');

        $this->assertDatabaseCount('infrastructure_inventory_items', 0);
    }

    public function test_repair_quantity_cannot_exceed_own_available_on_update(): void
    {
        $item = $this->makeItem();
        $row = $this->makeInventoryRow($item, ['own_available_quantity' => 5, 'own_to_repair_quantity' => 0]);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.inventory.update', ['team' => 'infraestructura', 'inventory' => $row->id]), [
                'own_to_repair_quantity' => 6,
            ])
            ->assertSessionHasErrors('own_to_repair_quantity');

        $this->assertEquals(0, $row->fresh()->own_to_repair_quantity);
    }

    public function test_partial_update_validates_against_existing_values(): void
    {
        // Enviar solo own_to_repair_quantity sin repetir own_available_quantity
        // debe seguir validando contra el valor YA guardado, no contra 0.
        $item = $this->makeItem();
        $row = $this->makeInventoryRow($item, ['own_available_quantity' => 8, 'own_to_repair_quantity' => 0]);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.inventory.update', ['team' => 'infraestructura', 'inventory' => $row->id]), [
                'own_to_repair_quantity' => 2,
            ])
            ->assertRedirect();

        $this->assertEquals(2, $row->fresh()->own_to_repair_quantity);
    }

    public function test_notes_are_optional(): void
    {
        $item = $this->makeItem();
        $row = $this->makeInventoryRow($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.inventory.update', ['team' => 'infraestructura', 'inventory' => $row->id]), [
                'notes' => 'Revisar conexiones antes del 8 de julio.',
            ])
            ->assertRedirect();

        $this->assertEquals('Revisar conexiones antes del 8 de julio.', $row->fresh()->notes);
    }

    // ─── Cálculo de disponibilidad y estado derivado ───────────────────────────

    public function test_own_useful_quantity_subtracts_repairs(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['own_available_quantity' => 8, 'own_to_repair_quantity' => 2]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.own_useful_quantity', 6)
            );
    }

    public function test_active_loans_are_added_to_total_useful(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 9, 'own_available_quantity' => 7]);
        $this->makeLoan($item, ['quantity' => 2, 'status' => 'pending']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.active_loans_quantity', 2)
                ->where('inventoryRows.0.total_useful_quantity', 9)
                ->where('inventoryRows.0.status', 'complete')
            );
    }

    public function test_returned_loans_do_not_count_as_active(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 9, 'own_available_quantity' => 7]);
        $this->makeLoan($item, ['quantity' => 2, 'status' => 'returned', 'returned_at' => '2026-07-10']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.active_loans_quantity', 0)
                ->where('inventoryRows.0.total_useful_quantity', 7)
                ->where('inventoryRows.0.status', 'missing')
                ->where('inventoryRows.0.diff_quantity', 2)
            );
    }

    public function test_status_complete_when_total_useful_matches_needed(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 9, 'own_available_quantity' => 7]);
        $this->makeLoan($item, ['quantity' => 2]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page->component('Infrastructure/Index')->where('inventoryRows.0.status', 'complete'));
    }

    public function test_status_missing_when_total_useful_below_needed(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 17, 'own_available_quantity' => 13]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'missing')
                ->where('inventoryRows.0.diff_quantity', 4)
            );
    }

    public function test_status_surplus_when_total_useful_above_needed(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 17, 'own_available_quantity' => 13]);
        $this->makeLoan($item, ['quantity' => 4]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'complete')
            );

        // Ahora con excedente real.
        $this->makeLoan($item, ['quantity' => 3]);
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'surplus')
                ->where('inventoryRows.0.diff_quantity', 3)
            );
    }

    public function test_missing_and_repairs_are_both_communicated(): void
    {
        // Necesarias: 8, propias: 8, a reparar: 2, sin préstamos → útiles: 6.
        // Debe reportar faltante (2) Y reparación (2) simultáneamente, sin
        // esconder una de las dos detrás de un único estado.
        $item = $this->makeItem();
        $this->makeInventoryRow($item, [
            'needed_quantity' => 8, 'own_available_quantity' => 8, 'own_to_repair_quantity' => 2,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'missing')
                ->where('inventoryRows.0.diff_quantity', 2)
                ->where('inventoryRows.0.own_to_repair_quantity', 2)
            );
    }

    public function test_complete_with_pending_repairs_still_reports_repairs(): void
    {
        // Total útil alcanza lo necesario, pero igual hay reparaciones
        // pendientes: la info no debe desaparecer solo porque "está completo".
        $item = $this->makeItem();
        $this->makeInventoryRow($item, [
            'needed_quantity' => 6, 'own_available_quantity' => 8, 'own_to_repair_quantity' => 2,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'complete')
                ->where('inventoryRows.0.own_to_repair_quantity', 2)
            );
    }

    // ─── Préstamos ────────────────────────────────────────────────────────────

    public function test_create_loan(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'quantity'                => 2,
                'lender'                  => 'Grupo San José',
                'notes'                   => 'Cinta roja en las manijas',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_loans', [
            'infrastructure_item_id' => $item->id,
            'quantity'                => 2,
            'lender'                  => 'Grupo San José',
            'notes'                   => 'Cinta roja en las manijas',
            'status'                  => 'pending',
            'year_id'                 => $this->year->id,
        ]);
    }

    public function test_lender_is_required(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'quantity'                => 1,
            ])
            ->assertSessionHasErrors('lender');
    }

    public function test_loan_quantity_must_be_positive(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'quantity'                => 0,
                'lender'                  => 'Juan',
            ])
            ->assertSessionHasErrors('quantity');
    }

    public function test_loan_notes_are_optional(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'quantity'                => 1,
                'lender'                  => 'Juan',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_loans', ['lender' => 'Juan', 'notes' => null]);
    }

    public function test_mark_loan_returned_without_date(): void
    {
        $item = $this->makeItem();
        $loan = $this->makeLoan($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.status', ['team' => 'infraestructura', 'loan' => $loan->id]), [
                'status' => 'returned',
            ])
            ->assertRedirect();

        $loan->refresh();
        $this->assertEquals('returned', $loan->status);
        $this->assertNull($loan->returned_at);
    }

    public function test_mark_loan_returned_with_date(): void
    {
        $item = $this->makeItem();
        $loan = $this->makeLoan($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.status', ['team' => 'infraestructura', 'loan' => $loan->id]), [
                'status'      => 'returned',
                'returned_at' => '2026-07-10',
            ])
            ->assertRedirect();

        $loan->refresh();
        $this->assertEquals('returned', $loan->status);
        $this->assertEquals('2026-07-10', $loan->returned_at->toDateString());
    }

    public function test_return_loan_to_pending_clears_returned_at(): void
    {
        $item = $this->makeItem();
        $loan = $this->makeLoan($item, ['status' => 'returned', 'returned_at' => '2026-07-10']);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('infrastructure.loans.status', ['team' => 'infraestructura', 'loan' => $loan->id]), [
                'status' => 'pending',
            ])
            ->assertRedirect();

        $loan->refresh();
        $this->assertEquals('pending', $loan->status);
        $this->assertNull($loan->returned_at);
    }

    public function test_returned_loan_no_longer_counts_as_active_availability(): void
    {
        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['needed_quantity' => 5, 'own_available_quantity' => 3]);
        $loan = $this->makeLoan($item, ['quantity' => 2, 'status' => 'pending']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'complete')
            );

        $loan->update(['status' => 'returned']);

        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('inventoryRows.0.status', 'missing')
                ->where('inventoryRows.0.diff_quantity', 2)
            );
    }

    public function test_edit_loan_quantity_and_lender(): void
    {
        $item = $this->makeItem();
        $loan = $this->makeLoan($item, ['quantity' => 1, 'lender' => 'Juan']);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('infrastructure.loans.update', ['team' => 'infraestructura', 'loan' => $loan->id]), [
                'quantity' => 3,
                'lender'   => 'Juan Pérez',
            ])
            ->assertRedirect();

        $loan->refresh();
        $this->assertEquals(3, $loan->quantity);
        $this->assertEquals('Juan Pérez', $loan->lender);
    }

    public function test_delete_loan(): void
    {
        $item = $this->makeItem();
        $loan = $this->makeLoan($item);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->delete(route('infrastructure.loans.destroy', ['team' => 'infraestructura', 'loan' => $loan->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('infrastructure_loans', ['id' => $loan->id]);
    }

    public function test_other_team_member_cannot_manage_loans(): void
    {
        $item = $this->makeItem();
        $member = $this->makeMember('publicidad');

        $this->actingAs($member)
            ->post(route('infrastructure.loans.store', ['team' => 'infraestructura']), [
                'infrastructure_item_id' => $item->id,
                'quantity'                => 1,
                'lender'                  => 'Juan',
            ])
            ->assertForbidden();
    }

    // ─── Resumen de préstamos ───────────────────────────────────────────────────

    public function test_loan_summary_counts(): void
    {
        $item1 = $this->makeItem(['name' => 'Olla 100 L']);
        $item2 = $this->makeItem(['name' => 'Cajones negros']);

        $this->makeLoan($item1, ['quantity' => 2, 'lender' => 'Grupo San José', 'status' => 'pending']);
        $this->makeLoan($item2, ['quantity' => 4, 'lender' => 'Juan', 'status' => 'pending']);
        $this->makeLoan($item2, ['quantity' => 1, 'lender' => 'Juan', 'status' => 'returned', 'returned_at' => '2026-07-01']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('infrastructure.index', ['team' => 'infraestructura']))
            ->assertInertia(fn ($page) => $page
                ->component('Infrastructure/Index')
                ->where('loanSummary.active_units', 6)
                ->where('loanSummary.active_count', 2)
                ->where('loanSummary.pending_lenders', 2)
                ->where('loanSummary.returned_count', 1)
            );
    }

    // ─── Importación ──────────────────────────────────────────────────────────

    public function test_import_copies_needed_and_own_quantities_without_repairs(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $item = $this->makeItem();
        $admin = $this->makeAdmin();

        InfrastructureInventoryItem::create([
            'year_id' => $source->id, 'infrastructure_item_id' => $item->id,
            'needed_quantity' => 8, 'own_available_quantity' => 8, 'own_to_repair_quantity' => 2,
            'notes' => 'Nota de la edición anterior', 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('infrastructure.import.store', ['team' => 'infraestructura']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $newRow = InfrastructureInventoryItem::where('year_id', $target->id)->firstOrFail();
        $this->assertEquals(8, $newRow->needed_quantity);
        $this->assertEquals(8, $newRow->own_available_quantity);

        // NO copiado: a reparar, observaciones.
        $this->assertEquals(0, $newRow->own_to_repair_quantity);
        $this->assertNull($newRow->notes);
    }

    public function test_import_never_copies_loans(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $item = $this->makeItem();
        $admin = $this->makeAdmin();

        InfrastructureInventoryItem::create([
            'year_id' => $source->id, 'infrastructure_item_id' => $item->id,
            'needed_quantity' => 5, 'created_by' => $admin->id,
        ]);
        InfrastructureLoan::create([
            'year_id' => $source->id, 'infrastructure_item_id' => $item->id,
            'quantity' => 2, 'lender' => 'Juan', 'status' => 'pending', 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('infrastructure.import.store', ['team' => 'infraestructura']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('infrastructure_loans', 1); // Solo el original, ninguno nuevo.
        $this->assertEquals(0, InfrastructureLoan::where('year_id', $target->id)->count());
    }

    public function test_import_allows_partial_item_selection(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $item1 = $this->makeItem(['name' => 'Olla 100 L']);
        $item2 = $this->makeItem(['name' => 'Pallet']);
        $this->makeInventoryRow($item1, ['year_id' => $source->id]);
        $this->makeInventoryRow($item2, ['year_id' => $source->id]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('infrastructure.import.store', ['team' => 'infraestructura']), [
                'source_year_id'    => $source->id,
                'target_year_id'    => $target->id,
                'selected_item_ids' => [$item1->id],
            ])
            ->assertRedirect();

        $targetRows = InfrastructureInventoryItem::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetRows);
        $this->assertEquals($item1->id, $targetRows->first()->infrastructure_item_id);
    }

    public function test_import_does_not_duplicate_items_already_in_target(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $item = $this->makeItem();
        $this->makeInventoryRow($item, ['year_id' => $source->id, 'needed_quantity' => 8]);
        $this->makeInventoryRow($item, ['year_id' => $target->id, 'needed_quantity' => 999]); // ya cargado a mano

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('infrastructure.import.store', ['team' => 'infraestructura']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $targetRows = InfrastructureInventoryItem::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetRows);
        $this->assertEquals(999, $targetRows->first()->needed_quantity);
    }

    public function test_member_cannot_access_import_page(): void
    {
        $member = $this->makeMember('publicidad');
        $this->actingAs($member)
            ->get(route('infrastructure.import', ['team' => 'infraestructura']))
            ->assertForbidden();
    }

    public function test_infraestructura_member_can_access_import_page(): void
    {
        $member = $this->makeMember();
        $this->actingAs($member)
            ->get(route('infrastructure.import', ['team' => 'infraestructura']))
            ->assertOk();
    }

    public function test_same_year_import_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('infrastructure.import.store', ['team' => 'infraestructura']), [
                'source_year_id' => $this->year->id,
                'target_year_id' => $this->year->id,
            ])
            ->assertStatus(422);
    }
}
