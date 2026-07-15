<?php

namespace Tests\Feature;

use App\Models\TeamDocument;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 11: documentación por equipo/edición.
 */
class TeamPhase11DocumentTest extends TestCase
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

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    protected function makeJefe(string $team): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole("jefe_{$team}");

        return $u;
    }

    protected function makeMember(string $team): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);

        return $u;
    }

    protected function fakeFile(string $name = 'documento.pdf', int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    protected function createDocument(string $team, User $uploader, array $overrides = []): TeamDocument
    {
        $file = $this->fakeFile();
        $path = $file->store("team-documents/{$this->year->id}/{$team}", 'local');

        return TeamDocument::create(array_merge([
            'team'        => $team,
            'year_id'     => $this->year->id,
            'name'        => 'Documento de prueba',
            'description' => null,
            'file_path'   => $path,
            'file_name'   => 'documento.pdf',
            'file_size'   => 102400,
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $uploader->id,
        ], $overrides));
    }

    // ---------- ACCESO: subir ------------------------------------------------

    public function test_guest_cannot_upload_document(): void
    {
        $this->post('/teams/logistica/documents', [])->assertRedirect('/login');
    }

    public function test_member_can_upload_document(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede subir
        // documentos, no solo el jefe.
        $member = $this->makeMember('logistica');

        $this->actingAs($member)->post('/teams/logistica/documents', [
            'name' => 'Test',
            'file' => $this->fakeFile(),
        ])->assertRedirect();

        $this->assertDatabaseHas('team_documents', ['team' => 'logistica', 'name' => 'Test']);
    }

    public function test_jefe_can_upload_document_for_own_team(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => 'Recorridos',
            'file' => $this->fakeFile('recorridos.pdf'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_documents', [
            'team'    => 'logistica',
            'year_id' => $this->year->id,
            'name'    => 'Recorridos',
        ]);
    }

    public function test_jefe_cannot_upload_document_for_another_team(): void
    {
        $jefe = $this->makeJefe('compras');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => 'Test',
            'file' => $this->fakeFile(),
        ])->assertForbidden();
    }

    public function test_admin_can_upload_document_for_any_team(): void
    {
        $admin = $this->makeAdmin();

        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            $this->actingAs($admin)->post("/teams/{$team}/documents", [
                'name' => "Archivo {$team}",
                'file' => $this->fakeFile("archivo.pdf"),
            ])->assertRedirect();

            $this->assertDatabaseHas('team_documents', ['team' => $team, 'name' => "Archivo {$team}"]);
        }
    }

    // ---------- VALIDACIONES ------------------------------------------------

    public function test_name_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'file' => $this->fakeFile(),
        ])->assertSessionHasErrors('name');
    }

    public function test_file_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => 'Sin archivo',
        ])->assertSessionHasErrors('file');
    }

    public function test_name_max_255_chars(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => str_repeat('a', 256),
            'file' => $this->fakeFile(),
        ])->assertSessionHasErrors('name');
    }

    // ---------- ARCHIVO EN DISCO -------------------------------------------

    public function test_upload_stores_file_on_disk(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => 'Recorridos',
            'file' => $this->fakeFile('recorridos.pdf'),
        ]);

        $doc = TeamDocument::where('name', 'Recorridos')->firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_upload_records_original_filename_and_size(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/documents', [
            'name' => 'Mi documento',
            'file' => UploadedFile::fake()->create('presupuesto.xlsx', 500, 'application/vnd.ms-excel'),
        ]);

        $doc = TeamDocument::where('name', 'Mi documento')->firstOrFail();
        $this->assertEquals('presupuesto.xlsx', $doc->file_name);
        $this->assertEquals(500 * 1024, $doc->file_size);
    }

    public function test_upload_assigns_year_from_request(): void
    {
        $admin   = $this->makeAdmin();
        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $this->actingAs($admin)->post('/teams/logistica/documents', [
            'name'    => 'Archivo año viejo',
            'file'    => $this->fakeFile(),
            'year_id' => $oldYear->id,
        ]);

        $this->assertDatabaseHas('team_documents', [
            'name'    => 'Archivo año viejo',
            'year_id' => $oldYear->id,
        ]);
    }

    public function test_file_path_is_not_exposed_in_show_props(): void
    {
        $admin = $this->makeAdmin();
        $this->createDocument('logistica', $admin);

        $response = $this->actingAs($admin)->get('/teams/logistica');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Show')
                ->has('documents', 1)
                ->missing('documents.0.file_path')
        );
    }

    // ---------- DESCARGA ---------------------------------------------------

    public function test_guest_cannot_download(): void
    {
        $admin = $this->makeAdmin();
        $doc   = $this->createDocument('logistica', $admin);

        $this->get("/teams/logistica/documents/{$doc->id}/download")->assertRedirect('/login');
    }

    public function test_member_can_download_own_team_document(): void
    {
        $admin  = $this->makeAdmin();
        $member = $this->makeMember('logistica');
        $doc    = $this->createDocument('logistica', $admin);

        $response = $this->actingAs($member)->get("/teams/logistica/documents/{$doc->id}/download");

        $response->assertOk();
    }

    public function test_member_cannot_download_from_another_team(): void
    {
        $admin  = $this->makeAdmin();
        $member = $this->makeMember('compras');
        $doc    = $this->createDocument('logistica', $admin);

        $this->actingAs($member)->get("/teams/logistica/documents/{$doc->id}/download")->assertForbidden();
    }

    public function test_download_404_if_file_missing_from_disk(): void
    {
        $admin = $this->makeAdmin();
        $doc   = TeamDocument::create([
            'team'        => 'logistica',
            'year_id'     => $this->year->id,
            'name'        => 'Inexistente',
            'file_path'   => 'team-documents/2026/logistica/no-existe.pdf',
            'file_name'   => 'no-existe.pdf',
            'file_size'   => 1000,
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get("/teams/logistica/documents/{$doc->id}/download")->assertNotFound();
    }

    public function test_idor_cannot_download_document_via_wrong_team(): void
    {
        $admin   = $this->makeAdmin();
        $docComp = $this->createDocument('compras', $admin);

        // Documento de compras accedido desde la URL de logistica
        $this->actingAs($admin)->get("/teams/logistica/documents/{$docComp->id}/download")->assertNotFound();
    }

    // ---------- EDITAR -----------------------------------------------------

    public function test_jefe_can_edit_document_name_and_description(): void
    {
        $jefe = $this->makeJefe('logistica');
        $doc  = $this->createDocument('logistica', $jefe);

        $this->actingAs($jefe)->put("/teams/logistica/documents/{$doc->id}", [
            'name'        => 'Nombre editado',
            'description' => 'Descripción nueva',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_documents', [
            'id'          => $doc->id,
            'name'        => 'Nombre editado',
            'description' => 'Descripción nueva',
        ]);
    }

    public function test_member_can_edit_document(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede editar
        // documentos, no solo el jefe.
        $admin  = $this->makeAdmin();
        $member = $this->makeMember('logistica');
        $doc    = $this->createDocument('logistica', $admin);

        $this->actingAs($member)->put("/teams/logistica/documents/{$doc->id}", [
            'name' => 'Editado por integrante',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_documents', ['id' => $doc->id, 'name' => 'Editado por integrante']);
    }

    public function test_jefe_cannot_edit_document_from_another_team(): void
    {
        $admin    = $this->makeAdmin();
        $jefe     = $this->makeJefe('compras');
        $docLogis = $this->createDocument('logistica', $admin);

        $this->actingAs($jefe)->put("/teams/logistica/documents/{$docLogis->id}", [
            'name' => 'Intento',
        ])->assertForbidden();
    }

    public function test_edit_name_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');
        $doc  = $this->createDocument('logistica', $jefe);

        $this->actingAs($jefe)->put("/teams/logistica/documents/{$doc->id}", [
            'name' => '',
        ])->assertSessionHasErrors('name');
    }

    // ---------- ELIMINAR ---------------------------------------------------

    public function test_jefe_can_delete_document(): void
    {
        $jefe = $this->makeJefe('logistica');
        $doc  = $this->createDocument('logistica', $jefe);
        $path = $doc->file_path;

        $this->actingAs($jefe)->delete("/teams/logistica/documents/{$doc->id}")->assertRedirect();

        $this->assertDatabaseMissing('team_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_member_can_delete_document(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede eliminar
        // documentos, no solo el jefe.
        $admin  = $this->makeAdmin();
        $member = $this->makeMember('logistica');
        $doc    = $this->createDocument('logistica', $admin);

        $this->actingAs($member)->delete("/teams/logistica/documents/{$doc->id}")->assertRedirect();

        $this->assertDatabaseMissing('team_documents', ['id' => $doc->id]);
    }

    public function test_jefe_cannot_delete_document_from_another_team(): void
    {
        $admin    = $this->makeAdmin();
        $jefe     = $this->makeJefe('compras');
        $docLogis = $this->createDocument('logistica', $admin);

        $this->actingAs($jefe)->delete("/teams/logistica/documents/{$docLogis->id}")->assertForbidden();
    }

    public function test_delete_idor_via_wrong_team_url(): void
    {
        $admin   = $this->makeAdmin();
        $docComp = $this->createDocument('compras', $admin);

        // Documento de compras accedido desde URL de logistica
        $this->actingAs($admin)->delete("/teams/logistica/documents/{$docComp->id}")->assertNotFound();
    }

    // ---------- DOCUMENTOS EN SHOW PAGE ------------------------------------

    public function test_show_page_includes_documents_prop(): void
    {
        $admin = $this->makeAdmin();
        $this->createDocument('logistica', $admin, ['name' => 'Doc A']);
        $this->createDocument('logistica', $admin, ['name' => 'Doc B']);

        $this->actingAs($admin)->get('/teams/logistica')
            ->assertInertia(fn ($page) =>
                $page->component('Teams/Show')
                    ->has('documents', 2)
            );
    }

    public function test_documents_are_scoped_to_year(): void
    {
        $admin   = $this->makeAdmin();
        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        // Documento para el año activo
        $this->createDocument('logistica', $admin, ['name' => 'Doc activo']);

        // Documento para año viejo (directamente en BD)
        TeamDocument::create([
            'team'        => 'logistica',
            'year_id'     => $oldYear->id,
            'name'        => 'Doc viejo',
            'file_path'   => 'team-documents/2023/logistica/viejo.pdf',
            'file_name'   => 'viejo.pdf',
            'file_size'   => 1000,
            'uploaded_by' => $admin->id,
        ]);

        // La página del año activo solo ve el documento activo
        $this->actingAs($admin)->get('/teams/logistica')
            ->assertInertia(fn ($page) =>
                $page->component('Teams/Show')
                    ->has('documents', 1)
                    ->where('documents.0.name', 'Doc activo')
            );
    }

    public function test_document_includes_uploader_name(): void
    {
        $admin = $this->makeAdmin();
        $this->createDocument('logistica', $admin);

        $this->actingAs($admin)->get('/teams/logistica')
            ->assertInertia(fn ($page) =>
                $page->component('Teams/Show')
                    ->has('documents.0.uploader')
            );
    }
}
