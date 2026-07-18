<?php

namespace Tests\Feature;

use App\Models\LogisticsCategory;
use App\Models\LogisticsRecord;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 17: logística histórica (archivo de recorridos, mapas, exportaciones
 * y listados por edición).
 */
class LogisticsPhase17Test extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->year = Year::where('is_active', true)->firstOrFail();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    protected function makeJefeLogistica(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('jefe_logistica');

        return $u;
    }

    protected function makeMember(string $team = 'logistica'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);

        return $u;
    }

    protected function fakeFile(string $name = 'recorrido.pdf', int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    protected function makeCategory(string $name = 'Recorridos'): LogisticsCategory
    {
        return LogisticsCategory::firstOrCreate(['name' => $name]);
    }

    protected function createRecord(array $overrides = []): LogisticsRecord
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();
        $file = $this->fakeFile();
        $path = $file->store("logistics-records/{$this->year->id}", 'local');

        return LogisticsRecord::create(array_merge([
            'year_id' => $this->year->id,
            'logistics_category_id' => $category->id,
            'title' => 'Recorrido barrio norte',
            'file_path' => $path,
            'file_name' => 'recorrido.pdf',
            'file_size' => 204800,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $admin->id,
        ], $overrides));
    }

    // ─── Categorías iniciales ───────────────────────────────────────────────────

    public function test_migration_seeds_initial_categories(): void
    {
        foreach (['Recorridos', 'Mapas', 'Exportaciones', 'Listados telefónicos', 'Etiquetas', 'Otros'] as $name) {
            $this->assertDatabaseHas('logistics_categories', ['name' => $name]);
        }
    }

    // ─── Permisos y acceso ────────────────────────────────────────────────────

    public function test_guest_cannot_view_logistics(): void
    {
        $this->get(route('logistics.index', ['team' => 'logistica']))->assertRedirect('/login');
    }

    public function test_other_team_member_cannot_view_logistics(): void
    {
        // Mismo esquema que Documentación/Publicidad: acceso acotado al propio equipo.
        $member = $this->makeMember('compras');
        $this->actingAs($member)
            ->get(route('logistics.index', ['team' => 'logistica']))
            ->assertForbidden();
    }

    public function test_logistica_member_can_view_and_manage(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo, no solo el jefe.
        $member = $this->makeMember('logistica');
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->get(route('logistics.index', ['team' => 'logistica']))
            ->assertInertia(fn ($page) => $page->component('Logistics/Index')->where('canManage', true));

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido del member',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', ['title' => 'Recorrido del member']);
    }

    public function test_jefe_logistica_can_manage(): void
    {
        $jefe = $this->makeJefeLogistica();
        $category = $this->makeCategory();

        $this->actingAs($jefe)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido del jefe',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', ['title' => 'Recorrido del jefe']);
    }

    public function test_other_team_member_cannot_upload(): void
    {
        $member = $this->makeMember('infraestructura');
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Intento',
                'file' => $this->fakeFile(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('logistics_records', 0);
    }

    public function test_admin_can_manage_any_team(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();

        $this->actingAs($admin)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido admin',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();
    }

    public function test_only_logistica_team_slug_is_routable(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('logistics.index', ['team' => 'compras']))
            ->assertNotFound();
    }

    // ─── Aislamiento por edición ──────────────────────────────────────────────

    public function test_records_are_isolated_by_year(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $this->createRecord(['year_id' => $other->id]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('logistics.index', ['team' => 'logistica']))
            ->assertInertia(fn ($page) => $page->component('Logistics/Index')->where('records', fn ($r) => count($r) === 0));
    }

    public function test_switching_year_shows_that_years_records(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $thisYearRecord = $this->createRecord(['title' => 'De esta edición']);
        $otherYearRecord = $this->createRecord(['year_id' => $other->id, 'title' => 'De la otra edición']);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('logistics.index', ['team' => 'logistica', 'year_id' => $this->year->id]))
            ->assertInertia(fn ($page) => $page->component('Logistics/Index')->where('records.0.id', $thisYearRecord->id));

        $this->actingAs($admin)
            ->get(route('logistics.index', ['team' => 'logistica', 'year_id' => $other->id]))
            ->assertInertia(fn ($page) => $page->component('Logistics/Index')->where('records.0.id', $otherYearRecord->id));
    }

    // ─── Crear registro ───────────────────────────────────────────────────────

    public function test_create_record_with_minimum_fields(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido mínimo',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', [
            'title' => 'Recorrido mínimo',
            'logistics_category_id' => $category->id,
            'description' => null,
            'notes' => null,
            'record_date' => null,
        ]);
    }

    public function test_create_record_with_all_fields(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory('Exportaciones');

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Exportación de pedidos',
                'description' => 'Exportación de pedidos para reparto',
                'notes' => 'Actualizar antes de imprimir',
                'record_date' => '2026-07-05',
                'file' => $this->fakeFile('exportacion_pedidos.xlsx', 500),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', [
            'title' => 'Exportación de pedidos',
            'description' => 'Exportación de pedidos para reparto',
            'notes' => 'Actualizar antes de imprimir',
            'record_date' => '2026-07-05',
        ]);
    }

    // ─── Finalidad (purpose) ────────────────────────────────────────────────

    public function test_create_record_with_purpose(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido con finalidad',
                'purpose' => 'Reparto turno mañana',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', [
            'title' => 'Recorrido con finalidad',
            'purpose' => 'Reparto turno mañana',
        ]);
    }

    public function test_create_record_without_purpose_is_optional(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Recorrido sin finalidad',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_records', [
            'title' => 'Recorrido sin finalidad',
            'purpose' => null,
        ]);
    }

    public function test_edit_purpose(): void
    {
        $record = $this->createRecord(['purpose' => 'Finalidad original']);
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => $record->title,
                'purpose' => 'Finalidad editada',
            ])
            ->assertRedirect();

        $this->assertEquals('Finalidad editada', $record->fresh()->purpose);
    }

    public function test_import_copies_purpose(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $this->createRecord([
            'year_id' => $source->id,
            'title' => 'Recorrido con finalidad histórica',
            'purpose' => 'Reparto turno mañana',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('logistics.import.store', ['team' => 'logistica']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $imported = LogisticsRecord::where('year_id', $target->id)->firstOrFail();
        $this->assertEquals('Reparto turno mañana', $imported->purpose);
    }

    public function test_category_is_required(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'title' => 'Sin categoría',
                'file' => $this->fakeFile(),
            ])
            ->assertSessionHasErrors('logistics_category_id');
    }

    public function test_title_is_required(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'file' => $this->fakeFile(),
            ])
            ->assertSessionHasErrors('title');
    }

    public function test_file_is_required(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('logistics.store', ['team' => 'logistica']), [
                'logistics_category_id' => $category->id,
                'title' => 'Sin archivo',
            ])
            ->assertSessionHasErrors('file');
    }

    // ─── Categorías nuevas ────────────────────────────────────────────────────

    public function test_member_can_create_category(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('logistics.categories.store', ['team' => 'logistica']), ['name' => 'Combustible'])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_categories', ['name' => 'Combustible']);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $this->makeCategory('Recorridos');
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('logistics.categories.store', ['team' => 'logistica']), ['name' => 'recorridos'])
            ->assertSessionHasErrors('name');
    }

    // ─── Editar ───────────────────────────────────────────────────────────────

    public function test_edit_record(): void
    {
        $record = $this->createRecord(['title' => 'Original']);
        $newCategory = $this->makeCategory('Mapas');
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $newCategory->id,
                'title' => 'Editado',
                'description' => 'Nueva descripción',
                'notes' => 'Nueva observación',
                'record_date' => '2026-07-08',
            ])
            ->assertRedirect();

        $record->refresh();
        $this->assertEquals('Editado', $record->title);
        $this->assertEquals($newCategory->id, $record->logistics_category_id);
        $this->assertEquals('Nueva descripción', $record->description);
        $this->assertEquals('Nueva observación', $record->notes);
        $this->assertEquals('2026-07-08', $record->record_date->toDateString());
    }

    public function test_editing_record_does_not_touch_file(): void
    {
        $record = $this->createRecord();
        $originalFileName = $record->file_name;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => 'Otro título',
            ])
            ->assertRedirect();

        $this->assertEquals($originalFileName, $record->fresh()->file_name);
    }

    public function test_other_team_member_cannot_edit(): void
    {
        $record = $this->createRecord();
        $member = $this->makeMember('publicidad');

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => 'Hackeado',
            ])
            ->assertForbidden();
    }

    // ─── Reemplazar archivo ───────────────────────────────────────────────────

    public function test_replacing_file_updates_file_fields_and_keeps_metadata(): void
    {
        $record = $this->createRecord([
            'title' => 'Recorrido original',
            'description' => 'Descripción original',
            'notes' => 'Observación original',
            'record_date' => '2026-07-01',
        ]);
        $oldPath = $record->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => $record->title,
                'description' => $record->description,
                'notes' => $record->notes,
                'record_date' => '2026-07-01',
                'file' => $this->fakeFile('recorrido-corregido.pdf', 300),
            ])
            ->assertRedirect();

        $record->refresh();

        // Archivo actualizado.
        $this->assertNotEquals($oldPath, $record->file_path);
        $this->assertEquals('recorrido-corregido.pdf', $record->file_name);
        $this->assertEquals(307200, $record->file_size);

        // Resto de los metadatos, intacto.
        $this->assertEquals('Recorrido original', $record->title);
        $this->assertEquals('Descripción original', $record->description);
        $this->assertEquals('Observación original', $record->notes);
        $this->assertEquals('2026-07-01', $record->record_date->toDateString());

        Storage::disk('local')->assertExists($record->file_path);
    }

    public function test_replacing_file_deletes_old_file_when_unreferenced(): void
    {
        $record = $this->createRecord();
        $oldPath = $record->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => $record->title,
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_replacing_file_keeps_old_file_when_still_referenced(): void
    {
        // Simula el resultado de una importación: otro registro (de otra
        // edición) todavía apunta al archivo que este va a reemplazar.
        $record = $this->createRecord();
        $oldPath = $record->file_path;
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $shared = $this->createRecord(['year_id' => $other->id, 'file_path' => $oldPath]);

        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => $record->title,
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        Storage::disk('local')->assertExists($oldPath);
        $this->assertEquals($oldPath, $shared->fresh()->file_path);
    }

    public function test_editing_without_file_field_keeps_current_file(): void
    {
        $record = $this->createRecord();
        $originalPath = $record->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('logistics.update', ['team' => 'logistica', 'record' => $record->id]), [
                'logistics_category_id' => $record->logistics_category_id,
                'title' => 'Nuevo título, mismo archivo',
            ])
            ->assertRedirect();

        $this->assertEquals($originalPath, $record->fresh()->file_path);
        Storage::disk('local')->assertExists($originalPath);
    }

    // ─── Eliminar ─────────────────────────────────────────────────────────────

    public function test_delete_record_removes_record_and_file(): void
    {
        $record = $this->createRecord();
        $path = $record->file_path;
        $member = $this->makeMember();

        Storage::disk('local')->assertExists($path);

        $this->actingAs($member)
            ->delete(route('logistics.destroy', ['team' => 'logistica', 'record' => $record->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('logistics_records', ['id' => $record->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_one_of_two_records_sharing_a_file_keeps_the_file(): void
    {
        // Simula el resultado de una importación: dos registros (de años
        // distintos) apuntando al mismo archivo físico.
        $record = $this->createRecord();
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $shared = $this->createRecord(['year_id' => $other->id, 'file_path' => $record->file_path]);

        $member = $this->makeMember();

        $this->actingAs($member)
            ->delete(route('logistics.destroy', ['team' => 'logistica', 'record' => $record->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('logistics_records', ['id' => $record->id]);
        Storage::disk('local')->assertExists($shared->file_path);

        // Fase 19: $shared pertenece a una edicion no activa (2025) -- un
        // integrante comun del equipo ya no puede tocarla, solo alguien con
        // 'anios.gestionar' (ver Year::isEditableBy). Se usa admin para la
        // segunda baja, que es la que efectivamente prueba la limpieza del
        // archivo compartido.
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->delete(route('logistics.destroy', ['team' => 'logistica', 'record' => $shared->id]))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($shared->file_path);
    }

    // ─── Descarga ─────────────────────────────────────────────────────────────

    public function test_member_can_download(): void
    {
        $record = $this->createRecord();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->get(route('logistics.download', ['team' => 'logistica', 'record' => $record->id]))
            ->assertOk();
    }

    // ─── Ver (apertura inline) ──────────────────────────────────────────────────

    public function test_member_can_view_record_inline(): void
    {
        $record = $this->createRecord();
        $member = $this->makeMember();

        $response = $this->actingAs($member)
            ->get(route('logistics.view', ['team' => 'logistica', 'record' => $record->id]));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_other_team_member_cannot_view_inline(): void
    {
        $record = $this->createRecord();
        $member = $this->makeMember('publicidad');

        $this->actingAs($member)
            ->get(route('logistics.view', ['team' => 'logistica', 'record' => $record->id]))
            ->assertForbidden();
    }

    public function test_guest_cannot_view_record_inline(): void
    {
        $record = $this->createRecord();

        $this->get(route('logistics.view', ['team' => 'logistica', 'record' => $record->id]))
            ->assertRedirect('/login');
    }

    // ─── Importación ──────────────────────────────────────────────────────────

    public function test_import_copies_record_reusing_same_file(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $record = $this->createRecord([
            'year_id' => $source->id,
            'title' => 'Recorrido histórico',
            'description' => 'Descripción reutilizable',
            'notes' => 'Observación de 2022',
            'record_date' => '2022-07-09',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('logistics.import.store', ['team' => 'logistica']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $imported = LogisticsRecord::where('year_id', $target->id)->firstOrFail();
        $this->assertEquals('Recorrido histórico', $imported->title);
        $this->assertEquals('Descripción reutilizable', $imported->description);
        $this->assertEquals($record->logistics_category_id, $imported->logistics_category_id);

        // Mismo archivo físico, no duplicado.
        $this->assertEquals($record->file_path, $imported->file_path);
        $this->assertEquals(
            Storage::disk('local')->path($record->file_path),
            Storage::disk('local')->path($imported->file_path)
        );

        // NO copiado: observaciones y fecha (propias de la edición de origen).
        $this->assertNull($imported->notes);
        $this->assertNull($imported->record_date);
    }

    public function test_import_allows_partial_selection(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $r1 = $this->createRecord(['year_id' => $source->id, 'title' => 'Recorrido A']);
        $r2 = $this->createRecord(['year_id' => $source->id, 'title' => 'Recorrido B']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('logistics.import.store', ['team' => 'logistica']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
                'selected_record_ids' => [$r1->id],
            ])
            ->assertRedirect();

        $targetRecords = LogisticsRecord::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetRecords);
        $this->assertEquals('Recorrido A', $targetRecords->first()->title);
    }

    public function test_import_avoids_duplicating_same_file_in_target(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $record = $this->createRecord(['year_id' => $source->id]);
        // Ya existe en destino, apuntando al mismo archivo (por ejemplo, una
        // importación anterior).
        $this->createRecord(['year_id' => $target->id, 'file_path' => $record->file_path]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('logistics.import.store', ['team' => 'logistica']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $this->assertCount(1, LogisticsRecord::where('year_id', $target->id)->get());
    }

    public function test_import_marks_already_existing_records_in_preview(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $record = $this->createRecord(['year_id' => $source->id]);
        $this->createRecord(['year_id' => $target->id, 'file_path' => $record->file_path]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('logistics.import', ['team' => 'logistica', 'source_year_id' => $source->id, 'target_year_id' => $target->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Logistics/Import')
                ->where('sourceData.records.0.already_exists', true)
            );
    }

    public function test_member_cannot_access_import_page(): void
    {
        $member = $this->makeMember('publicidad');
        $this->actingAs($member)
            ->get(route('logistics.import', ['team' => 'logistica']))
            ->assertForbidden();
    }

    public function test_same_year_import_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('logistics.import.store', ['team' => 'logistica']), [
                'source_year_id' => $this->year->id,
                'target_year_id' => $this->year->id,
            ])
            ->assertStatus(422);
    }
}
