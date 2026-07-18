<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Year;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 19: unico punto de verdad de "esta edicion se puede modificar ahora
 * mismo" (ver Year::isEditableBy). No se testea aca ningun controlador --
 * eso vive en HistoricalEditionReadOnlyTest -- solo la regla en si misma.
 */
class YearIsEditableByTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_active_year_is_editable_by_a_user_without_any_special_permission(): void
    {
        $year = Year::create(['year' => 2030, 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $this->assertTrue($year->isEditableBy($user));
    }

    public function test_historical_year_is_not_editable_without_anios_gestionar(): void
    {
        $year = Year::create(['year' => 2020, 'is_active' => false]);
        $user = User::factory()->create(['is_active' => true]);

        $this->assertFalse($year->isEditableBy($user));
    }

    public function test_historical_year_is_editable_with_anios_gestionar(): void
    {
        $year = Year::create(['year' => 2021, 'is_active' => false]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('jefe_logistica'); // tiene 'anios.gestionar', ver seeder

        $this->assertTrue($year->isEditableBy($user));
    }

    public function test_active_year_is_editable_by_admin_too(): void
    {
        $year = Year::create(['year' => 2031, 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $this->assertTrue($year->isEditableBy($user));
    }
}
