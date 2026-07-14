<?php

namespace Tests\Feature;

use App\Models\TeamTask;
use App\Models\TeamTaskItem;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 10: importación de tareas entre ediciones (Memoria Histórica).
 */
class TeamPhase10ImportTest extends TestCase
{
    use RefreshDatabase;

    protected Year $activeYear;
    protected Year $oldYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->activeYear = Year::where('is_active', true)->firstOrFail();
        $this->oldYear    = Year::create([
            'year'      => $this->activeYear->year - 1,
            'label'     => 'Edición anterior',
            'is_active' => false,
        ]);
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

    protected function createTask(Year $year, string $team, User $creator, array $overrides = []): TeamTask
    {
        return TeamTask::create(array_merge([
            'team'       => $team,
            'year_id'    => $year->id,
            'title'      => 'Tarea de prueba',
            'created_by' => $creator->id,
        ], $overrides));
    }

    protected function createItem(TeamTask $task, User $creator, array $overrides = []): TeamTaskItem
    {
        return TeamTaskItem::create(array_merge([
            'team_task_id' => $task->id,
            'title'        => 'Paso de prueba',
            'created_by'   => $creator->id,
        ], $overrides));
    }

    // ---------- ACCESO: página de importación --------------------------------

    public function test_guest_cannot_access_import_page(): void
    {
        $this->get('/teams/import')->assertRedirect('/login');
    }

    public function test_jefe_gets_403_on_import_page(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->get('/teams/import')->assertForbidden();
    }

    public function test_admin_can_access_import_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/teams/import')->assertOk();
    }

    // ---------- ACCESO: POST de importación ----------------------------------

    public function test_guest_cannot_post_import(): void
    {
        $this->post('/teams/import', [])->assertRedirect('/login');
    }

    public function test_jefe_gets_403_on_import_post(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['logistica'],
        ])->assertForbidden();
    }

    // ---------- VALIDACION ---------------------------------------------------

    public function test_import_requires_source_and_target_year(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/teams/import', [
            'teams' => ['logistica'],
        ])->assertSessionHasErrors(['source_year_id', 'target_year_id']);
    }

    public function test_import_requires_at_least_one_team(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => [],
        ])->assertSessionHasErrors(['teams']);
    }

    public function test_import_rejects_invalid_team_name(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['equipo_falso'],
        ])->assertSessionHasErrors(['teams.0']);
    }

    public function test_import_rejects_same_source_and_target(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->activeYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['logistica'],
        ])->assertStatus(422);
    }

    // ---------- IMPORTACION BÁSICA -------------------------------------------

    public function test_import_copies_tasks_and_items(): void
    {
        $admin  = $this->makeAdmin();
        $source = $this->createTask($this->oldYear, 'logistica', $admin, [
            'title'       => 'Reservar transporte',
            'description' => 'Contactar al proveedor',
            'notes'       => 'Nota interna',
            'sort_order'  => 1,
            'is_completed' => true,
        ]);
        $this->createItem($source, $admin, ['title' => 'Llamar al proveedor', 'sort_order' => 1, 'is_completed' => true]);
        $this->createItem($source, $admin, ['title' => 'Confirmar fecha', 'sort_order' => 2]);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['logistica'],
        ]);

        $newTask = TeamTask::where('year_id', $this->activeYear->id)
            ->where('team', 'logistica')
            ->firstOrFail();

        $this->assertEquals('Reservar transporte', $newTask->title);
        $this->assertEquals('Contactar al proveedor', $newTask->description);
        $this->assertEquals('Nota interna', $newTask->notes);
        $this->assertEquals(1, $newTask->sort_order);
        $this->assertFalse((bool) $newTask->is_completed);
        $this->assertNull($newTask->completed_at);
        $this->assertNull($newTask->completed_by);

        $items = $newTask->items()->orderBy('sort_order')->get();
        $this->assertCount(2, $items);
        $this->assertEquals('Llamar al proveedor', $items[0]->title);
        $this->assertFalse((bool) $items[0]->is_completed);
        $this->assertNull($items[0]->completed_at);
        $this->assertEquals('Confirmar fecha', $items[1]->title);
    }

    public function test_import_does_not_copy_dates(): void
    {
        $admin  = $this->makeAdmin();
        $this->createTask($this->oldYear, 'compras', $admin, [
            'optimal_date' => '2024-04-01',
            'due_date'     => '2024-04-15',
        ]);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['compras'],
        ]);

        $newTask = TeamTask::where('year_id', $this->activeYear->id)
            ->where('team', 'compras')
            ->firstOrFail();

        $this->assertNull($newTask->optimal_date);
        $this->assertNull($newTask->due_date);
    }

    public function test_import_sets_created_by_to_current_user(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();
        $this->createTask($this->oldYear, 'publicidad', $other);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['publicidad'],
        ]);

        $newTask = TeamTask::where('year_id', $this->activeYear->id)
            ->where('team', 'publicidad')
            ->firstOrFail();

        $this->assertEquals($admin->id, $newTask->created_by);
    }

    public function test_import_multiple_teams_at_once(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->oldYear, 'logistica', $admin, ['title' => 'T1']);
        $this->createTask($this->oldYear, 'compras', $admin, ['title' => 'T2']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['logistica', 'compras'],
        ]);

        $this->assertDatabaseHas('team_tasks', ['year_id' => $this->activeYear->id, 'team' => 'logistica', 'title' => 'T1']);
        $this->assertDatabaseHas('team_tasks', ['year_id' => $this->activeYear->id, 'team' => 'compras', 'title' => 'T2']);
    }

    public function test_import_empty_source_team_creates_nothing(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['infraestructura'],
        ]);

        $this->assertDatabaseMissing('team_tasks', [
            'year_id' => $this->activeYear->id,
            'team'    => 'infraestructura',
        ]);
    }

    // ---------- RESOLUCIÓN DE CONFLICTOS -------------------------------------

    public function test_conflict_skip_keeps_existing_tasks(): void
    {
        $admin    = $this->makeAdmin();
        $existing = $this->createTask($this->activeYear, 'logistica', $admin, ['title' => 'Tarea existente']);
        $this->createTask($this->oldYear, 'logistica', $admin, ['title' => 'Tarea del origen']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id'      => $this->oldYear->id,
            'target_year_id'      => $this->activeYear->id,
            'teams'               => ['logistica'],
            'conflict_resolutions' => ['logistica' => 'skip'],
        ]);

        // El existente sigue ahí
        $this->assertDatabaseHas('team_tasks', ['id' => $existing->id, 'title' => 'Tarea existente']);
        // El del origen NO se importó
        $this->assertDatabaseMissing('team_tasks', ['year_id' => $this->activeYear->id, 'title' => 'Tarea del origen']);
    }

    public function test_conflict_keep_is_alias_of_skip(): void
    {
        $admin    = $this->makeAdmin();
        $existing = $this->createTask($this->activeYear, 'compras', $admin, ['title' => 'Tarea existente']);
        $this->createTask($this->oldYear, 'compras', $admin, ['title' => 'Tarea del origen']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id'      => $this->oldYear->id,
            'target_year_id'      => $this->activeYear->id,
            'teams'               => ['compras'],
            'conflict_resolutions' => ['compras' => 'keep'],
        ]);

        $this->assertDatabaseHas('team_tasks', ['id' => $existing->id]);
        $this->assertDatabaseMissing('team_tasks', ['year_id' => $this->activeYear->id, 'title' => 'Tarea del origen']);
    }

    public function test_conflict_replace_removes_existing_and_imports(): void
    {
        $admin    = $this->makeAdmin();
        $existing = $this->createTask($this->activeYear, 'logistica', $admin, ['title' => 'Tarea existente']);
        $source   = $this->createTask($this->oldYear, 'logistica', $admin, ['title' => 'Tarea del origen']);
        $this->createItem($source, $admin, ['title' => 'Paso 1']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id'      => $this->oldYear->id,
            'target_year_id'      => $this->activeYear->id,
            'teams'               => ['logistica'],
            'conflict_resolutions' => ['logistica' => 'replace'],
        ]);

        // El existente fue eliminado
        $this->assertDatabaseMissing('team_tasks', ['id' => $existing->id]);
        // El del origen fue importado
        $this->assertDatabaseHas('team_tasks', ['year_id' => $this->activeYear->id, 'title' => 'Tarea del origen']);
        // Y su subtarea también
        $newTask = TeamTask::where('year_id', $this->activeYear->id)->where('title', 'Tarea del origen')->first();
        $this->assertNotNull($newTask);
        $this->assertDatabaseHas('team_task_items', ['team_task_id' => $newTask->id, 'title' => 'Paso 1']);
    }

    public function test_replace_cascade_deletes_items_of_existing_tasks(): void
    {
        $admin    = $this->makeAdmin();
        $existing = $this->createTask($this->activeYear, 'publicidad', $admin);
        $item     = $this->createItem($existing, $admin, ['title' => 'Item del existente']);
        $this->createTask($this->oldYear, 'publicidad', $admin, ['title' => 'Nueva tarea']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id'      => $this->oldYear->id,
            'target_year_id'      => $this->activeYear->id,
            'teams'               => ['publicidad'],
            'conflict_resolutions' => ['publicidad' => 'replace'],
        ]);

        // El ítem del existente también fue eliminado por cascade
        $this->assertDatabaseMissing('team_task_items', ['id' => $item->id]);
    }

    public function test_mixed_conflict_resolution_per_team(): void
    {
        $admin       = $this->makeAdmin();
        $existLogist = $this->createTask($this->activeYear, 'logistica', $admin, ['title' => 'Logística existente']);
        $existCompra = $this->createTask($this->activeYear, 'compras', $admin, ['title' => 'Compras existente']);
        $this->createTask($this->oldYear, 'logistica', $admin, ['title' => 'Logística origen']);
        $this->createTask($this->oldYear, 'compras', $admin, ['title' => 'Compras origen']);

        $this->actingAs($admin)->post('/teams/import', [
            'source_year_id'      => $this->oldYear->id,
            'target_year_id'      => $this->activeYear->id,
            'teams'               => ['logistica', 'compras'],
            'conflict_resolutions' => [
                'logistica' => 'skip',
                'compras'   => 'replace',
            ],
        ]);

        // Logística: conserva el existente, no importa
        $this->assertDatabaseHas('team_tasks', ['id' => $existLogist->id]);
        $this->assertDatabaseMissing('team_tasks', ['year_id' => $this->activeYear->id, 'title' => 'Logística origen']);

        // Compras: reemplaza, importa
        $this->assertDatabaseMissing('team_tasks', ['id' => $existCompra->id]);
        $this->assertDatabaseHas('team_tasks', ['year_id' => $this->activeYear->id, 'title' => 'Compras origen']);
    }

    // ---------- REDIRECT Y FLASH MESSAGE -------------------------------------

    public function test_successful_import_redirects_with_success_message(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->oldYear, 'logistica', $admin);

        $response = $this->actingAs($admin)->post('/teams/import', [
            'source_year_id' => $this->oldYear->id,
            'target_year_id' => $this->activeYear->id,
            'teams'          => ['logistica'],
        ]);

        $response->assertRedirectToRoute('teams.show', 'logistica');
        $response->assertSessionHas('success');
    }

    // ---------- COUNTSBYTEAM (service) ---------------------------------------

    public function test_counts_by_team_includes_zero_teams(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->oldYear, 'logistica', $admin);
        $this->createTask($this->oldYear, 'logistica', $admin);

        $service = app(\App\Services\TeamTaskImportService::class);
        $counts  = $service->countsByTeam($this->oldYear->id, TeamTask::TEAMS);

        $this->assertEquals(2, $counts['logistica']['tasks']);
        $this->assertEquals(0, $counts['compras']['tasks']);
        $this->assertEquals(0, $counts['infraestructura']['tasks']);
        $this->assertEquals(0, $counts['publicidad']['tasks']);
    }

    public function test_counts_by_team_includes_items(): void
    {
        $admin  = $this->makeAdmin();
        $task   = $this->createTask($this->oldYear, 'logistica', $admin);
        $this->createItem($task, $admin);
        $this->createItem($task, $admin);

        $service = app(\App\Services\TeamTaskImportService::class);
        $counts  = $service->countsByTeam($this->oldYear->id, ['logistica']);

        $this->assertEquals(1, $counts['logistica']['tasks']);
        $this->assertEquals(2, $counts['logistica']['items']);
    }

    // ---------- CONFLICTING TEAMS (service) ----------------------------------

    public function test_conflicting_teams_returns_only_teams_with_tasks(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->activeYear, 'logistica', $admin);
        $this->createTask($this->activeYear, 'logistica', $admin);

        $service   = app(\App\Services\TeamTaskImportService::class);
        $conflicts = $service->conflictingTeams($this->activeYear->id, TeamTask::TEAMS);

        $this->assertArrayHasKey('logistica', $conflicts);
        $this->assertEquals(2, $conflicts['logistica']);
        $this->assertArrayNotHasKey('compras', $conflicts);
    }

    // ---------- IMPORT PAGE: preview -----------------------------------------

    public function test_import_page_shows_preview_when_source_year_provided(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->oldYear, 'logistica', $admin, ['title' => 'Tarea preview']);

        $response = $this->actingAs($admin)->get(
            '/teams/import?source_year_id=' . $this->oldYear->id . '&target_year_id=' . $this->activeYear->id
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Import')
                ->has('sourceData')
                ->where('sourceData.source_year_id', $this->oldYear->id)
        );
    }

    public function test_import_page_without_source_has_null_source_data(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/teams/import');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Import')
                ->where('sourceData', null)
        );
    }

    // ---------- PRESELECTEDTEAM ----------------------------------------------

    public function test_import_page_passes_valid_team_as_preselected(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/teams/import?team=logistica');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Import')
                ->where('preselectedTeam', 'logistica')
        );
    }

    public function test_import_page_ignores_invalid_team_param(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/teams/import?team=equipo_falso');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Import')
                ->where('preselectedTeam', null)
        );
    }

    public function test_import_page_preserves_team_param_with_source(): void
    {
        $admin = $this->makeAdmin();
        $this->createTask($this->oldYear, 'compras', $admin);

        $response = $this->actingAs($admin)->get(
            '/teams/import?team=compras&source_year_id=' . $this->oldYear->id . '&target_year_id=' . $this->activeYear->id
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Teams/Import')
                ->where('preselectedTeam', 'compras')
                ->has('sourceData')
        );
    }

    // ---------- CANMANAGE / CANIMPORT EN SHOW --------------------------------

    public function test_show_passes_can_import_true_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/teams/logistica')
            ->assertInertia(fn ($page) =>
                $page->component('Teams/Show')
                    ->where('canImport', true)
            );
    }

    public function test_show_passes_can_import_false_for_jefe(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)
            ->get('/teams/logistica')
            ->assertInertia(fn ($page) =>
                $page->component('Teams/Show')
                    ->where('canImport', false)
            );
    }
}
