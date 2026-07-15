<?php

namespace Tests\Feature;

use App\Models\ScheduleActivity;
use App\Models\ScheduleDay;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase13Test extends TestCase
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

    protected function makeJefe(string $team = 'logistica'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole("jefe_{$team}");
        return $u;
    }

    protected function makeMember(string $team = 'logistica'): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole($team);
        return $u;
    }

    protected function createDay(array $overrides = []): ScheduleDay
    {
        $admin = $this->makeAdmin();
        return ScheduleDay::create(array_merge([
            'year_id'    => $this->year->id,
            'date'       => '2026-07-09',
            'title'      => 'Día del Locro',
            'sort_order' => 0,
            'created_by' => $admin->id,
        ], $overrides));
    }

    protected function createActivity(ScheduleDay $day, array $overrides = []): ScheduleActivity
    {
        $admin = $this->makeAdmin();
        return ScheduleActivity::create(array_merge([
            'schedule_day_id' => $day->id,
            'title'           => 'Poner el maíz',
            'status'          => 'pending',
            'sort_order'      => 0,
            'created_by'      => $admin->id,
        ], $overrides));
    }

    // ─── Permisos y acceso ────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_see_schedule(): void
    {
        $this->get(route('schedule.index'))->assertRedirect('/login');
    }

    public function test_member_can_view_schedule(): void
    {
        $member = $this->makeMember();
        $this->actingAs($member)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) => $page->component('Schedule/Index'));
    }

    public function test_member_cannot_create_day(): void
    {
        $member = $this->makeMember();
        $this->actingAs($member)
            ->post(route('schedule.days.store'), ['date' => '2026-07-09', 'year_id' => $this->year->id])
            ->assertForbidden();
    }

    public function test_jefe_can_create_day(): void
    {
        $jefe = $this->makeJefe();
        $this->actingAs($jefe)
            ->post(route('schedule.days.store'), [
                'date'    => '2026-07-09',
                'title'   => 'Día del Locro',
                'year_id' => $this->year->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_days', [
            'year_id' => $this->year->id,
            'date'    => '2026-07-09',
            'title'   => 'Día del Locro',
        ]);
    }

    public function test_admin_can_create_day(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('schedule.days.store'), ['date' => '2026-07-06', 'year_id' => $this->year->id])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_days', ['date' => '2026-07-06']);
    }

    // ─── Aislamiento por edición ──────────────────────────────────────────────

    public function test_days_are_isolated_by_year(): void
    {
        $other = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $this->createDay(['year_id' => $other->id]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('schedule.index'));

        $response->assertInertia(fn ($page) =>
            $page->component('Schedule/Index')
                ->where('days', fn ($days) => count($days) === 0)
        );
    }

    public function test_schedule_index_shows_correct_year_days(): void
    {
        $day  = $this->createDay();
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('schedule.index'));
        $response->assertInertia(fn ($page) =>
            $page->component('Schedule/Index')
                ->has('days', 1)
                ->where('days.0.id', $day->id)
        );
    }

    // ─── Creación de días ─────────────────────────────────────────────────────

    public function test_create_day_with_only_date(): void
    {
        $jefe = $this->makeJefe();
        $this->actingAs($jefe)
            ->post(route('schedule.days.store'), ['date' => '2026-07-06', 'year_id' => $this->year->id])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_days', [
            'date'    => '2026-07-06',
            'title'   => null,
            'year_id' => $this->year->id,
        ]);
    }

    public function test_create_day_requires_date(): void
    {
        $jefe = $this->makeJefe();
        $this->actingAs($jefe)
            ->post(route('schedule.days.store'), ['title' => 'Sin fecha', 'year_id' => $this->year->id])
            ->assertSessionHasErrors('date');
    }

    public function test_delete_day_cascades_activities(): void
    {
        $day = $this->createDay();
        $this->createActivity($day);

        $jefe = $this->makeJefe();
        $this->actingAs($jefe)
            ->delete(route('schedule.days.destroy', $day))
            ->assertRedirect();

        $this->assertDatabaseMissing('schedule_days', ['id' => $day->id]);
        $this->assertDatabaseCount('schedule_activities', 0);
    }

    // ─── Creación de actividades ──────────────────────────────────────────────

    public function test_create_activity_without_time(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), ['title' => 'Poner granos en agua'])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_activities', [
            'title'      => 'Poner granos en agua',
            'start_time' => null,
            'end_time'   => null,
            'status'     => 'pending',
        ]);
    }

    public function test_create_activity_with_single_time(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), [
                'title'      => 'Poner maíz',
                'start_time' => '05:00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_activities', [
            'title'    => 'Poner maíz',
            'end_time' => null,
        ]);
        $act = ScheduleActivity::where('title', 'Poner maíz')->firstOrFail();
        $this->assertNotNull($act->start_time);
    }

    public function test_create_activity_with_time_block(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), [
                'title'      => 'Precocción',
                'start_time' => '09:00',
                'end_time'   => '15:00',
            ])
            ->assertRedirect();

        $act = ScheduleActivity::where('title', 'Precocción')->firstOrFail();
        $this->assertNotNull($act->start_time);
        $this->assertNotNull($act->end_time);
    }

    public function test_end_time_before_start_time_fails(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), [
                'title'      => 'X',
                'start_time' => '15:00',
                'end_time'   => '09:00',
            ])
            ->assertSessionHasErrors('end_time');
    }

    public function test_end_time_without_start_time_fails(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), [
                'title'    => 'X',
                'end_time' => '15:00',
            ])
            ->assertSessionHasErrors('end_time');
    }

    public function test_team_is_optional_on_activity(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), ['title' => 'Sin equipo'])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_activities', ['title' => 'Sin equipo', 'team' => null]);
    }

    public function test_activity_with_team(): void
    {
        $day  = $this->createDay();
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.store', $day), [
                'title' => 'Cortar ingredientes',
                'team'  => 'compras',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_activities', ['title' => 'Cortar ingredientes', 'team' => 'compras']);
    }

    // ─── Cambio de estado ─────────────────────────────────────────────────────

    public function test_mark_done_without_real_date_or_time(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.status', [$day, $activity]), ['status' => 'completed'])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('completed', $activity->status);
        $this->assertNull($activity->actual_date);
        $this->assertNull($activity->actual_time);
    }

    public function test_complete_now_records_real_date_and_time(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.status', [$day, $activity]), [
                'status'       => 'completed',
                'complete_now' => true,
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('completed', $activity->status);
        $this->assertNotNull($activity->actual_date);
        $this->assertNotNull($activity->actual_time);
    }

    public function test_skip_activity(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.status', [$day, $activity]), ['status' => 'skipped'])
            ->assertRedirect();

        $this->assertEquals('skipped', $activity->fresh()->status);
    }

    public function test_reset_activity_clears_real_date_and_time(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day, [
            'status'      => 'completed',
            'actual_date' => '2026-07-09',
            'actual_time' => '05:18:00',
        ]);
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.status', [$day, $activity]), ['status' => 'pending'])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('pending', $activity->status);
        $this->assertNull($activity->actual_date);
        $this->assertNull($activity->actual_time);
    }

    // ─── Edición de ejecución real ──────────────────────────────────────────────

    public function test_manual_actual_date_different_from_today(): void
    {
        $day      = $this->createDay(['date' => '2026-07-09']);
        $activity = $this->createActivity($day, ['status' => 'completed']);
        $jefe     = $this->makeJefe();

        // Today (per the test environment context) may be well after the event;
        // the user must be able to backfill the real date it actually happened.
        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'actual_date' => '2026-07-09',
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('2026-07-09', $activity->actual_date->toDateString());
        $this->assertNull($activity->actual_time);
    }

    public function test_actual_date_without_time(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day, ['status' => 'completed']);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'actual_date' => '2026-07-08',
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('2026-07-08', $activity->actual_date->toDateString());
        $this->assertNull($activity->actual_time);
    }

    public function test_actual_date_and_time_together(): void
    {
        $day      = $this->createDay(['date' => '2026-07-09']);
        $activity = $this->createActivity($day, ['status' => 'completed', 'start_time' => '05:00:00']);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'actual_date' => '2026-07-09',
                'actual_time' => '05:18',
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('2026-07-09', $activity->actual_date->toDateString());
        $this->assertEquals('05:18', $activity->actual_time);
    }

    public function test_actual_time_without_date_is_rejected(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day, ['status' => 'completed']);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'actual_time' => '05:18',
            ])
            ->assertSessionHasErrors('actual_time');

        $this->assertNull($activity->fresh()->actual_time);
    }

    public function test_edit_execution_of_already_completed_activity(): void
    {
        $day      = $this->createDay(['date' => '2026-07-09']);
        $activity = $this->createActivity($day, [
            'status'      => 'completed',
            'actual_date' => '2026-07-09',
            'actual_time' => '05:00:00',
        ]);
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'actual_date' => '2026-07-09',
                'actual_time' => '05:18',
                'notes'       => 'Se demoró porque faltaba agua caliente.',
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertEquals('05:18', $activity->actual_time);
        $this->assertEquals('Se demoró porque faltaba agua caliente.', $activity->notes);
    }

    public function test_observation_can_be_saved(): void
    {
        $day      = $this->createDay();
        $activity = $this->createActivity($day, ['status' => 'completed']);
        $jefe     = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.activities.execution', [$day, $activity]), [
                'notes' => 'Este año se demoró porque faltaba agua caliente.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_activities', [
            'id'    => $activity->id,
            'notes' => 'Este año se demoró porque faltaba agua caliente.',
        ]);
    }

    // ─── Reordenamiento ───────────────────────────────────────────────────────

    public function test_reorder_days(): void
    {
        $day1 = $this->createDay(['date' => '2026-07-06', 'sort_order' => 0]);
        $day2 = $this->createDay(['date' => '2026-07-09', 'sort_order' => 1]);
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.days.reorder'), ['ids' => [$day2->id, $day1->id]])
            ->assertRedirect();

        $this->assertEquals(0, $day2->fresh()->sort_order);
        $this->assertEquals(1, $day1->fresh()->sort_order);
    }

    public function test_reorder_activities(): void
    {
        $day  = $this->createDay();
        $act1 = $this->createActivity($day, ['title' => 'Primero',  'sort_order' => 0]);
        $act2 = $this->createActivity($day, ['title' => 'Segundo', 'sort_order' => 1]);
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.reorder', $day), ['ids' => [$act2->id, $act1->id]])
            ->assertRedirect();

        $this->assertEquals(0, $act2->fresh()->sort_order);
        $this->assertEquals(1, $act1->fresh()->sort_order);
    }

    // ─── Orden cronológico de actividades ──────────────────────────────────────

    public function test_activities_without_time_appear_first(): void
    {
        $day = $this->createDay();
        $this->createActivity($day, ['title' => 'Poner el maíz', 'start_time' => '05:00:00', 'sort_order' => 0]);
        $this->createActivity($day, ['title' => 'Lavar cubos y ollas', 'sort_order' => 1]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Index')
                ->where('days.0.activities.0.title', 'Lavar cubos y ollas')
                ->where('days.0.activities.1.title', 'Poner el maíz')
            );
    }

    public function test_activities_with_time_ordered_ascending(): void
    {
        $day = $this->createDay();
        $this->createActivity($day, ['title' => 'Cortar cebolla', 'start_time' => '19:00:00', 'sort_order' => 0]);
        $this->createActivity($day, ['title' => 'Cebolla de verdeo', 'start_time' => '15:00:00', 'sort_order' => 1]);
        $this->createActivity($day, ['title' => 'Agregar chorizo', 'start_time' => '06:00:00', 'sort_order' => 2]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Index')
                ->where('days.0.activities.0.title', 'Agregar chorizo')
                ->where('days.0.activities.1.title', 'Cebolla de verdeo')
                ->where('days.0.activities.2.title', 'Cortar cebolla')
            );
    }

    public function test_same_start_time_uses_sort_order_as_tiebreak(): void
    {
        $day = $this->createDay();
        $this->createActivity($day, ['title' => 'Segundo agregado', 'start_time' => '05:00:00', 'sort_order' => 5]);
        $this->createActivity($day, ['title' => 'Primero agregado', 'start_time' => '05:00:00', 'sort_order' => 1]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Index')
                ->where('days.0.activities.0.title', 'Primero agregado')
                ->where('days.0.activities.1.title', 'Segundo agregado')
            );
    }

    public function test_untimed_activities_respect_manual_order(): void
    {
        $day  = $this->createDay();
        $act1 = $this->createActivity($day, ['title' => 'Lavar cubos y ollas', 'sort_order' => 0]);
        $act2 = $this->createActivity($day, ['title' => 'Dejar carne en heladera', 'sort_order' => 1]);
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.reorder', $day), ['ids' => [$act2->id, $act1->id]])
            ->assertRedirect();

        $this->actingAs($jefe)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Index')
                ->where('days.0.activities.0.title', 'Dejar carne en heladera')
                ->where('days.0.activities.1.title', 'Lavar cubos y ollas')
            );
    }

    public function test_reorder_ignores_activities_with_a_start_time(): void
    {
        $day   = $this->createDay();
        $timed = $this->createActivity($day, ['title' => 'Poner el maíz', 'start_time' => '05:00:00', 'sort_order' => 0]);
        $jefe  = $this->makeJefe();

        $this->actingAs($jefe)
            ->post(route('schedule.activities.reorder', $day), ['ids' => [$timed->id]])
            ->assertRedirect();

        // A timed activity's position is automatic; reorder must not touch it.
        $this->assertEquals(0, $timed->fresh()->sort_order);
    }

    public function test_import_preserves_chronological_order(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        $srcDay = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        ScheduleActivity::create([
            'schedule_day_id' => $srcDay->id, 'title' => 'Poner maíz',
            'start_time' => '05:00:00', 'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        ScheduleActivity::create([
            'schedule_day_id' => $srcDay->id, 'title' => 'Lavar cubos y ollas',
            'sort_order' => 1, 'created_by' => $admin->id,
        ]);
        ScheduleActivity::create([
            'schedule_day_id' => $srcDay->id, 'title' => 'Poner porotos',
            'start_time' => '05:30:00', 'sort_order' => 2, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ]);

        $this->actingAs($admin)
            ->get(route('schedule.index', ['year_id' => $target->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Index')
                ->where('days.0.activities.0.title', 'Lavar cubos y ollas')
                ->where('days.0.activities.1.title', 'Poner maíz')
                ->where('days.0.activities.2.title', 'Poner porotos')
            );
    }

    // ─── Notas del cronograma ─────────────────────────────────────────────────

    public function test_update_schedule_notes(): void
    {
        $jefe = $this->makeJefe();

        $this->actingAs($jefe)
            ->put(route('schedule.notes.update'), [
                'notes'   => 'Pesar porciones entre 700 y 800 g.',
                'year_id' => $this->year->id,
            ])
            ->assertRedirect();

        $this->assertEquals('Pesar porciones entre 700 y 800 g.', $this->year->fresh()->schedule_notes);
    }

    public function test_member_cannot_update_notes(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)
            ->put(route('schedule.notes.update'), ['notes' => 'X', 'year_id' => $this->year->id])
            ->assertForbidden();
    }

    public function test_index_passes_schedule_notes(): void
    {
        $this->year->update(['schedule_notes' => 'Nota de prueba']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('schedule.index'))
            ->assertInertia(fn ($page) =>
                $page->component('Schedule/Index')
                    ->where('scheduleNotes', 'Nota de prueba')
            );
    }

    // ─── Importación ──────────────────────────────────────────────────────────

    public function test_import_copies_structure_without_operational_data(): void
    {
        $source  = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target  = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $admin   = $this->makeAdmin();
        $srcDay  = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'title' => 'Día del Locro', 'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        ScheduleActivity::create([
            'schedule_day_id' => $srcDay->id,
            'title'           => 'Poner maíz',
            'start_time'      => '05:00:00',
            'status'          => 'completed',
            'actual_date'     => now()->toDateString(),
            'actual_time'     => '05:18:00',
            'notes'           => 'Observación del año pasado',
            'sort_order'      => 0,
            'created_by'      => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ])
            ->assertRedirect();

        // Estructura copiada
        $newDay = ScheduleDay::where('year_id', $target->id)->first();
        $this->assertNotNull($newDay);
        $this->assertEquals('Día del Locro', $newDay->title);

        $newAct = $newDay->activities()->first();
        $this->assertNotNull($newAct);
        $this->assertEquals('Poner maíz', $newAct->title);

        // Datos operativos NO copiados
        $this->assertEquals('pending', $newAct->status);
        $this->assertNull($newAct->actual_date);
        $this->assertNull($newAct->actual_time);
        $this->assertNull($newAct->notes);
    }

    public function test_import_adapts_dates_to_target_year(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-08',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ]);

        $newDay = ScheduleDay::where('year_id', $target->id)->first();
        $this->assertNotNull($newDay);
        $this->assertEquals('2023-07-08', $newDay->date->toDateString());
    }

    public function test_import_handles_feb29_in_non_leap_year(): void
    {
        // 2024 is a leap year, 2025 is not
        $source = Year::create(['year' => 2024, 'label' => 'Locro 2024', 'is_active' => false]);
        $target = Year::create(['year' => 2025, 'label' => 'Locro 2025', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2024-02-29',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ]);

        $newDay = ScheduleDay::where('year_id', $target->id)->first();
        $this->assertNotNull($newDay);
        // Feb 29 doesn't exist in 2025 → adapted to Feb 28
        $this->assertEquals('2025-02-28', $newDay->date->toDateString());
    }

    public function test_import_replaces_existing_target_data(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        // Existing target data
        ScheduleDay::create([
            'year_id' => $target->id, 'date' => '2023-07-01',
            'title' => 'Viejo', 'sort_order' => 0, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $source->id,
                'target_year_id' => $target->id,
            ]);

        $days = ScheduleDay::where('year_id', $target->id)->get();
        $this->assertCount(1, $days);
        $this->assertEquals('2023-07-09', $days->first()->date->toDateString());
    }

    public function test_import_create_returns_source_days_for_selection(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        $day = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'title' => 'Día del Locro', 'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        ScheduleActivity::create([
            'schedule_day_id' => $day->id, 'title' => 'Poner maíz',
            'start_time' => '05:00:00', 'status' => 'pending',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('schedule.import', ['source_year_id' => $source->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Import')
                ->has('sourceData.source_days', 1)
                ->where('sourceData.source_days.0.activities.0.title', 'Poner maíz')
            );
    }

    public function test_import_only_selected_days(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        $dayA = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-08',
            'title' => 'Día previo', 'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        $dayB = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'title' => 'Día del Locro', 'sort_order' => 1, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id'   => $source->id,
                'target_year_id'   => $target->id,
                'selected_day_ids' => [$dayB->id],
            ])
            ->assertRedirect();

        $days = ScheduleDay::where('year_id', $target->id)->get();
        $this->assertCount(1, $days);
        $this->assertEquals('Día del Locro', $days->first()->title);
    }

    public function test_import_excludes_specific_activities(): void
    {
        $source = Year::create(['year' => 2022, 'label' => 'Locro 2022', 'is_active' => false]);
        $target = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);
        $admin  = $this->makeAdmin();

        $day = ScheduleDay::create([
            'year_id' => $source->id, 'date' => '2022-07-09',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        $act1 = ScheduleActivity::create([
            'schedule_day_id' => $day->id, 'title' => 'Poner maíz',
            'sort_order' => 0, 'created_by' => $admin->id,
        ]);
        $act2 = ScheduleActivity::create([
            'schedule_day_id' => $day->id, 'title' => 'Cortar verdura',
            'sort_order' => 1, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id'        => $source->id,
                'target_year_id'        => $target->id,
                'selected_day_ids'      => [$day->id],
                'excluded_activity_ids' => [$act1->id],
            ])
            ->assertRedirect();

        $newDay = ScheduleDay::where('year_id', $target->id)->first();
        $this->assertCount(1, $newDay->activities);
        $this->assertEquals('Cortar verdura', $newDay->activities->first()->title);
    }

    public function test_member_cannot_access_import_page(): void
    {
        $member = $this->makeMember();
        $this->actingAs($member)->get(route('schedule.import'))->assertForbidden();
    }

    public function test_same_year_import_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('schedule.import.store'), [
                'source_year_id' => $this->year->id,
                'target_year_id' => $this->year->id,
            ])
            ->assertStatus(422);
    }
}
