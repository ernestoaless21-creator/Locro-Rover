<?php

namespace Tests\Feature;

use App\Models\PurchaseCategory;
use App\Models\PurchasePlanItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePlanningPhase14Test extends TestCase
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

    protected function makeJefeCompras(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('jefe_compras');
        return $u;
    }

    protected function makeJefeLogistica(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('jefe_logistica');
        return $u;
    }

    protected function makeMember(string $team = 'compras'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);
        return $u;
    }

    protected function makeCategory(string $name = 'Verdulería'): PurchaseCategory
    {
        return PurchaseCategory::create(['name' => $name]);
    }

    protected function makeProduct(array $overrides = []): PurchaseProduct
    {
        return PurchaseProduct::create(array_merge([
            'name' => 'Maíz blanco',
            'unit' => 'kg',
        ], $overrides));
    }

    protected function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge(['name' => 'Juanitos'], $overrides));
    }

    protected function makeItem(PurchaseProduct $product, array $overrides = []): PurchasePlanItem
    {
        $admin = $this->makeAdmin();
        return PurchasePlanItem::create(array_merge([
            'year_id'             => $this->year->id,
            'purchase_product_id' => $product->id,
            'created_by'          => $admin->id,
        ], $overrides));
    }

    // ─── Permisos y acceso ────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_see_purchases(): void
    {
        $this->get(route('purchases.index', ['team' => 'compras']))->assertRedirect('/login');
    }

    public function test_member_of_other_team_can_view_purchases(): void
    {
        // La planificación de compras se puede VER ampliamente, no solo desde Compras.
        $member = $this->makeMember('logistica');
        $this->actingAs($member)
            ->get(route('purchases.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page->component('Purchases/Index'));
    }

    public function test_compras_member_cannot_manage_plan(): void
    {
        $member = $this->makeMember('compras');
        $product = $this->makeProduct();

        $this->actingAs($member)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'purchase_product_id' => $product->id,
            ])
            ->assertForbidden();
    }

    public function test_jefe_compras_can_manage_plan(): void
    {
        $jefe = $this->makeJefeCompras();
        $product = $this->makeProduct();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'purchase_product_id' => $product->id,
                'qty_1000'            => 70,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_plan_items', [
            'purchase_product_id' => $product->id,
            'year_id'             => $this->year->id,
        ]);
    }

    public function test_jefe_logistica_cannot_manage_purchase_plan(): void
    {
        // Gestionar compras es dominio especifico de Compras, a diferencia del cronograma.
        $jefe = $this->makeJefeLogistica();
        $product = $this->makeProduct();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'purchase_product_id' => $product->id,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_manage_plan_and_suppliers(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct();

        $this->actingAs($admin)
            ->post(route('purchases.items.store', ['team' => 'compras']), ['purchase_product_id' => $product->id])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('suppliers.store', ['team' => 'compras']), ['name' => 'Nuevo proveedor'])
            ->assertRedirect();
    }

    public function test_only_compras_team_slug_is_routable(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'logistica']))
            ->assertNotFound();
    }

    public function test_member_cannot_manage_suppliers(): void
    {
        $member = $this->makeMember('compras');
        $this->actingAs($member)
            ->post(route('suppliers.store', ['team' => 'compras']), ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_jefe_compras_can_manage_suppliers(): void
    {
        $jefe = $this->makeJefeCompras();
        $this->actingAs($jefe)
            ->post(route('suppliers.store', ['team' => 'compras']), ['name' => 'Verdulería El Sol'])
            ->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['name' => 'Verdulería El Sol']);
    }

    // ─── Aislamiento por edición ──────────────────────────────────────────────

    public function test_plan_items_are_isolated_by_year(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $product = $this->makeProduct();
        $this->makeItem($product, ['year_id' => $other->id]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('purchases.index', ['team' => 'compras']));

        $response->assertInertia(fn ($page) =>
            $page->component('Purchases/Index')->where('items', fn ($items) => count($items) === 0)
        );
    }

    public function test_index_shows_items_for_requested_year(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem($product);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras', 'year_id' => $this->year->id]))
            ->assertInertia(fn ($page) =>
                $page->component('Purchases/Index')
                    ->has('items', 1)
                    ->where('items.0.id', $item->id)
            );
    }

    // ─── Categorías ───────────────────────────────────────────────────────────

    public function test_jefe_compras_can_create_category(): void
    {
        $jefe = $this->makeJefeCompras();
        $this->actingAs($jefe)
            ->post(route('purchases.categories.store', ['team' => 'compras']), ['name' => 'Carnicería'])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_categories', ['name' => 'Carnicería']);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $this->makeCategory('Verdulería');
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.categories.store', ['team' => 'compras']), ['name' => 'verdulería'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('purchase_categories', 1);
    }

    // ─── Productos ────────────────────────────────────────────────────────────

    public function test_jefe_compras_can_create_product_inline_with_item(): void
    {
        $category = $this->makeCategory('Verdulería');
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'new_product_name'        => 'Zapallo',
                'new_product_category_id' => $category->id,
                'unit'                    => 'kg',
                'qty_1000'                => 40,
                'qty_1500'                => 60,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'name'                  => 'Zapallo',
            'purchase_category_id'  => $category->id,
        ]);
        $this->assertDatabaseHas('purchase_plan_items', [
            'year_id'  => $this->year->id,
            'qty_1000' => 40,
            'qty_1500' => 60,
        ]);
    }

    public function test_duplicate_product_name_is_rejected(): void
    {
        $this->makeProduct(['name' => 'Maíz blanco']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'new_product_name' => 'maíz blanco',
            ])
            ->assertSessionHasErrors('new_product_name');

        $this->assertDatabaseCount('purchase_products', 1);
    }

    public function test_product_requires_either_existing_or_new_name(): void
    {
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), ['qty_1000' => 10])
            ->assertSessionHasErrors('purchase_product_id');
    }

    // ─── Cantidades de referencia, unidad, campos opcionales ──────────────────

    public function test_item_stores_1000_and_1500_reference_quantities(): void
    {
        $product = $this->makeProduct();
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'purchase_product_id' => $product->id,
                'qty_1000'            => 70,
                'qty_1500'            => 87.5,
                'unit'                => 'kg',
            ])
            ->assertRedirect();

        $item = PurchasePlanItem::where('purchase_product_id', $product->id)->firstOrFail();
        $this->assertEquals('70.000', $item->qty_1000);
        $this->assertEquals('87.500', $item->qty_1500);
        $this->assertEquals('kg', $item->unit);
    }

    public function test_actual_quantity_is_optional(): void
    {
        $product = $this->makeProduct();
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), ['purchase_product_id' => $product->id])
            ->assertRedirect();

        $item = PurchasePlanItem::where('purchase_product_id', $product->id)->firstOrFail();
        $this->assertNull($item->actual_quantity);

        $this->actingAs($jefe)
            ->put(route('purchases.items.update', ['team' => 'compras', 'item' => $item->id]), [
                'actual_quantity' => 90,
            ])
            ->assertRedirect();

        $this->assertEquals('90.000', $item->fresh()->actual_quantity);
    }

    public function test_estimated_price_is_optional(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->assertNull($item->estimated_total_price);

        $this->actingAs($jefe)
            ->put(route('purchases.items.update', ['team' => 'compras', 'item' => $item->id]), [
                'estimated_total_price' => 332500,
            ])
            ->assertRedirect();

        $this->assertEquals('332500.00', $item->fresh()->estimated_total_price);
    }

    public function test_real_price_is_optional(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.items.update', ['team' => 'compras', 'item' => $item->id]), [
                'actual_total_price' => 350000,
            ])
            ->assertRedirect();

        $this->assertEquals('350000.00', $item->fresh()->actual_total_price);
    }

    public function test_planned_and_actual_supplier_are_independent_and_optional(): void
    {
        $product = $this->makeProduct();
        $supplierA = $this->makeSupplier(['name' => 'Proveedor A']);
        $supplierB = $this->makeSupplier(['name' => 'Proveedor B']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), [
                'purchase_product_id' => $product->id,
                'planned_supplier_id' => $supplierA->id,
            ])
            ->assertRedirect();

        $item = PurchasePlanItem::where('purchase_product_id', $product->id)->firstOrFail();
        $this->assertEquals($supplierA->id, $item->planned_supplier_id);
        $this->assertNull($item->actual_supplier_id);

        $this->actingAs($jefe)
            ->put(route('purchases.items.update', ['team' => 'compras', 'item' => $item->id]), [
                'actual_supplier_id' => $supplierB->id,
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertEquals($supplierA->id, $item->planned_supplier_id);
        $this->assertEquals($supplierB->id, $item->actual_supplier_id);
    }

    public function test_observations_can_be_saved(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.items.update', ['team' => 'compras', 'item' => $item->id]), [
                'notes' => 'Comprar con anticipación.',
            ])
            ->assertRedirect();

        $this->assertEquals('Comprar con anticipación.', $item->fresh()->notes);
    }

    public function test_cannot_add_same_product_twice_in_same_year(): void
    {
        $product = $this->makeProduct();
        $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), ['purchase_product_id' => $product->id])
            ->assertSessionHasErrors('purchase_product_id');

        $this->assertDatabaseCount('purchase_plan_items', 1);
    }

    public function test_delete_item_removes_it_from_plan(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->delete(route('purchases.items.destroy', ['team' => 'compras', 'item' => $item->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('purchase_plan_items', ['id' => $item->id]);
    }

    public function test_removing_from_plan_does_not_delete_global_product(): void
    {
        $product = $this->makeProduct(['name' => 'Maíz blanco']);
        $item = $this->makeItem($product);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->delete(route('purchases.items.destroy', ['team' => 'compras', 'item' => $item->id]))
            ->assertRedirect();

        // El producto sigue existiendo en el catálogo global, reutilizable.
        $this->assertDatabaseHas('purchase_products', ['id' => $product->id, 'name' => 'Maíz blanco']);

        // Y puede volver a agregarse a la planificación sin problema.
        $this->actingAs($jefe)
            ->post(route('purchases.items.store', ['team' => 'compras']), ['purchase_product_id' => $product->id])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_plan_items', ['purchase_product_id' => $product->id, 'year_id' => $this->year->id]);
    }

    // ─── Edición del catálogo de productos ─────────────────────────────────────

    public function test_jefe_compras_can_rename_existing_product(): void
    {
        $product = $this->makeProduct(['name' => 'Maiz blanco']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name' => 'Maíz blanco',
            ])
            ->assertRedirect();

        $this->assertEquals('Maíz blanco', $product->fresh()->name);
    }

    public function test_jefe_compras_can_change_product_category(): void
    {
        $product = $this->makeProduct(); // sin categoría
        $category = $this->makeCategory('Granos');
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name'                  => $product->name,
                'purchase_category_id'  => $category->id,
            ])
            ->assertRedirect();

        $this->assertEquals($category->id, $product->fresh()->purchase_category_id);
    }

    public function test_jefe_compras_can_change_product_default_unit(): void
    {
        $product = $this->makeProduct(['unit' => 'kg']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name' => $product->name,
                'unit' => 'unidad',
            ])
            ->assertRedirect();

        $this->assertEquals('unidad', $product->fresh()->unit);
    }

    public function test_editing_product_does_not_lose_historical_plan_items(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $category = $this->makeCategory('Verdulería');
        $product = $this->makeProduct(['purchase_category_id' => $category->id]);

        $itemOld = $this->makeItem($product, ['year_id' => $other->id, 'qty_1000' => 65]);
        $itemNew = $this->makeItem($product, ['year_id' => $this->year->id, 'qty_1000' => 70]);

        $newCategory = $this->makeCategory('Granos');
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name'                 => 'Maíz blanco corregido',
                'purchase_category_id' => $newCategory->id,
            ])
            ->assertRedirect();

        // El catálogo se actualizó...
        $product->refresh();
        $this->assertEquals('Maíz blanco corregido', $product->name);
        $this->assertEquals($newCategory->id, $product->purchase_category_id);

        // ...pero las planificaciones históricas de ambos años siguen intactas
        // y siguen apuntando al mismo producto (ahora con el nombre corregido).
        $this->assertEquals('65.000', $itemOld->fresh()->qty_1000);
        $this->assertEquals('70.000', $itemNew->fresh()->qty_1000);
        $this->assertEquals($product->id, $itemOld->fresh()->purchase_product_id);
        $this->assertEquals($product->id, $itemNew->fresh()->purchase_product_id);
    }

    public function test_renaming_product_to_existing_name_is_rejected(): void
    {
        $this->makeProduct(['name' => 'Porotos']);
        $product = $this->makeProduct(['name' => 'Zapallo']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name' => 'porotos',
            ])
            ->assertSessionHasErrors('name');

        $this->assertEquals('Zapallo', $product->fresh()->name);
    }

    public function test_member_cannot_edit_product(): void
    {
        $product = $this->makeProduct();
        $member = $this->makeMember('compras');

        $this->actingAs($member)
            ->put(route('purchases.products.update', ['team' => 'compras', 'product' => $product->id]), [
                'name' => 'Otro nombre',
            ])
            ->assertForbidden();
    }

    // ─── Totales ──────────────────────────────────────────────────────────────

    public function test_totals_sum_estimated_and_real_prices(): void
    {
        $p1 = $this->makeProduct(['name' => 'Maíz blanco']);
        $p2 = $this->makeProduct(['name' => 'Porotos']);
        $this->makeItem($p1, ['estimated_total_price' => 100000, 'actual_total_price' => 110000]);
        // Evidencia de compra real (cantidad comprada) pero sin precio real cargado todavía.
        $this->makeItem($p2, ['estimated_total_price' => 50000, 'actual_quantity' => 45, 'actual_total_price' => null]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('totals.estimated', 150000)
                ->where('totals.real', 110000)
                ->where('totals.items_without_real_price', 1)
            );
    }

    public function test_planned_item_without_any_real_data_does_not_count_as_purchase_without_price(): void
    {
        // Un producto recién agregado a la planificación, sin ningún dato de
        // ejecución real todavía, no es una "compra sin precio real": todavía
        // no hay evidencia de que la compra haya ocurrido.
        $product = $this->makeProduct();
        $this->makeItem($product, ['qty_1000' => 70, 'estimated_total_price' => 100000]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('totals.items_without_real_price', 0)
            );
    }

    public function test_item_with_real_supplier_but_no_real_price_counts_as_purchase_without_price(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $this->makeItem($product, ['actual_supplier_id' => $supplier->id, 'actual_total_price' => null]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('totals.items_without_real_price', 1)
            );
    }

    public function test_totals_zero_when_no_items(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('totals.estimated', 0)
                ->where('totals.real', 0)
                ->where('totals.items_without_real_price', 0)
            );
    }

    // ─── Navegación histórica ──────────────────────────────────────────────────

    public function test_switching_year_shows_that_years_plan(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $product = $this->makeProduct();
        $itemThisYear = $this->makeItem($product, ['qty_1000' => 70]);
        $itemOtherYear = $this->makeItem($product, ['year_id' => $other->id, 'qty_1000' => 65]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras', 'year_id' => $this->year->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('items.0.id', $itemThisYear->id)
            );

        $this->actingAs($admin)
            ->get(route('purchases.index', ['team' => 'compras', 'year_id' => $other->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->where('items.0.id', $itemOtherYear->id)
            );
    }

    // ─── Historial preservado / referencias por edición ────────────────────────

    public function test_reference_quantities_are_preserved_per_year_not_overwritten(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $product = $this->makeProduct();

        $this->makeItem($product, ['year_id' => $other->id, 'qty_1000' => 65, 'qty_1500' => null]);
        $this->makeItem($product, ['year_id' => $this->year->id, 'qty_1000' => 70, 'qty_1500' => 87.5]);

        $itemOld = PurchasePlanItem::where('year_id', $other->id)->firstOrFail();
        $itemNew = PurchasePlanItem::where('year_id', $this->year->id)->firstOrFail();

        // Cambiar la referencia de la edición nueva no debe afectar la vieja.
        $this->assertEquals('65.000', $itemOld->qty_1000);
        $this->assertEquals('70.000', $itemNew->qty_1000);
        $this->assertNull($itemOld->qty_1500);
        $this->assertEquals('87.500', $itemNew->qty_1500);
    }

    public function test_supplier_is_reusable_across_years(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $supplier = $this->makeSupplier(['name' => 'Juanitos']);
        $p1 = $this->makeProduct(['name' => 'Maíz blanco']);
        $p2 = $this->makeProduct(['name' => 'Porotos']);

        $this->makeItem($p1, ['year_id' => $other->id, 'actual_supplier_id' => $supplier->id]);
        $this->makeItem($p2, ['year_id' => $this->year->id, 'planned_supplier_id' => $supplier->id]);

        $this->assertEquals(2, PurchasePlanItem::where(function ($q) use ($supplier) {
            $q->where('planned_supplier_id', $supplier->id)->orWhere('actual_supplier_id', $supplier->id);
        })->count());
    }

    // ─── Importación ──────────────────────────────────────────────────────────

    public function test_import_copies_planning_fields_without_execution_data(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $category = $this->makeCategory('Verdulería');
        $product = $this->makeProduct(['purchase_category_id' => $category->id]);
        $supplier = $this->makeSupplier();
        $admin = $this->makeAdmin();

        PurchasePlanItem::create([
            'year_id'                => $source->id,
            'purchase_product_id'    => $product->id,
            'qty_1000'               => 70,
            'qty_1500'               => 87.5,
            'unit'                   => 'kg',
            'estimated_total_price'  => 332500,
            'planned_supplier_id'    => $supplier->id,
            'actual_quantity'        => 90,
            'actual_total_price'     => 350000,
            'actual_supplier_id'     => $supplier->id,
            'notes'                  => 'Observación de ejecución 2022',
            'created_by'             => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('purchases.import.store', ['team' => 'compras']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $newItem = PurchasePlanItem::where('year_id', $target->id)->firstOrFail();
        $this->assertEquals($product->id, $newItem->purchase_product_id);
        $this->assertEquals('70.000', $newItem->qty_1000);
        $this->assertEquals('87.500', $newItem->qty_1500);
        $this->assertEquals('kg', $newItem->unit);
        $this->assertEquals('332500.00', $newItem->estimated_total_price);
        $this->assertEquals($supplier->id, $newItem->planned_supplier_id);

        // NO copiado: ejecución real.
        $this->assertNull($newItem->actual_quantity);
        $this->assertNull($newItem->actual_total_price);
        $this->assertNull($newItem->actual_supplier_id);
        $this->assertNull($newItem->notes);
    }

    public function test_import_allows_partial_product_selection(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $p1 = $this->makeProduct(['name' => 'Maíz blanco']);
        $p2 = $this->makeProduct(['name' => 'Porotos']);
        $this->makeItem($p1, ['year_id' => $source->id]);
        $this->makeItem($p2, ['year_id' => $source->id]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('purchases.import.store', ['team' => 'compras']), [
                'source_year_id'       => $source->id,
                'target_year_id'       => $target->id,
                'selected_product_ids' => [$p1->id],
            ])
            ->assertRedirect();

        $targetItems = PurchasePlanItem::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetItems);
        $this->assertEquals($p1->id, $targetItems->first()->purchase_product_id);
    }

    public function test_import_does_not_duplicate_products_already_in_target(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $product = $this->makeProduct();
        $this->makeItem($product, ['year_id' => $source->id, 'qty_1000' => 70]);
        $this->makeItem($product, ['year_id' => $target->id, 'qty_1000' => 999]); // ya cargado a mano

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('purchases.import.store', ['team' => 'compras']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $targetItems = PurchasePlanItem::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetItems);
        // Se conserva el valor ya cargado manualmente, no se pisa con el importado.
        $this->assertEquals('999.000', $targetItems->first()->qty_1000);
    }

    public function test_member_cannot_access_import_page(): void
    {
        $member = $this->makeMember('compras');
        $this->actingAs($member)
            ->get(route('purchases.import', ['team' => 'compras']))
            ->assertForbidden();
    }

    public function test_same_year_import_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('purchases.import.store', ['team' => 'compras']), [
                'source_year_id' => $this->year->id,
                'target_year_id' => $this->year->id,
            ])
            ->assertStatus(422);
    }

    // ─── Proveedores ──────────────────────────────────────────────────────────

    public function test_supplier_optional_fields(): void
    {
        $jefe = $this->makeJefeCompras();
        $this->actingAs($jefe)
            ->post(route('suppliers.store', ['team' => 'compras']), ['name' => 'Solo nombre'])
            ->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Solo nombre', 'phone' => null, 'address' => null, 'notes' => null,
        ]);
    }

    public function test_duplicate_supplier_name_is_rejected(): void
    {
        $this->makeSupplier(['name' => 'Juanitos']);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('suppliers.store', ['team' => 'compras']), ['name' => 'juanitos'])
            ->assertSessionHasErrors('name');
    }

    public function test_jefe_compras_can_update_supplier(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Juanitos', 'phone' => null]);
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('suppliers.update', ['team' => 'compras', 'supplier' => $supplier->id]), [
                'name'  => 'Juanitos',
                'phone' => '11-2222-3333',
            ])
            ->assertRedirect();

        $this->assertEquals('11-2222-3333', $supplier->fresh()->phone);
    }

    public function test_jefe_compras_can_deactivate_supplier(): void
    {
        $supplier = $this->makeSupplier();
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->put(route('suppliers.update', ['team' => 'compras', 'supplier' => $supplier->id]), [
                'name'      => $supplier->name,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($supplier->fresh()->is_active);
    }

    public function test_standalone_product_creation_route_works(): void
    {
        // Ruta usada por una futura gestión de catálogo standalone (no solo
        // la creación inline dentro de "agregar ítem").
        $category = $this->makeCategory('Condimentos');
        $jefe = $this->makeJefeCompras();

        $this->actingAs($jefe)
            ->post(route('purchases.products.store', ['team' => 'compras']), [
                'name'                  => 'Pimentón',
                'purchase_category_id'  => $category->id,
                'unit'                  => 'kg',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_products', ['name' => 'Pimentón', 'purchase_category_id' => $category->id]);
    }

    public function test_suppliers_index_shows_purchase_history_summary(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $this->makeItem($product, ['actual_supplier_id' => $supplier->id, 'actual_total_price' => 350000]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('suppliers.index', ['team' => 'compras']))
            ->assertInertia(fn ($page) => $page
                ->component('Suppliers/Index')
                ->where('suppliers.0.purchase_count', 1)
                ->where('suppliers.0.total_spent', 350000)
            );
    }
}
