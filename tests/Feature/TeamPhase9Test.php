<?php

namespace Tests\Feature;

use App\Models\TeamTask;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 9: equipos operativos y checklists de tareas por equipo/año.
 */
class TeamPhase9Test extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

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

    protected function createTask(string $team, User $creator, array $overrides = []): TeamTask
    {
        return TeamTask::create(array_merge([
            'team' => $team,
            'year_id' => $this->year->id,
            'title' => 'Tarea de prueba',
            'created_by' => $creator->id,
        ], $overrides));
    }

    // ---------- ACCESO / AUTENTICACION ------------------------------------

    public function test_guest_cannot_access_team_page(): void
    {
        $response = $this->get('/teams/logistica');

        $response->assertRedirect('/login');
    }

    public function test_user_without_tareas_ver_gets_403(): void
    {
        // Crear un rol sin permisos para simular un usuario que paso EnsureUserHasRole
        // (tiene un rol) pero no tiene el permiso tareas.ver
        \Spatie\Permission\Models\Role::create(['name' => 'sin_permisos', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sin_permisos');

        $response = $this->actingAs($user)->get('/teams/logistica');

        $response->assertForbidden();
    }

    public function test_invalid_team_slug_returns_404(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/teams/inexistente');

        $response->assertNotFound();
    }

    // ---------- CONTROL DE ACCESO POR EQUIPO -----------------------------

    public function test_member_can_view_own_team_page(): void
    {
        $member = $this->makeMember('logistica');

        $response = $this->actingAs($member)->get('/teams/logistica');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Teams/Show'));
    }

    public function test_member_cannot_view_other_team_page(): void
    {
        $member = $this->makeMember('logistica');

        $response = $this->actingAs($member)->get('/teams/compras');

        $response->assertForbidden();
    }

    public function test_jefe_can_view_own_team_page(): void
    {
        $jefe = $this->makeJefe('compras');

        $response = $this->actingAs($jefe)->get('/teams/compras');

        $response->assertOk();
    }

    public function test_jefe_cannot_view_other_team_page(): void
    {
        $jefe = $this->makeJefe('compras');

        $response = $this->actingAs($jefe)->get('/teams/infraestructura');

        $response->assertForbidden();
    }

    public function test_admin_can_view_any_team_page(): void
    {
        $admin = $this->makeAdmin();

        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            $this->actingAs($admin)->get("/teams/{$team}")->assertOk();
        }
    }

    // ---------- PROPS DE INDEX -------------------------------------------

    public function test_canManage_is_true_for_jefe(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page->where('canManage', true));
    }

    public function test_canManage_is_true_for_member(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede gestionar
        // sus herramientas, no solo el jefe.
        $member = $this->makeMember('logistica');

        $response = $this->actingAs($member)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page->where('canManage', true));
    }

    public function test_canManage_is_true_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page->where('canManage', true));
    }

    public function test_tasks_ordered_by_sort_order_then_id(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->createTask('logistica', $jefe, ['title' => 'Segunda', 'sort_order' => 2]);
        $this->createTask('logistica', $jefe, ['title' => 'Primera', 'sort_order' => 1]);
        $this->createTask('logistica', $jefe, ['title' => 'Tercera', 'sort_order' => 3]);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->where('tasks.0.title', 'Primera')
            ->where('tasks.1.title', 'Segunda')
            ->where('tasks.2.title', 'Tercera')
        );
    }

    public function test_tasks_are_scoped_to_year(): void
    {
        $jefe = $this->makeJefe('logistica');

        $otherYear = Year::create([
            'year' => 2024,
            'label' => 'Locro 2024',
            'is_active' => false,
            'event_type' => 'locro',
        ]);

        TeamTask::create([
            'team' => 'logistica',
            'year_id' => $otherYear->id,
            'title' => 'Tarea año anterior',
            'created_by' => $jefe->id,
        ]);

        $response = $this->actingAs($jefe)->get("/teams/logistica?year_id={$this->year->id}");

        $response->assertInertia(fn ($page) => $page->where('tasks', []));
    }

    // ---------- CREAR TAREA ----------------------------------------------

    public function test_jefe_can_create_task(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/teams/logistica/tasks', ['title' => 'Nueva tarea']);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_tasks', [
            'team' => 'logistica',
            'year_id' => $this->year->id,
            'title' => 'Nueva tarea',
            'created_by' => $jefe->id,
        ]);
    }

    public function test_member_can_create_task(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede gestionar
        // sus herramientas, no solo el jefe.
        $member = $this->makeMember('logistica');

        $response = $this->actingAs($member)->post('/teams/logistica/tasks', ['title' => 'Nueva tarea']);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_tasks', ['team' => 'logistica', 'title' => 'Nueva tarea']);
    }

    public function test_task_title_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/teams/logistica/tasks', ['title' => '']);

        $response->assertSessionHasErrors('title');
    }

    public function test_new_task_sort_order_is_appended(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->createTask('logistica', $jefe, ['sort_order' => 3]);
        $this->actingAs($jefe)->post('/teams/logistica/tasks', ['title' => 'Ultima']);

        $this->assertDatabaseHas('team_tasks', ['title' => 'Ultima', 'sort_order' => 4]);
    }

    // ---------- ACTUALIZAR TAREA -----------------------------------------

    public function test_jefe_can_update_task(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($jefe)->put("/teams/logistica/tasks/{$task->id}", ['title' => 'Título editado']);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_tasks', ['id' => $task->id, 'title' => 'Título editado']);
    }

    public function test_member_can_update_task(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede editar,
        // no solo el jefe.
        $jefe = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($member)->put("/teams/logistica/tasks/{$task->id}", ['title' => 'Editado por integrante']);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_tasks', ['id' => $task->id, 'title' => 'Editado por integrante']);
    }

    // ---------- TOGGLE ---------------------------------------------------

    public function test_jefe_can_toggle_task_to_complete(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertNotNull($task->completed_at);
        $this->assertEquals($jefe->id, $task->completed_by);
    }

    public function test_toggle_twice_marks_task_uncompleted(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");
        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $task->refresh();
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by);
    }

    public function test_member_can_toggle_task(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede completar
        // tareas, no solo el jefe.
        $jefe = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($member)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $response->assertRedirect();
        $this->assertTrue($task->fresh()->is_completed);
    }

    // ---------- ELIMINAR TAREA -------------------------------------------

    public function test_jefe_can_delete_task(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($jefe)->delete("/teams/logistica/tasks/{$task->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_tasks', ['id' => $task->id]);
    }

    public function test_member_can_delete_task(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede eliminar,
        // no solo el jefe.
        $jefe = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($member)->delete("/teams/logistica/tasks/{$task->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_tasks', ['id' => $task->id]);
    }

    // ---------- ACCESO CRUZADO ENTRE EQUIPOS -----------------------------

    public function test_jefe_cannot_modify_task_from_other_team(): void
    {
        $jefeLogistica = $this->makeJefe('logistica');
        $jefeCompras = $this->makeJefe('compras');

        $task = $this->createTask('compras', $jefeCompras);

        // jefe_logistica intenta modificar una tarea de compras via la URL de su equipo
        $response = $this->actingAs($jefeLogistica)->put("/teams/logistica/tasks/{$task->id}", ['title' => 'Hackeado']);

        $response->assertNotFound();
        $this->assertDatabaseHas('team_tasks', ['id' => $task->id, 'title' => 'Tarea de prueba']);
    }

    public function test_admin_can_create_task_for_any_team(): void
    {
        $admin = $this->makeAdmin();

        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            $this->actingAs($admin)
                ->post("/teams/{$team}/tasks", ['title' => "Tarea {$team}"])
                ->assertRedirect();
        }

        $this->assertDatabaseCount('team_tasks', 4);
    }

    // ---------- USER MODEL -----------------------------------------------

    public function test_teamSlug_returns_correct_team_for_member(): void
    {
        $member = $this->makeMember('compras');

        $this->assertEquals('compras', $member->teamSlug());
    }

    public function test_teamSlug_returns_correct_team_for_jefe(): void
    {
        $jefe = $this->makeJefe('publicidad');

        $this->assertEquals('publicidad', $jefe->teamSlug());
    }

    public function test_teamSlug_returns_null_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->assertNull($admin->teamSlug());
    }

    // ---------- REGRESION: PERMISOS DEL SEEDER ---------------------------
    // Estos tests verifican que el seeder (o la migración de datos) asigna
    // correctamente los permisos de Fase 9. Si alguna vez el seeder se
    // modifica sin ejecutarse contra la BD local, estos tests lo detectan
    // (y el resultado visible en producción sería 403 en todas las rutas
    // /teams/{team}).

    public function test_tareas_ver_permission_exists_in_database(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => 'tareas.ver', 'guard_name' => 'web']);
    }

    public function test_tareas_ver_is_assigned_to_all_team_member_roles(): void
    {
        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            $role = \Spatie\Permission\Models\Role::where('name', $team)->first();
            $this->assertNotNull($role, "El rol {$team} debe existir");
            $this->assertTrue(
                $role->permissions->contains('name', 'tareas.ver'),
                "El rol {$team} debe tener tareas.ver (falta en DB si el seeder no se re-ejecutó)"
            );
        }
    }

    public function test_tareas_ver_is_assigned_to_all_jefe_roles(): void
    {
        foreach (['jefe_logistica', 'jefe_compras', 'jefe_infraestructura', 'jefe_publicidad'] as $roleName) {
            $role = \Spatie\Permission\Models\Role::where('name', $roleName)->first();
            $this->assertTrue(
                $role->permissions->contains('name', 'tareas.ver'),
                "El rol {$roleName} debe tener tareas.ver"
            );
        }
    }

    public function test_tareas_gestionar_is_assigned_to_all_jefe_roles(): void
    {
        foreach (['jefe_logistica', 'jefe_compras', 'jefe_infraestructura', 'jefe_publicidad'] as $roleName) {
            $role = \Spatie\Permission\Models\Role::where('name', $roleName)->first();
            $this->assertTrue(
                $role->permissions->contains('name', 'tareas.gestionar-propio-equipo'),
                "El rol {$roleName} debe tener tareas.gestionar-propio-equipo"
            );
        }
    }

    public function test_member_roles_have_gestionar_permission(): void
    {
        // Fase 15, Parte A: ser jefe ya no es condicion para poder gestionar
        // las tareas del propio equipo. Cualquier integrante lo puede hacer.
        foreach (['logistica', 'compras', 'infraestructura', 'publicidad'] as $team) {
            $role = \Spatie\Permission\Models\Role::where('name', $team)->first();
            $this->assertTrue(
                $role->permissions->contains('name', 'tareas.gestionar-propio-equipo'),
                "El rol {$team} debe tener tareas.gestionar-propio-equipo (Fase 15, Parte A)"
            );
        }
    }

    // ---------- CAMPOS OPCIONALES (FASE 9 AMPLIACION) --------------------

    public function test_jefe_can_create_task_with_optional_fields(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/tasks', [
            'title'        => 'Con detalles',
            'description'  => 'Descripción de prueba',
            'notes'        => 'Nota interna',
            'optimal_date' => '2026-08-01',
            'due_date'     => '2026-08-15',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_tasks', [
            'title'        => 'Con detalles',
            'description'  => 'Descripción de prueba',
            'notes'        => 'Nota interna',
            'optimal_date' => '2026-08-01',
            'due_date'     => '2026-08-15',
        ]);
    }

    public function test_jefe_can_update_optional_fields(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->actingAs($jefe)->put("/teams/logistica/tasks/{$task->id}", [
            'title'        => 'Tarea actualizada',
            'description'  => 'Nueva descripción',
            'notes'        => 'Nueva nota',
            'optimal_date' => '2026-09-01',
            'due_date'     => '2026-09-10',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_tasks', [
            'id'           => $task->id,
            'description'  => 'Nueva descripción',
            'notes'        => 'Nueva nota',
            'optimal_date' => '2026-09-01',
            'due_date'     => '2026-09-10',
        ]);
    }

    public function test_due_date_cannot_be_before_optimal_date(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/teams/logistica/tasks', [
            'title'        => 'Fechas inválidas',
            'optimal_date' => '2026-09-15',
            'due_date'     => '2026-09-10',
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertDatabaseCount('team_tasks', 0);
    }

    public function test_due_date_equal_to_optimal_date_is_valid(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/tasks', [
            'title'        => 'Fechas iguales',
            'optimal_date' => '2026-09-10',
            'due_date'     => '2026-09-10',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_tasks', ['title' => 'Fechas iguales']);
    }

    public function test_optional_fields_are_nullable_by_default(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/teams/logistica/tasks', ['title' => 'Sin detalles'])->assertRedirect();

        $task = \App\Models\TeamTask::first();
        $this->assertNull($task->description);
        $this->assertNull($task->notes);
        $this->assertNull($task->optimal_date);
        $this->assertNull($task->due_date);
    }

    public function test_existing_tasks_without_optional_fields_still_work(): void
    {
        $jefe = $this->makeJefe('logistica');
        // createTask uses TeamTask::create directamente sin campos opcionales
        $task = $this->createTask('logistica', $jefe);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('tasks', 1));
    }

    // ---------- ORDEN: PENDIENTES ANTES DE COMPLETADAS -------------------

    public function test_pending_tasks_appear_before_completed(): void
    {
        $jefe = $this->makeJefe('logistica');

        $pending   = $this->createTask('logistica', $jefe, ['title' => 'Pendiente', 'sort_order' => 2]);
        $completed = $this->createTask('logistica', $jefe, ['title' => 'Completada', 'sort_order' => 1, 'is_completed' => true, 'completed_at' => now(), 'completed_by' => $jefe->id]);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->where('tasks.0.title', 'Pendiente')
            ->where('tasks.1.title', 'Completada')
        );
    }

    // ---------- CAMPO completer EN PROPS ---------------------------------

    public function test_completed_task_includes_completer_name(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->has('tasks.0.completer')
            ->where('tasks.0.completer.id', $jefe->id)
        );
    }

    public function test_pending_task_has_null_completer(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->createTask('logistica', $jefe);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->where('tasks.0.completer', null)
        );
    }

    // ---------- CAMBIO DE AÑO (YEAR SELECTOR) ---------------------------

    public function test_index_uses_year_id_from_query_string(): void
    {
        $jefe = $this->makeJefe('logistica');

        $otherYear = Year::create([
            'year'       => 2025,
            'label'      => 'Locro 2025',
            'is_active'  => false,
            'event_type' => 'locro',
        ]);

        $this->createTask('logistica', $jefe, ['year_id' => $otherYear->id, 'title' => 'Tarea 2025']);

        $response = $this->actingAs($jefe)->get("/teams/logistica?year_id={$otherYear->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Tarea 2025')
        );
    }

    public function test_index_returns_active_year_by_default(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->createTask('logistica', $jefe, ['title' => 'Tarea año activo']);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->where('year.id', $this->year->id)
            ->has('tasks', 1)
        );
    }

    // ---------- VALIDACION EXTRA -----------------------------------------

    public function test_description_can_be_long_text(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $texto = str_repeat('a', 5000);

        $this->actingAs($jefe)->post('/teams/logistica/tasks', [
            'title'       => 'Con descripción larga',
            'description' => $texto,
        ])->assertRedirect();

        $this->assertDatabaseHas('team_tasks', ['description' => $texto]);
    }

    public function test_invalid_date_format_returns_error(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/teams/logistica/tasks', [
            'title'    => 'Fecha rota',
            'due_date' => 'no-es-fecha',
        ]);

        $response->assertSessionHasErrors('due_date');
    }

    // ---------- SUBTAREAS: CREAR -----------------------------------------

    public function test_jefe_can_create_item(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->actingAs($jefe)
            ->post("/teams/logistica/tasks/{$task->id}/items", ['title' => 'Paso 1'])
            ->assertRedirect();

        $this->assertDatabaseHas('team_task_items', [
            'team_task_id' => $task->id,
            'title'        => 'Paso 1',
            'created_by'   => $jefe->id,
        ]);
    }

    public function test_member_can_create_item(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede gestionar
        // subtareas, no solo el jefe.
        $jefe   = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task   = $this->createTask('logistica', $jefe);

        $this->actingAs($member)
            ->post("/teams/logistica/tasks/{$task->id}/items", ['title' => 'Paso'])
            ->assertRedirect();

        $this->assertDatabaseHas('team_task_items', ['team_task_id' => $task->id, 'title' => 'Paso']);
    }

    public function test_other_team_jefe_cannot_create_item(): void
    {
        $jefeLogistica = $this->makeJefe('logistica');
        $jefeCompras   = $this->makeJefe('compras');
        $task          = $this->createTask('logistica', $jefeLogistica);

        $this->actingAs($jefeCompras)
            ->post("/teams/logistica/tasks/{$task->id}/items", ['title' => 'Intento'])
            ->assertForbidden();

        $this->assertDatabaseCount('team_task_items', 0);
    }

    public function test_item_title_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->actingAs($jefe)
            ->post("/teams/logistica/tasks/{$task->id}/items", ['title' => ''])
            ->assertSessionHasErrors('title');
    }

    public function test_new_item_sort_order_is_appended(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso A',
            'sort_order'   => 3,
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)
            ->post("/teams/logistica/tasks/{$task->id}/items", ['title' => 'Paso B']);

        $this->assertDatabaseHas('team_task_items', ['title' => 'Paso B', 'sort_order' => 4]);
    }

    // ---------- SUBTAREAS: EDITAR ----------------------------------------

    public function test_jefe_can_update_item(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso original',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)
            ->put("/teams/logistica/tasks/{$task->id}/items/{$item->id}", ['title' => 'Paso editado'])
            ->assertRedirect();

        $this->assertDatabaseHas('team_task_items', ['id' => $item->id, 'title' => 'Paso editado']);
    }

    public function test_member_can_update_item(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede editar
        // subtareas, no solo el jefe.
        $jefe   = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task   = $this->createTask('logistica', $jefe);
        $item   = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Original',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($member)
            ->put("/teams/logistica/tasks/{$task->id}/items/{$item->id}", ['title' => 'Editado por integrante'])
            ->assertRedirect();

        $this->assertDatabaseHas('team_task_items', ['id' => $item->id, 'title' => 'Editado por integrante']);
    }

    // ---------- SUBTAREAS: TOGGLE ----------------------------------------

    public function test_jefe_can_toggle_item_to_complete(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)
            ->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle");

        $item->refresh();
        $this->assertTrue($item->is_completed);
        $this->assertNotNull($item->completed_at);
        $this->assertEquals($jefe->id, $item->completed_by);
    }

    public function test_toggle_item_twice_marks_uncompleted(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle");
        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle");

        $item->refresh();
        $this->assertFalse($item->is_completed);
        $this->assertNull($item->completed_at);
        $this->assertNull($item->completed_by);
    }

    public function test_member_can_toggle_item(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede completar
        // subtareas, no solo el jefe.
        $jefe   = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task   = $this->createTask('logistica', $jefe);
        $item   = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($member)
            ->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle")
            ->assertRedirect();

        $this->assertTrue($item->fresh()->is_completed);
    }

    // ---------- SUBTAREAS: ELIMINAR --------------------------------------

    public function test_jefe_can_delete_item(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'A eliminar',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)
            ->delete("/teams/logistica/tasks/{$task->id}/items/{$item->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('team_task_items', ['id' => $item->id]);
    }

    public function test_member_can_delete_item(): void
    {
        // Fase 15, Parte A: cualquier integrante del equipo puede eliminar
        // subtareas, no solo el jefe.
        $jefe   = $this->makeJefe('logistica');
        $member = $this->makeMember('logistica');
        $task   = $this->createTask('logistica', $jefe);
        $item   = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'A eliminar',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($member)
            ->delete("/teams/logistica/tasks/{$task->id}/items/{$item->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('team_task_items', ['id' => $item->id]);
    }

    // ---------- SUBTAREAS: ORDEN -----------------------------------------

    public function test_items_are_ordered_by_sort_order_then_id(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        \App\Models\TeamTaskItem::create(['team_task_id' => $task->id, 'title' => 'Segundo', 'sort_order' => 2, 'created_by' => $jefe->id]);
        \App\Models\TeamTaskItem::create(['team_task_id' => $task->id, 'title' => 'Primero',  'sort_order' => 1, 'created_by' => $jefe->id]);
        \App\Models\TeamTaskItem::create(['team_task_id' => $task->id, 'title' => 'Tercero',  'sort_order' => 3, 'created_by' => $jefe->id]);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->where('tasks.0.items.0.title', 'Primero')
            ->where('tasks.0.items.1.title', 'Segundo')
            ->where('tasks.0.items.2.title', 'Tercero')
        );
    }

    // ---------- SUBTAREAS: PROPS -----------------------------------------

    public function test_items_are_returned_in_index_props(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        \App\Models\TeamTaskItem::create(['team_task_id' => $task->id, 'title' => 'Un paso', 'created_by' => $jefe->id]);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->has('tasks.0.items', 1)
            ->where('tasks.0.items.0.title', 'Un paso')
        );
    }

    public function test_task_without_items_has_empty_items_array(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->createTask('logistica', $jefe);

        $response = $this->actingAs($jefe)->get('/teams/logistica');

        $response->assertInertia(fn ($page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.items', [])
        );
    }

    // ---------- SUBTAREAS: IDOR / SEGURIDAD ------------------------------

    public function test_idor_cannot_manipulate_item_via_wrong_task(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $taskA = $this->createTask('logistica', $jefe, ['title' => 'Tarea A']);
        $taskB = $this->createTask('logistica', $jefe, ['title' => 'Tarea B']);
        $item  = \App\Models\TeamTaskItem::create([
            'team_task_id' => $taskB->id,
            'title'        => 'Paso de B',
            'created_by'   => $jefe->id,
        ]);

        // Intenta manipular el item de taskB usando la URL de taskA
        $response = $this->actingAs($jefe)
            ->put("/teams/logistica/tasks/{$taskA->id}/items/{$item->id}", ['title' => 'Hackeado']);

        $response->assertNotFound();
        $this->assertDatabaseHas('team_task_items', ['id' => $item->id, 'title' => 'Paso de B']);
    }

    public function test_idor_cannot_manipulate_item_via_wrong_team(): void
    {
        $jefeLogistica = $this->makeJefe('logistica');
        $jefeCompras   = $this->makeJefe('compras');
        $task          = $this->createTask('logistica', $jefeLogistica);
        $item          = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso protegido',
            'created_by'   => $jefeLogistica->id,
        ]);

        // jefe_compras intenta acceder a un item de logistica via URL de compras
        $taskCompras = $this->createTask('compras', $jefeCompras);
        $response    = $this->actingAs($jefeCompras)
            ->delete("/teams/compras/tasks/{$taskCompras->id}/items/{$item->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('team_task_items', ['id' => $item->id]);
    }

    // ---------- SUBTAREAS: ELIMINAR EN CASCADA ---------------------------

    public function test_deleting_task_also_deletes_its_items(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = \App\Models\TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title'        => 'Paso',
            'created_by'   => $jefe->id,
        ]);

        $this->actingAs($jefe)->delete("/teams/logistica/tasks/{$task->id}");

        $this->assertDatabaseMissing('team_task_items', ['id' => $item->id]);
    }

    // ---------- SINCRONIZACIÓN TAREA PRINCIPAL ↔ SUBTAREAS ---------------

    protected function makeItem(TeamTask $task, User $creator, array $overrides = []): \App\Models\TeamTaskItem
    {
        return \App\Models\TeamTaskItem::create(array_merge([
            'team_task_id' => $task->id,
            'title'        => 'Paso de prueba',
            'created_by'   => $creator->id,
        ], $overrides));
    }

    public function test_completing_task_marks_all_pending_items_completed(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $task  = $this->createTask('logistica', $jefe);
        $itemA = $this->makeItem($task, $jefe);
        $itemB = $this->makeItem($task, $jefe);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $this->assertTrue($itemA->fresh()->is_completed);
        $this->assertTrue($itemB->fresh()->is_completed);
    }

    public function test_completing_task_sets_completed_at_and_completed_by_on_pending_items(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);
        $item = $this->makeItem($task, $jefe);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $item->refresh();
        $this->assertEquals($jefe->id, $item->completed_by);
        $this->assertNotNull($item->completed_at);
    }

    public function test_completing_task_does_not_overwrite_already_completed_item_metadata(): void
    {
        $jefeA = $this->makeJefe('logistica');
        $jefeB = $this->makeJefe('compras'); // usamos otro usuario para distinguir el author

        // jefeA ya completó su ítem antes
        $task  = $this->createTask('logistica', $jefeA);
        $itemAlreadyDone = $this->makeItem($task, $jefeA, [
            'is_completed' => true,
            'completed_at' => now()->subHour(),
            'completed_by' => $jefeA->id,
        ]);
        $itemPending = $this->makeItem($task, $jefeA);

        // jefeA completa la tarea principal (en su equipo)
        $this->actingAs($jefeA)->post("/teams/logistica/tasks/{$task->id}/toggle");

        // El ítem ya completado conserva su completed_by original (jefeA, no el admin que tocó la tarea)
        $this->assertEquals($jefeA->id, $itemAlreadyDone->fresh()->completed_by);
        // El ítem pendiente sí se actualizó
        $this->assertTrue($itemPending->fresh()->is_completed);
    }

    public function test_uncompleting_task_unmarks_all_items(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $task  = $this->createTask('logistica', $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);
        $itemA = $this->makeItem($task, $jefe, ['is_completed' => true, 'completed_at' => now(), 'completed_by' => $jefe->id]);
        $itemB = $this->makeItem($task, $jefe, ['is_completed' => true, 'completed_at' => now(), 'completed_by' => $jefe->id]);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $this->assertFalse($itemA->fresh()->is_completed);
        $this->assertFalse($itemB->fresh()->is_completed);
    }

    public function test_uncompleting_task_clears_item_metadata(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);
        $item = $this->makeItem($task, $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle");

        $item->refresh();
        $this->assertNull($item->completed_at);
        $this->assertNull($item->completed_by);
    }

    public function test_completing_last_pending_item_auto_completes_parent_task(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $task  = $this->createTask('logistica', $jefe);
        $itemA = $this->makeItem($task, $jefe, ['is_completed' => true, 'completed_at' => now(), 'completed_by' => $jefe->id]);
        $itemB = $this->makeItem($task, $jefe); // único pendiente

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$itemB->id}/toggle");

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertEquals($jefe->id, $task->completed_by);
        $this->assertNotNull($task->completed_at);
    }

    public function test_completing_non_last_item_does_not_complete_parent(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $task  = $this->createTask('logistica', $jefe);
        $itemA = $this->makeItem($task, $jefe);
        $itemB = $this->makeItem($task, $jefe); // otro ítem sigue pendiente

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$itemA->id}/toggle");

        $task->refresh();
        $this->assertFalse($task->is_completed);
    }

    public function test_uncompleting_item_marks_parent_task_incomplete(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);
        $item = $this->makeItem($task, $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle");

        $task->refresh();
        $this->assertFalse($task->is_completed);
    }

    public function test_uncompleting_item_clears_parent_task_metadata(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);
        $item = $this->makeItem($task, $jefe, [
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $jefe->id,
        ]);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle");

        $task->refresh();
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by);
    }

    public function test_task_without_items_toggle_is_unchanged(): void
    {
        $jefe = $this->makeJefe('logistica');
        $task = $this->createTask('logistica', $jefe);

        $this->assertDatabaseCount('team_task_items', 0);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle")->assertRedirect();

        $task->refresh();
        $this->assertTrue($task->is_completed);

        $this->actingAs($jefe)->post("/teams/logistica/tasks/{$task->id}/toggle")->assertRedirect();

        $task->refresh();
        $this->assertFalse($task->is_completed);
    }

    public function test_sync_respects_permissions_no_regression(): void
    {
        // Fase 15, Parte A: el member de logistica ahora SI puede gestionar
        // (es integrante del equipo), pero un integrante de OTRO equipo
        // sigue sin poder tocar nada de logistica.
        $jefe        = $this->makeJefe('logistica');
        $otherMember = $this->makeMember('publicidad');
        $task        = $this->createTask('logistica', $jefe);
        $item        = $this->makeItem($task, $jefe);

        $this->actingAs($otherMember)->post("/teams/logistica/tasks/{$task->id}/toggle")->assertForbidden();
        $this->assertFalse($task->fresh()->is_completed);
        $this->assertFalse($item->fresh()->is_completed);

        $this->actingAs($otherMember)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle")->assertForbidden();
        $this->assertFalse($item->fresh()->is_completed);

        // Sincronización real: un integrante DEL PROPIO equipo sí puede, y
        // completar todos los ítems auto-completa la tarea.
        $member = $this->makeMember('logistica');
        $this->actingAs($member)->post("/teams/logistica/tasks/{$task->id}/items/{$item->id}/toggle")->assertRedirect();
        $this->assertTrue($item->fresh()->is_completed);
        $this->assertTrue($task->fresh()->is_completed);
    }
}
