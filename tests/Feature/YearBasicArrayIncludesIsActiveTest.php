<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 21 (correccion de bug): "edicion activa marcada como historica de
 * solo lectura" en Actas/Cronograma/Mi Equipo (y, se detecto en la misma
 * revision, tambien en Compras/Infraestructura/Publicidad/Logistica).
 *
 * Causa real: varios controladores armaban el prop 'year' a mano con
 * $year->only('id', 'year', 'label'), sin 'is_active'. Como
 * HistoricalEditionBanner.vue / useEditableYear.js dependen de
 * `year.is_active` (Fase 19), un campo ausente se lee como `undefined` ->
 * `Boolean(undefined)` es `false` -> la pantalla trata CUALQUIER edicion,
 * incluida la activa, como historica de solo lectura para quien no tiene
 * 'anios.gestionar'.
 *
 * Fix centralizado: Year::toBasicArray() (ver app/Models/Year.php) es ahora
 * la UNICA fuente de verdad para armar ese array minimo, usada por todos los
 * controladores de abajo. Este test no vuelve a testear la logica de
 * Year::isEditableBy/YearPolicy::mutate (ya cubierta por
 * HistoricalEditionReadOnlyTest): solo verifica que el DATO que el frontend
 * necesita para aplicarla efectivamente viaje completo.
 */
class YearBasicArrayIncludesIsActiveTest extends TestCase
{
    use RefreshDatabase;

    protected Year $activeYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->activeYear = Year::where('year', 2026)->firstOrFail();
    }

    protected function makeAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    public static function pageRouteProvider(): array
    {
        return [
            'meetings.index' => ['/meetings'],
            'schedule.index' => ['/schedule'],
            'teams.show (logistica)' => ['/teams/logistica'],
            'purchases.index (compras)' => ['/teams/compras/purchases'],
            'infrastructure.index (infraestructura)' => ['/teams/infraestructura/infrastructure'],
            'publicity.index (publicidad)' => ['/teams/publicidad/publicity'],
            'logistics.index (logistica)' => ['/teams/logistica/logistics'],
        ];
    }

    #[DataProvider('pageRouteProvider')]
    public function test_year_prop_includes_is_active_true_for_the_active_edition(string $path): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get($path);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('year.id', $this->activeYear->id)
            ->where('year.is_active', true)
        );
    }

    public function test_meetings_show_and_edit_year_prop_includes_is_active(): void
    {
        $admin = $this->makeAdmin();

        $meeting = Meeting::create([
            'year_id' => $this->activeYear->id,
            'title' => 'Reunion',
            'date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get("/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('year.is_active', true));

        $this->actingAs($admin)->get("/meetings/{$meeting->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('year.is_active', true));
    }
}
