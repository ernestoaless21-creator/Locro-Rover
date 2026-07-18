<?php

namespace Tests\Feature;

use App\Models\PublicityCategory;
use App\Models\PublicityMaterial;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 16: publicidad histórica (archivo de material publicitario por edición).
 */
class PublicityPhase16Test extends TestCase
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

    protected function makeJefePublicidad(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('jefe_publicidad');

        return $u;
    }

    protected function makeMember(string $team = 'publicidad'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);

        return $u;
    }

    protected function fakeFile(string $name = 'flyer.jpg', int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    protected function makeCategory(string $name = 'Flyers'): PublicityCategory
    {
        return PublicityCategory::firstOrCreate(['name' => $name]);
    }

    protected function createMaterial(array $overrides = []): PublicityMaterial
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();
        $file = $this->fakeFile();
        $path = $file->store("publicity-materials/{$this->year->id}", 'local');

        return PublicityMaterial::create(array_merge([
            'year_id' => $this->year->id,
            'publicity_category_id' => $category->id,
            'title' => 'Flyer principal',
            'file_path' => $path,
            'file_name' => 'flyer.jpg',
            'file_size' => 204800,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $admin->id,
        ], $overrides));
    }

    // ─── Categorías iniciales ───────────────────────────────────────────────────

    public function test_migration_seeds_initial_categories(): void
    {
        foreach (['Flyers', 'Publicaciones', 'Historias', 'Reels', 'Folletos'] as $name) {
            $this->assertDatabaseHas('publicity_categories', ['name' => $name]);
        }
    }

    // ─── Permisos y acceso ────────────────────────────────────────────────────

    public function test_guest_cannot_view_publicity(): void
    {
        $this->get(route('publicity.index', ['team' => 'publicidad']))->assertRedirect('/login');
    }

    public function test_other_team_member_cannot_view_publicity(): void
    {
        // Mismo esquema que Documentación: acceso acotado al propio equipo.
        $member = $this->makeMember('compras');
        $this->actingAs($member)
            ->get(route('publicity.index', ['team' => 'publicidad']))
            ->assertForbidden();
    }

    public function test_publicidad_member_can_view_and_manage(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo, no solo el jefe.
        $member = $this->makeMember('publicidad');
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->get(route('publicity.index', ['team' => 'publicidad']))
            ->assertInertia(fn ($page) => $page->component('Publicity/Index')->where('canManage', true));

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Flyer del member',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('publicity_materials', ['title' => 'Flyer del member']);
    }

    public function test_jefe_publicidad_can_manage(): void
    {
        $jefe = $this->makeJefePublicidad();
        $category = $this->makeCategory();

        $this->actingAs($jefe)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Flyer del jefe',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('publicity_materials', ['title' => 'Flyer del jefe']);
    }

    public function test_other_team_member_cannot_upload(): void
    {
        $member = $this->makeMember('infraestructura');
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Intento',
                'file' => $this->fakeFile(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('publicity_materials', 0);
    }

    public function test_admin_can_manage_any_team(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();

        $this->actingAs($admin)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Flyer admin',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();
    }

    public function test_only_publicidad_team_slug_is_routable(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('publicity.index', ['team' => 'compras']))
            ->assertNotFound();
    }

    // ─── Aislamiento por edición ──────────────────────────────────────────────

    public function test_materials_are_isolated_by_year(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $this->createMaterial(['year_id' => $other->id]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('publicity.index', ['team' => 'publicidad']))
            ->assertInertia(fn ($page) => $page->component('Publicity/Index')->where('materials', fn ($m) => count($m) === 0));
    }

    public function test_switching_year_shows_that_years_materials(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $thisYearMaterial = $this->createMaterial(['title' => 'De esta edición']);
        $otherYearMaterial = $this->createMaterial(['year_id' => $other->id, 'title' => 'De la otra edición']);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('publicity.index', ['team' => 'publicidad', 'year_id' => $this->year->id]))
            ->assertInertia(fn ($page) => $page->component('Publicity/Index')->where('materials.0.id', $thisYearMaterial->id));

        $this->actingAs($admin)
            ->get(route('publicity.index', ['team' => 'publicidad', 'year_id' => $other->id]))
            ->assertInertia(fn ($page) => $page->component('Publicity/Index')->where('materials.0.id', $otherYearMaterial->id));
    }

    // ─── Crear material ───────────────────────────────────────────────────────

    public function test_create_material_with_minimum_fields(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Flyer mínimo',
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('publicity_materials', [
            'title' => 'Flyer mínimo',
            'publicity_category_id' => $category->id,
            'description' => null,
            'notes' => null,
            'material_date' => null,
        ]);
    }

    public function test_create_material_with_all_fields(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory('Reels');

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Reel del corte',
                'description' => 'Reel mostrando el corte de la carne',
                'notes' => 'Grabado con el celu de Juan',
                'material_date' => '2026-07-05',
                'file' => $this->fakeFile('reel.mp4', 500),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('publicity_materials', [
            'title' => 'Reel del corte',
            'description' => 'Reel mostrando el corte de la carne',
            'notes' => 'Grabado con el celu de Juan',
            'material_date' => '2026-07-05',
        ]);
    }

    public function test_category_is_required(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'title' => 'Sin categoría',
                'file' => $this->fakeFile(),
            ])
            ->assertSessionHasErrors('publicity_category_id');
    }

    public function test_title_is_required(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'file' => $this->fakeFile(),
            ])
            ->assertSessionHasErrors('title');
    }

    public function test_file_is_required(): void
    {
        $member = $this->makeMember();
        $category = $this->makeCategory();

        $this->actingAs($member)
            ->post(route('publicity.store', ['team' => 'publicidad']), [
                'publicity_category_id' => $category->id,
                'title' => 'Sin archivo',
            ])
            ->assertSessionHasErrors('file');
    }

    // ─── Categorías nuevas ────────────────────────────────────────────────────

    public function test_member_can_create_category(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('publicity.categories.store', ['team' => 'publicidad']), ['name' => 'Stories'])
            ->assertRedirect();

        $this->assertDatabaseHas('publicity_categories', ['name' => 'Stories']);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $this->makeCategory('Flyers');
        $member = $this->makeMember();

        $this->actingAs($member)
            ->post(route('publicity.categories.store', ['team' => 'publicidad']), ['name' => 'flyers'])
            ->assertSessionHasErrors('name');
    }

    // ─── Editar ───────────────────────────────────────────────────────────────

    public function test_edit_material(): void
    {
        $material = $this->createMaterial(['title' => 'Original']);
        $newCategory = $this->makeCategory('Historias');
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $newCategory->id,
                'title' => 'Editado',
                'description' => 'Nueva descripción',
                'notes' => 'Nueva observación',
                'material_date' => '2026-07-08',
            ])
            ->assertRedirect();

        $material->refresh();
        $this->assertEquals('Editado', $material->title);
        $this->assertEquals($newCategory->id, $material->publicity_category_id);
        $this->assertEquals('Nueva descripción', $material->description);
        $this->assertEquals('Nueva observación', $material->notes);
        $this->assertEquals('2026-07-08', $material->material_date->toDateString());
    }

    public function test_editing_material_does_not_touch_file(): void
    {
        $material = $this->createMaterial();
        $originalFileName = $material->file_name;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => 'Otro título',
            ])
            ->assertRedirect();

        $this->assertEquals($originalFileName, $material->fresh()->file_name);
    }

    public function test_other_team_member_cannot_edit(): void
    {
        $material = $this->createMaterial();
        $member = $this->makeMember('logistica');

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => 'Hackeado',
            ])
            ->assertForbidden();
    }

    // ─── Reemplazar archivo ───────────────────────────────────────────────────

    public function test_replacing_file_updates_file_fields_and_keeps_metadata(): void
    {
        $material = $this->createMaterial([
            'title' => 'Flyer original',
            'description' => 'Descripción original',
            'notes' => 'Observación original',
            'material_date' => '2026-07-01',
        ]);
        $oldPath = $material->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => $material->title,
                'description' => $material->description,
                'notes' => $material->notes,
                'material_date' => '2026-07-01',
                'file' => $this->fakeFile('flyer-corregido.png', 300),
            ])
            ->assertRedirect();

        $material->refresh();

        // Archivo actualizado.
        $this->assertNotEquals($oldPath, $material->file_path);
        $this->assertEquals('flyer-corregido.png', $material->file_name);
        $this->assertEquals(307200, $material->file_size);

        // Resto de los metadatos, intacto.
        $this->assertEquals('Flyer original', $material->title);
        $this->assertEquals('Descripción original', $material->description);
        $this->assertEquals('Observación original', $material->notes);
        $this->assertEquals('2026-07-01', $material->material_date->toDateString());

        Storage::disk('local')->assertExists($material->file_path);
    }

    public function test_replacing_file_deletes_old_file_when_unreferenced(): void
    {
        $material = $this->createMaterial();
        $oldPath = $material->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => $material->title,
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_replacing_file_keeps_old_file_when_still_referenced(): void
    {
        // Simula el resultado de una importación: otro material (de otra
        // edición) todavía apunta al archivo que este va a reemplazar.
        $material = $this->createMaterial();
        $oldPath = $material->file_path;
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $shared = $this->createMaterial(['year_id' => $other->id, 'file_path' => $oldPath]);

        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => $material->title,
                'file' => $this->fakeFile(),
            ])
            ->assertRedirect();

        Storage::disk('local')->assertExists($oldPath);
        $this->assertEquals($oldPath, $shared->fresh()->file_path);
    }

    public function test_editing_without_file_field_keeps_current_file(): void
    {
        $material = $this->createMaterial();
        $originalPath = $material->file_path;
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('publicity.update', ['team' => 'publicidad', 'material' => $material->id]), [
                'publicity_category_id' => $material->publicity_category_id,
                'title' => 'Nuevo título, mismo archivo',
            ])
            ->assertRedirect();

        $this->assertEquals($originalPath, $material->fresh()->file_path);
        Storage::disk('local')->assertExists($originalPath);
    }

    // ─── Eliminar ─────────────────────────────────────────────────────────────

    public function test_delete_material_removes_record_and_file(): void
    {
        $material = $this->createMaterial();
        $path = $material->file_path;
        $member = $this->makeMember();

        Storage::disk('local')->assertExists($path);

        $this->actingAs($member)
            ->delete(route('publicity.destroy', ['team' => 'publicidad', 'material' => $material->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('publicity_materials', ['id' => $material->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_one_of_two_materials_sharing_a_file_keeps_the_file(): void
    {
        // Simula el resultado de una importación: dos registros (de años
        // distintos) apuntando al mismo archivo físico.
        $material = $this->createMaterial();
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $shared = $this->createMaterial(['year_id' => $other->id, 'file_path' => $material->file_path]);

        $member = $this->makeMember();

        $this->actingAs($member)
            ->delete(route('publicity.destroy', ['team' => 'publicidad', 'material' => $material->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('publicity_materials', ['id' => $material->id]);
        Storage::disk('local')->assertExists($shared->file_path);

        // Fase 19: $shared pertenece a una edicion no activa (2025) -- un
        // integrante comun del equipo ya no puede tocarla, solo alguien con
        // 'anios.gestionar' (ver Year::isEditableBy). Se usa admin para la
        // segunda baja, que es la que efectivamente prueba la limpieza del
        // archivo compartido.
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->delete(route('publicity.destroy', ['team' => 'publicidad', 'material' => $shared->id]))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($shared->file_path);
    }

    // ─── Descarga ─────────────────────────────────────────────────────────────

    public function test_member_can_download(): void
    {
        $material = $this->createMaterial();
        $member = $this->makeMember();

        $this->actingAs($member)
            ->get(route('publicity.download', ['team' => 'publicidad', 'material' => $material->id]))
            ->assertOk();
    }

    // ─── Ver (apertura inline) ──────────────────────────────────────────────────

    public function test_member_can_view_material_inline(): void
    {
        $material = $this->createMaterial();
        $member = $this->makeMember();

        $response = $this->actingAs($member)
            ->get(route('publicity.view', ['team' => 'publicidad', 'material' => $material->id]));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_other_team_member_cannot_view_inline(): void
    {
        $material = $this->createMaterial();
        $member = $this->makeMember('logistica');

        $this->actingAs($member)
            ->get(route('publicity.view', ['team' => 'publicidad', 'material' => $material->id]))
            ->assertForbidden();
    }

    public function test_guest_cannot_view_material_inline(): void
    {
        $material = $this->createMaterial();

        $this->get(route('publicity.view', ['team' => 'publicidad', 'material' => $material->id]))
            ->assertRedirect('/login');
    }

    // ─── Importación ──────────────────────────────────────────────────────────

    public function test_import_copies_record_reusing_same_file(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $material = $this->createMaterial([
            'year_id' => $source->id,
            'title' => 'Flyer histórico',
            'description' => 'Descripción reutilizable',
            'notes' => 'Observación de 2022',
            'material_date' => '2022-07-09',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('publicity.import.store', ['team' => 'publicidad']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $imported = PublicityMaterial::where('year_id', $target->id)->firstOrFail();
        $this->assertEquals('Flyer histórico', $imported->title);
        $this->assertEquals('Descripción reutilizable', $imported->description);
        $this->assertEquals($material->publicity_category_id, $imported->publicity_category_id);

        // Mismo archivo físico, no duplicado.
        $this->assertEquals($material->file_path, $imported->file_path);
        $this->assertEquals(
            Storage::disk('local')->path($material->file_path),
            Storage::disk('local')->path($imported->file_path)
        );

        // NO copiado: observaciones y fecha (propias de la edición de origen).
        $this->assertNull($imported->notes);
        $this->assertNull($imported->material_date);
    }

    public function test_import_allows_partial_selection(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $m1 = $this->createMaterial(['year_id' => $source->id, 'title' => 'Flyer A']);
        $m2 = $this->createMaterial(['year_id' => $source->id, 'title' => 'Flyer B']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('publicity.import.store', ['team' => 'publicidad']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
                'selected_material_ids' => [$m1->id],
            ])
            ->assertRedirect();

        $targetMaterials = PublicityMaterial::where('year_id', $target->id)->get();
        $this->assertCount(1, $targetMaterials);
        $this->assertEquals('Flyer A', $targetMaterials->first()->title);
    }

    public function test_import_avoids_duplicating_same_file_in_target(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $material = $this->createMaterial(['year_id' => $source->id]);
        // Ya existe en destino, apuntando al mismo archivo (por ejemplo, una
        // importación anterior).
        $this->createMaterial(['year_id' => $target->id, 'file_path' => $material->file_path]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('publicity.import.store', ['team' => 'publicidad']), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        $this->assertCount(1, PublicityMaterial::where('year_id', $target->id)->get());
    }

    public function test_import_marks_already_existing_materials_in_preview(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $material = $this->createMaterial(['year_id' => $source->id]);
        $this->createMaterial(['year_id' => $target->id, 'file_path' => $material->file_path]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('publicity.import', ['team' => 'publicidad', 'source_year_id' => $source->id, 'target_year_id' => $target->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Publicity/Import')
                ->where('sourceData.materials.0.already_exists', true)
            );
    }

    public function test_member_cannot_access_import_page(): void
    {
        $member = $this->makeMember('logistica');
        $this->actingAs($member)
            ->get(route('publicity.import', ['team' => 'publicidad']))
            ->assertForbidden();
    }

    public function test_same_year_import_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('publicity.import.store', ['team' => 'publicidad']), [
                'source_year_id' => $this->year->id,
                'target_year_id' => $this->year->id,
            ])
            ->assertStatus(422);
    }
}
