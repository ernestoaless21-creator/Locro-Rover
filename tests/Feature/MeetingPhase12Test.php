<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingDecision;
use App\Models\TeamDocument;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 12: actas y reuniones.
 * Refinamiento: sin "tipo", con secretario y asistencia por checkbox.
 */
class MeetingPhase12Test extends TestCase
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

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    protected function createMeeting(User $creator, array $overrides = []): Meeting
    {
        return Meeting::create(array_merge([
            'year_id'     => $this->year->id,
            'title'       => 'Reunión de prueba',
            'date'        => '2026-07-14',
            'development' => null,
            'created_by'  => $creator->id,
        ], $overrides));
    }

    protected function createDecision(Meeting $meeting, array $overrides = []): MeetingDecision
    {
        return MeetingDecision::create(array_merge([
            'meeting_id' => $meeting->id,
            'text'       => 'Punto de prueba',
            'category'   => 'decision',
            'team'       => null,
            'sort_order' => 1,
        ], $overrides));
    }

    protected function createDocument(User $uploader, string $team = 'logistica'): TeamDocument
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $path = $file->store("team-documents/{$this->year->id}/{$team}", 'local');

        return TeamDocument::create([
            'team'        => $team,
            'year_id'     => $this->year->id,
            'name'        => 'Doc ' . $team,
            'file_path'   => $path,
            'file_name'   => 'doc.pdf',
            'file_size'   => 102400,
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $uploader->id,
        ]);
    }

    // ── Permisos: actas.ver ───────────────────────────────────────────────────

    public function test_guest_cannot_see_meetings_index(): void
    {
        $this->get('/meetings')->assertRedirect('/login');
    }

    public function test_all_operational_roles_can_see_meetings_index(): void
    {
        $roles = [
            'logistica', 'compras', 'infraestructura', 'publicidad',
            'jefe_logistica', 'jefe_compras', 'jefe_infraestructura', 'jefe_publicidad',
        ];

        foreach ($roles as $role) {
            $u = User::factory()->create(['is_active' => true]);
            $u->assignRole($role);
            $this->actingAs($u)->get('/meetings')->assertOk();
        }
    }

    // ── Permisos: actas.gestionar ────────────────────────────────────────────

    public function test_member_cannot_create_meeting(): void
    {
        $member = $this->makeMember('logistica');
        $this->actingAs($member)->post('/meetings', [])->assertForbidden();
    }

    public function test_jefe_can_create_meeting(): void
    {
        $jefe = $this->makeJefe('logistica');

        $response = $this->actingAs($jefe)->post('/meetings', [
            'title' => 'Reunión inicial',
            'date'  => '2026-07-15',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'title'      => 'Reunión inicial',
            'year_id'    => $this->year->id,
            'created_by' => $jefe->id,
        ]);
    }

    public function test_admin_can_create_meeting(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/meetings', [
            'title'       => 'Acta de comité',
            'date'        => '2026-07-20',
            'development' => 'Se trataron temas varios.',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', ['title' => 'Acta de comité']);
    }

    // ── Validaciones ─────────────────────────────────────────────────────────

    public function test_title_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->actingAs($jefe)->post('/meetings', [
            'date' => '2026-07-15',
        ])->assertSessionHasErrors('title');
    }

    public function test_date_is_required(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->actingAs($jefe)->post('/meetings', [
            'title' => 'Test',
        ])->assertSessionHasErrors('date');
    }

    // ── Show page ────────────────────────────────────────────────────────────

    public function test_member_can_view_meeting_show(): void
    {
        $admin   = $this->makeAdmin();
        $member  = $this->makeMember('compras');
        $meeting = $this->createMeeting($admin);

        $this->actingAs($member)->get("/meetings/{$meeting->id}")->assertOk();
    }

    public function test_show_page_renders_correct_inertia_component(): void
    {
        $admin   = $this->makeAdmin();
        $meeting = $this->createMeeting($admin);

        $this->actingAs($admin)->get("/meetings/{$meeting->id}")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Show')
                    ->has('meeting')
                    ->has('decisionCategories')
                    ->has('canManage')
            );
    }

    public function test_show_includes_decisions(): void
    {
        $admin   = $this->makeAdmin();
        $meeting = $this->createMeeting($admin);
        $this->createDecision($meeting, ['text' => 'Punto A']);
        $this->createDecision($meeting, ['text' => 'Punto B', 'sort_order' => 2]);

        $this->actingAs($admin)->get("/meetings/{$meeting->id}")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Show')->has('meeting.decisions', 2)
            );
    }

    // ── Edit / Update ────────────────────────────────────────────────────────

    public function test_jefe_can_update_meeting(): void
    {
        $jefe    = $this->makeJefe('compras');
        $meeting = $this->createMeeting($jefe);

        $this->actingAs($jefe)->put("/meetings/{$meeting->id}", [
            'title' => 'Título editado',
            'date'  => '2026-08-01',
        ])->assertRedirect(route('meetings.show', $meeting));

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'title' => 'Título editado']);
    }

    public function test_member_cannot_update_meeting(): void
    {
        $admin   = $this->makeAdmin();
        $member  = $this->makeMember('logistica');
        $meeting = $this->createMeeting($admin);

        $this->actingAs($member)->put("/meetings/{$meeting->id}", [
            'title' => 'Hack', 'date' => '2026-07-15',
        ])->assertForbidden();
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete_meeting(): void
    {
        $admin   = $this->makeAdmin();
        $meeting = $this->createMeeting($admin);

        $this->actingAs($admin)->delete("/meetings/{$meeting->id}")->assertRedirect(route('meetings.index'));
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_member_cannot_delete_meeting(): void
    {
        $admin   = $this->makeAdmin();
        $member  = $this->makeMember('publicidad');
        $meeting = $this->createMeeting($admin);

        $this->actingAs($member)->delete("/meetings/{$meeting->id}")->assertForbidden();
    }

    // ── Secretario ───────────────────────────────────────────────────────────

    public function test_secretary_is_saved_with_name_snapshot(): void
    {
        $jefe      = $this->makeJefe('logistica');
        $secretary = User::factory()->create(['is_active' => true, 'name' => 'María García']);

        $this->actingAs($jefe)->post('/meetings', [
            'title'        => 'Acta con secretaria',
            'date'         => '2026-07-15',
            'secretary_id' => $secretary->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'title'          => 'Acta con secretaria',
            'secretary_id'   => $secretary->id,
            'secretary_name' => 'María García',
        ]);
    }

    public function test_secretary_name_is_null_when_not_set(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/meetings', [
            'title' => 'Sin secretario',
            'date'  => '2026-07-15',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'title'          => 'Sin secretario',
            'secretary_id'   => null,
            'secretary_name' => null,
        ]);
    }

    public function test_secretary_snapshot_preserved_after_name_change(): void
    {
        $jefe      = $this->makeJefe('logistica');
        $secretary = User::factory()->create(['is_active' => true, 'name' => 'Nombre Original']);

        $this->actingAs($jefe)->post('/meetings', [
            'title'        => 'Acta histórica',
            'date'         => '2026-07-15',
            'secretary_id' => $secretary->id,
        ]);

        // Simular cambio de nombre en la BD directamente
        $secretary->update(['name' => 'Nombre Nuevo']);

        $meeting = Meeting::where('title', 'Acta histórica')->firstOrFail();
        $this->assertEquals('Nombre Original', $meeting->secretary_name);
    }

    // ── Asistencia ───────────────────────────────────────────────────────────

    public function test_attendances_are_saved_with_name_snapshots(): void
    {
        $jefe   = $this->makeJefe('logistica');
        $rover1 = User::factory()->create(['is_active' => true, 'name' => 'Rover Uno']);
        $rover2 = User::factory()->create(['is_active' => true, 'name' => 'Rover Dos']);

        $this->actingAs($jefe)->post('/meetings', [
            'title'       => 'Acta con asistencia',
            'date'        => '2026-07-15',
            'attendances' => [
                ['user_id' => $rover1->id, 'is_present' => true],
                ['user_id' => $rover2->id, 'is_present' => false],
            ],
        ])->assertRedirect();

        $meeting = Meeting::where('title', 'Acta con asistencia')->firstOrFail();

        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id,
            'user_id'    => $rover1->id,
            'user_name'  => 'Rover Uno',
            'is_present' => true,
        ]);
        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id,
            'user_id'    => $rover2->id,
            'user_name'  => 'Rover Dos',
            'is_present' => false,
        ]);
    }

    public function test_show_page_separates_presentes_and_ausentes(): void
    {
        $admin  = $this->makeAdmin();
        $rover1 = User::factory()->create(['is_active' => true, 'name' => 'Ana López']);
        $rover2 = User::factory()->create(['is_active' => true, 'name' => 'Carlos Soto']);
        $meeting = $this->createMeeting($admin);

        MeetingAttendance::create(['meeting_id' => $meeting->id, 'user_id' => $rover1->id, 'user_name' => 'Ana López', 'is_present' => true]);
        MeetingAttendance::create(['meeting_id' => $meeting->id, 'user_id' => $rover2->id, 'user_name' => 'Carlos Soto', 'is_present' => false]);

        $this->actingAs($admin)->get("/meetings/{$meeting->id}")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Show')
                    ->where('meeting.presentes', fn ($v) => collect($v)->contains('Ana López'))
                    ->where('meeting.ausentes', fn ($v) => collect($v)->contains('Carlos Soto'))
            );
    }

    public function test_attendance_name_snapshot_independent_of_user_record(): void
    {
        $admin   = $this->makeAdmin();
        $rover   = User::factory()->create(['is_active' => true, 'name' => 'Rover Temporal']);
        $meeting = $this->createMeeting($admin);

        MeetingAttendance::create([
            'meeting_id' => $meeting->id,
            'user_id'    => $rover->id,
            'user_name'  => 'Rover Temporal',
            'is_present' => true,
        ]);

        // Simular cambio de nombre del usuario en el sistema
        $rover->update(['name' => 'Nombre Diferente']);

        // El snapshot del acta no cambia
        $attendance = MeetingAttendance::where('meeting_id', $meeting->id)->firstOrFail();
        $this->assertEquals('Rover Temporal', $attendance->user_name);
    }

    public function test_otros_asistentes_is_saved(): void
    {
        $jefe = $this->makeJefe('logistica');

        $this->actingAs($jefe)->post('/meetings', [
            'title'            => 'Acta con invitados',
            'date'             => '2026-07-15',
            'otros_asistentes' => 'Invitado externo, Proveedor X',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'title'            => 'Acta con invitados',
            'otros_asistentes' => 'Invitado externo, Proveedor X',
        ]);
    }

    public function test_create_page_passes_active_users(): void
    {
        $jefe  = $this->makeJefe('logistica');
        $rover = User::factory()->create(['is_active' => true]);

        $this->actingAs($jefe)->get('/meetings/create')
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Create')
                    ->has('activeUsers')
            );
    }

    public function test_edit_page_passes_active_users_and_existing_attendances(): void
    {
        $jefe    = $this->makeJefe('logistica');
        $meeting = $this->createMeeting($jefe);

        $this->actingAs($jefe)->get("/meetings/{$meeting->id}/edit")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Edit')
                    ->has('activeUsers')
                    ->has('meeting.attendances')
            );
    }

    // ── Decisiones: store ────────────────────────────────────────────────────

    public function test_jefe_can_add_decision(): void
    {
        $jefe    = $this->makeJefe('infraestructura');
        $meeting = $this->createMeeting($jefe);

        $this->actingAs($jefe)->post("/meetings/{$meeting->id}/decisions", [
            'text'     => 'Comprar carpas adicionales',
            'category' => 'decision',
            'team'     => 'infraestructura',
        ])->assertRedirect();

        $this->assertDatabaseHas('meeting_decisions', [
            'meeting_id' => $meeting->id,
            'text'       => 'Comprar carpas adicionales',
            'category'   => 'decision',
            'team'       => 'infraestructura',
        ]);
    }

    public function test_member_cannot_add_decision(): void
    {
        $admin   = $this->makeAdmin();
        $member  = $this->makeMember('logistica');
        $meeting = $this->createMeeting($admin);

        $this->actingAs($member)->post("/meetings/{$meeting->id}/decisions", [
            'text' => 'Intento', 'category' => 'decision',
        ])->assertForbidden();
    }

    public function test_decision_category_must_be_valid(): void
    {
        $jefe    = $this->makeJefe('logistica');
        $meeting = $this->createMeeting($jefe);

        $this->actingAs($jefe)->post("/meetings/{$meeting->id}/decisions", [
            'text' => 'Algo', 'category' => 'invalida',
        ])->assertSessionHasErrors('category');
    }

    public function test_decision_team_must_be_valid_if_present(): void
    {
        $jefe    = $this->makeJefe('logistica');
        $meeting = $this->createMeeting($jefe);

        $this->actingAs($jefe)->post("/meetings/{$meeting->id}/decisions", [
            'text'     => 'Algo',
            'category' => 'decision',
            'team'     => 'equipo_inexistente',
        ])->assertSessionHasErrors('team');
    }

    // ── Decisiones: update / destroy ─────────────────────────────────────────

    public function test_jefe_can_update_decision(): void
    {
        $jefe     = $this->makeJefe('logistica');
        $meeting  = $this->createMeeting($jefe);
        $decision = $this->createDecision($meeting);

        $this->actingAs($jefe)->put("/meetings/{$meeting->id}/decisions/{$decision->id}", [
            'text'     => 'Texto actualizado',
            'category' => 'pendiente',
        ])->assertRedirect();

        $this->assertDatabaseHas('meeting_decisions', [
            'id'       => $decision->id,
            'text'     => 'Texto actualizado',
            'category' => 'pendiente',
        ]);
    }

    public function test_decision_idor_via_wrong_meeting(): void
    {
        $admin    = $this->makeAdmin();
        $meeting1 = $this->createMeeting($admin);
        $meeting2 = $this->createMeeting($admin, ['title' => 'Otra reunión']);
        $decision = $this->createDecision($meeting2);

        $this->actingAs($admin)->put("/meetings/{$meeting1->id}/decisions/{$decision->id}", [
            'text' => 'Hack', 'category' => 'decision',
        ])->assertNotFound();
    }

    public function test_jefe_can_delete_decision(): void
    {
        $jefe     = $this->makeJefe('compras');
        $meeting  = $this->createMeeting($jefe);
        $decision = $this->createDecision($meeting);

        $this->actingAs($jefe)->delete("/meetings/{$meeting->id}/decisions/{$decision->id}")->assertRedirect();
        $this->assertDatabaseMissing('meeting_decisions', ['id' => $decision->id]);
    }

    // ── Documentos: attach / detach ──────────────────────────────────────────

    public function test_jefe_can_attach_document_to_meeting(): void
    {
        $jefe    = $this->makeJefe('logistica');
        $meeting = $this->createMeeting($jefe);
        $doc     = $this->createDocument($jefe);

        $this->actingAs($jefe)->post("/meetings/{$meeting->id}/documents", [
            'team_document_id' => $doc->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('meeting_team_document', [
            'meeting_id'       => $meeting->id,
            'team_document_id' => $doc->id,
        ]);
    }

    public function test_member_cannot_attach_document(): void
    {
        $admin   = $this->makeAdmin();
        $member  = $this->makeMember('logistica');
        $meeting = $this->createMeeting($admin);
        $doc     = $this->createDocument($admin);

        $this->actingAs($member)->post("/meetings/{$meeting->id}/documents", [
            'team_document_id' => $doc->id,
        ])->assertForbidden();
    }

    public function test_cannot_attach_document_from_different_year(): void
    {
        $admin   = $this->makeAdmin();
        $meeting = $this->createMeeting($admin);
        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $file = UploadedFile::fake()->create('viejo.pdf', 100, 'application/pdf');
        $path = $file->store('team-documents/2023/logistica', 'local');
        $doc  = TeamDocument::create([
            'team' => 'logistica', 'year_id' => $oldYear->id,
            'name' => 'Doc viejo', 'file_path' => $path,
            'file_name' => 'viejo.pdf', 'file_size' => 102400,
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/meetings/{$meeting->id}/documents", [
            'team_document_id' => $doc->id,
        ])->assertStatus(422);
    }

    public function test_jefe_can_detach_document(): void
    {
        $jefe    = $this->makeJefe('logistica');
        $meeting = $this->createMeeting($jefe);
        $doc     = $this->createDocument($jefe);
        $meeting->documents()->attach($doc->id);

        $this->actingAs($jefe)->delete("/meetings/{$meeting->id}/documents/{$doc->id}")->assertRedirect();

        $this->assertDatabaseMissing('meeting_team_document', [
            'meeting_id'       => $meeting->id,
            'team_document_id' => $doc->id,
        ]);
    }

    // ── Scoping por año ──────────────────────────────────────────────────────

    public function test_index_is_scoped_to_active_year_by_default(): void
    {
        $admin   = $this->makeAdmin();
        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $this->createMeeting($admin, ['title' => 'Acta año activo']);
        $this->createMeeting($admin, ['title' => 'Acta año viejo', 'year_id' => $oldYear->id]);

        $this->actingAs($admin)->get('/meetings')
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Index')
                    ->has('meetings', 1)
                    ->where('meetings.0.title', 'Acta año activo')
            );
    }

    public function test_index_can_filter_by_year(): void
    {
        $admin   = $this->makeAdmin();
        $oldYear = Year::create(['year' => 2023, 'label' => 'Locro 2023', 'is_active' => false]);

        $this->createMeeting($admin, ['year_id' => $oldYear->id, 'title' => 'Acta vieja']);

        $this->actingAs($admin)->get("/meetings?year_id={$oldYear->id}")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Index')
                    ->has('meetings', 1)
                    ->where('meetings.0.title', 'Acta vieja')
            );
    }

    // ── Prop canManage ────────────────────────────────────────────────────────

    public function test_member_sees_can_manage_false(): void
    {
        $member = $this->makeMember('compras');
        $this->actingAs($member)->get('/meetings')
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Index')->where('canManage', false)
            );
    }

    public function test_jefe_sees_can_manage_true(): void
    {
        $jefe = $this->makeJefe('publicidad');
        $this->actingAs($jefe)->get('/meetings')
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Index')->where('canManage', true)
            );
    }

    // ── Cascade delete ────────────────────────────────────────────────────────

    public function test_deleting_meeting_cascades_to_decisions_pivot_and_attendances(): void
    {
        $admin     = $this->makeAdmin();
        $rover     = User::factory()->create(['is_active' => true]);
        $meeting   = $this->createMeeting($admin);
        $decision  = $this->createDecision($meeting);
        $doc       = $this->createDocument($admin);
        $meeting->documents()->attach($doc->id);
        MeetingAttendance::create([
            'meeting_id' => $meeting->id, 'user_id' => $rover->id,
            'user_name' => $rover->name, 'is_present' => true,
        ]);

        $this->actingAs($admin)->delete("/meetings/{$meeting->id}");

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
        $this->assertDatabaseMissing('meeting_decisions', ['id' => $decision->id]);
        $this->assertDatabaseMissing('meeting_team_document', ['meeting_id' => $meeting->id]);
        $this->assertDatabaseMissing('meeting_attendances', ['meeting_id' => $meeting->id]);
        // El documento en sí NO se borra
        $this->assertDatabaseHas('team_documents', ['id' => $doc->id]);
    }

    // ── Show page: documentos ─────────────────────────────────────────────────

    public function test_show_includes_associated_documents(): void
    {
        $admin   = $this->makeAdmin();
        $meeting = $this->createMeeting($admin);
        $doc     = $this->createDocument($admin);
        $meeting->documents()->attach($doc->id);

        $this->actingAs($admin)->get("/meetings/{$meeting->id}")
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Show')
                    ->has('meeting.documents', 1)
                    ->where('meeting.documents.0.name', 'Doc logistica')
            );
    }

    // ── Documentos durante creación ──────────────────────────────────────────

    public function test_documents_can_be_associated_during_creation(): void
    {
        $jefe = $this->makeJefe('logistica');
        $doc  = $this->createDocument($jefe);

        $this->actingAs($jefe)->post('/meetings', [
            'title'        => 'Acta con doc',
            'date'         => '2026-07-15',
            'document_ids' => [$doc->id],
        ])->assertRedirect();

        $meeting = Meeting::where('title', 'Acta con doc')->firstOrFail();

        $this->assertDatabaseHas('meeting_team_document', [
            'meeting_id'       => $meeting->id,
            'team_document_id' => $doc->id,
        ]);
    }

    public function test_create_page_passes_available_documents(): void
    {
        $jefe = $this->makeJefe('logistica');
        $this->createDocument($jefe);

        $this->actingAs($jefe)->get('/meetings/create')
            ->assertInertia(fn ($page) =>
                $page->component('Meetings/Create')
                    ->has('availableDocuments')
                    ->has('availableDocuments', 1)
            );
    }
}
