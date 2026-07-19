<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use App\Services\ClientAssignmentService;
use App\Services\Import\ImportFormat;
use App\Services\Import\ImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Fase P2: importacion historica de clientes/pedidos desde Excel.
 */
class HistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    protected Year $year;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->year = Year::where('is_active', true)->firstOrFail();
    }

    // ---------- Helpers --------------------------------------------------

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

    protected function makeLogistica(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('logistica');

        return $u;
    }

    protected const HEADER = [
        'ID orden', 'Rover encargado', 'Nombre', 'Apellido', 'Telefono', 'Direccion',
        'Cod. Postal', 'Delivery', 'QTY', 'Importe', 'Salsas', 'Observaciones 2026',
        'Observaciones 2025', 'Dinero cobrado', 'A cobrar', 'Mercado pago SI/NO',
    ];

    protected const COLUMN_ORDER = [
        'id_orden', 'rover', 'nombre', 'apellido', 'telefono', 'direccion',
        'cod_postal', 'delivery', 'qty', 'importe', 'salsas', 'obs2026',
        'obs2025', 'dinero_cobrado', 'a_cobrar', 'mercado_pago',
    ];

    protected function legacyRow(array $overrides = []): array
    {
        return array_merge([
            'id_orden' => '1001',
            'rover' => '',
            'nombre' => 'Maria',
            'apellido' => 'Gomez',
            'telefono' => '1123456789',
            'direccion' => 'Calle Falsa 123',
            'cod_postal' => '1900',
            'delivery' => 'NO',
            'qty' => 3,
            'importe' => 15000,
            'salsas' => 1,
            'obs2026' => '',
            'obs2025' => '',
            'dinero_cobrado' => 15000,
            'a_cobrar' => 0,
            'mercado_pago' => 'NO',
        ], $overrides);
    }

    /** @param  array<int,array<string,mixed>>  $rowsAssoc */
    protected function legacyXlsx(array $rowsAssoc, string $filename = 'legacy.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADER, null, 'A1');

        $row = 2;
        foreach ($rowsAssoc as $data) {
            $line = array_map(fn ($key) => $data[$key] ?? '', self::COLUMN_ORDER);
            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile($tmpPath, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function analyze(User $user, UploadedFile $file, ?string $format = null): TestResponse
    {
        $payload = ['file' => $file];
        if ($format !== null) {
            $payload['format'] = $format;
        }

        return $this->actingAs($user)->post('/imports/analyze', $payload, ['Accept' => 'application/json']);
    }

    protected function confirm(User $user, string $token, array $roverOverrides = [], ?ImportFormat $format = null): TestResponse
    {
        return $this->actingAs($user)->postJson('/imports/confirm', [
            'token' => $token,
            'year_id' => $this->year->id,
            'format' => ($format ?? ImportFormat::LegacySite)->value,
            'rover_overrides' => $roverOverrides,
        ]);
    }

    // ---------- Acceso -----------------------------------------------------

    public function test_guest_cannot_access_import_page(): void
    {
        $this->get('/imports')->assertRedirect('/login');
    }

    public function test_guest_cannot_analyze(): void
    {
        $this->post('/imports/analyze', ['file' => $this->legacyXlsx([$this->legacyRow()])], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_user_without_permission_gets_403_on_all_endpoints(): void
    {
        $user = $this->makeLogistica();

        $this->actingAs($user)->get('/imports')->assertForbidden();
        $this->analyze($user, $this->legacyXlsx([$this->legacyRow()]))->assertForbidden();
        $this->confirm($user, (string) Str::uuid())->assertForbidden();
        $this->actingAs($user)->deleteJson('/imports/'.Str::uuid())->assertForbidden();
    }

    public function test_admin_can_access_import_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/imports')->assertOk()->assertInertia(
            fn ($page) => $page->component('Imports/Create')->has('years')->has('users'),
        );
    }

    public function test_jefe_logistica_can_access_import_page(): void
    {
        $jefe = $this->makeJefeLogistica();

        $this->actingAs($jefe)->get('/imports')->assertOk();
    }

    // ---------- Analyze / preview -------------------------------------------

    public function test_analyze_detects_legacy_format_and_builds_preview_without_writing_db(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(), $this->legacyRow(['id_orden' => '1002', 'telefono' => '1155501234'])]));

        $response->assertOk();
        $data = $response->json();

        $this->assertTrue(Str::isUuid($data['token']));
        $this->assertEquals('legacy_site', $data['preview']['format']);
        $this->assertTrue($data['preview']['can_import']);
        $this->assertEquals(2, $data['preview']['orders_to_create']);
        $this->assertEquals(2, $data['preview']['new_clients_to_create']);
        $this->assertEquals(0, $data['preview']['existing_clients_matched']);
        $this->assertEquals(6, $data['preview']['total_portions']);
        $this->assertEquals(30000, $data['preview']['total_amount']);

        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_analyze_rejects_unsupported_file(): void
    {
        $admin = $this->makeAdmin();

        $tmp = tempnam(sys_get_temp_dir(), 'garbage').'.xlsx';
        file_put_contents($tmp, str_repeat('no soy un excel valido', 20));
        $file = new UploadedFile($tmp, 'roto.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->analyze($admin, $file)->assertStatus(422);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_analyze_rejects_non_xlsx_extension(): void
    {
        $admin = $this->makeAdmin();

        $file = UploadedFile::fake()->create('planilla.txt', 10, 'text/plain');

        $this->analyze($admin, $file)->assertStatus(422);
    }

    public function test_analyze_rejects_file_with_unrecognized_columns(): void
    {
        $admin = $this->makeAdmin();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(['Columna A', 'Columna B'], null, 'A1');
        $tmpPath = tempnam(sys_get_temp_dir(), 'unknown').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $file = new UploadedFile($tmpPath, 'desconocido.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->analyze($admin, $file)->assertStatus(422);
    }

    public function test_analyze_blocks_import_on_missing_phone(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['telefono' => ''])]));

        $data = $response->json();
        $this->assertFalse($data['preview']['can_import']);
        $this->assertEquals(1, $data['preview']['error_rows']);
        $this->assertEquals(0, $data['preview']['orders_to_create']);
    }

    public function test_analyze_blocks_import_on_missing_name(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['nombre' => '', 'apellido' => ''])]));

        $this->assertFalse($response->json('preview.can_import'));
    }

    public function test_analyze_blocks_import_on_negative_amount(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['importe' => -500])]));

        $this->assertFalse($response->json('preview.can_import'));
    }

    public function test_analyze_blocks_import_on_negative_portions(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['qty' => -1])]));

        $this->assertFalse($response->json('preview.can_import'));
    }

    /**
     * Regla de negocio actualizada (ver diagnostico del Excel real): la
     * pagina vieja registraba TAMBIEN a los clientes contactados que no
     * compraron ese año (QTY/Importe vacios). Esas filas NO son un error:
     * se importan igual, como pedido de 0 porciones (ver mas abajo el bloque
     * "Sin compra").
     */
    public function test_analyze_does_not_block_import_on_missing_qty_and_importe(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow([
            'qty' => '', 'importe' => '', 'salsas' => '', 'dinero_cobrado' => '', 'a_cobrar' => '',
        ])]));

        $data = $response->json('preview');
        $this->assertTrue($data['can_import']);
        $this->assertEquals(0, $data['error_rows']);
        $this->assertEquals(1, $data['orders_to_create']);
        $this->assertEquals(0, $data['total_portions']);
        $this->assertEquals(0, $data['total_amount']);
    }

    public function test_analyze_reports_duplicate_phone_within_file(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([
            $this->legacyRow(['id_orden' => '1001']),
            $this->legacyRow(['id_orden' => '1002']),
        ]));

        $data = $response->json('preview');
        $this->assertTrue($data['can_import']);
        $this->assertEquals(2, $data['duplicate_rows']);
        // Ambas filas comparten telefono: un solo cliente nuevo, no dos.
        $this->assertEquals(1, $data['new_clients_to_create']);
    }

    public function test_analyze_reports_unresolved_rover(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['rover' => 'Nombre Desconocido'])]));

        $this->assertEquals(['Nombre Desconocido'], $response->json('preview.unresolved_rovers'));
    }

    public function test_analyze_matches_rover_by_exact_name(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Juan Perez', 'is_active' => true]);
        $rover->assignRole('logistica');

        $response = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['rover' => 'Juan Perez'])]));

        $this->assertEquals([], $response->json('preview.unresolved_rovers'));
    }

    // ---------- Import exitoso -----------------------------------------------

    public function test_confirm_creates_client_order_items_and_payment(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Juan Perez', 'is_active' => true]);
        $rover->assignRole('logistica');

        $analyzeResponse = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow([
            'rover' => 'Juan Perez',
            'delivery' => 'SI',
            'mercado_pago' => 'SI',
            'obs2025' => 'Cliente antiguo<br>Buena onda',
            'obs2026' => 'Pidió sin sal',
        ])]));
        $token = $analyzeResponse->json('token');

        $confirmResponse = $this->confirm($admin, $token);
        $confirmResponse->assertOk();

        $result = $confirmResponse->json('result');
        $this->assertEquals(1, $result['clients_created']);
        $this->assertEquals(0, $result['clients_reused']);
        $this->assertEquals(1, $result['orders_created']);
        $this->assertEquals(3, $result['portions_imported']);
        $this->assertEquals(15000, $result['total_amount']);
        $this->assertArrayHasKey('elapsed_ms', $result);

        $client = Client::where('phone', '11-2345-6789')->firstOrFail();
        $this->assertEquals('Maria', $client->first_name);
        $this->assertEquals('Gomez', $client->last_name);
        $this->assertNotNull($client->historical_number);

        $order = Order::where('client_id', $client->id)->where('year_id', $this->year->id)->firstOrFail();
        $this->assertEquals($rover->id, $order->rover_id);
        $this->assertEquals('confirmado', $order->status);
        $this->assertEquals('no_retirado', $order->withdrawal_status);
        $this->assertFalse((bool) $order->take_away); // delivery=SI
        $this->assertEquals('Calle Falsa 123', $order->delivery_address);
        $this->assertEquals(3, $order->total_portions);
        $this->assertEquals('15000.00', $order->total_amount);
        $this->assertEquals('15000.00', $order->total_paid);
        $this->assertEquals('0.00', $order->balance_due);
        $this->assertStringContainsString('2025: Cliente antiguo', $order->observations);
        $this->assertStringContainsString('Buena onda', $order->observations);
        $this->assertStringContainsString('2026: Pidió sin sal', $order->observations);
        $this->assertStringNotContainsString('<br>', $order->observations);

        $locroItem = $order->items()->where('product', 'locro')->firstOrFail();
        $this->assertEquals('personalizado', $locroItem->type);
        $this->assertEquals(3, $locroItem->quantity);
        $this->assertEquals('15000.00', $locroItem->line_total);

        $saucesItem = $order->items()->where('product', 'salsas')->firstOrFail();
        $this->assertEquals(1, $saucesItem->quantity);
        $this->assertEquals('0.00', $saucesItem->line_total);

        $payment = $order->payments()->firstOrFail();
        $this->assertEquals('15000.00', $payment->amount);
        // "Mercado Pago" no existe como medio de pago independiente: se
        // normaliza a 'transferencia' durante la importacion (ver
        // LegacyExcelImportAdapter y la migracion de normalizacion).
        $this->assertEquals('transferencia', $payment->method->slug);

        // El archivo staged se borra despues de confirmar.
        Storage::disk('local')->assertMissing('imports/staging/'.$token.'.xlsx');
    }

    public function test_confirm_uses_efectivo_when_mercado_pago_is_no(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['mercado_pago' => 'NO'])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals('efectivo', $order->payments()->firstOrFail()->method->slug);
    }

    public function test_confirm_does_not_create_payment_when_nothing_collected(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['dinero_cobrado' => 0])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(0, $order->payments()->count());
        $this->assertEquals('15000.00', $order->balance_due);
    }

    // ---------- Apellido/nombre faltante (clients.last_name/first_name son NOT NULL) ----

    public function test_confirm_fills_missing_last_name_with_placeholder(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['apellido' => ''])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $client = Client::where('phone', '11-2345-6789')->firstOrFail();
        $this->assertEquals('Maria', $client->first_name);
        $this->assertEquals('Completar', $client->last_name);
    }

    public function test_confirm_fills_missing_first_name_with_placeholder(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['nombre' => ''])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $client = Client::where('phone', '11-2345-6789')->firstOrFail();
        $this->assertEquals('Completar', $client->first_name);
        $this->assertEquals('Gomez', $client->last_name);
    }

    public function test_confirm_does_not_touch_name_of_reused_existing_client(): void
    {
        $admin = $this->makeAdmin();
        $existing = Client::createWithAutoHistoricalNumber([
            'first_name' => 'Ana', 'last_name' => 'Reales', 'phone' => '1123456789',
        ]);

        // El archivo trae el apellido vacio, pero el telefono ya matchea un
        // cliente existente: se reutiliza tal cual, nunca se pisa su nombre
        // real con el placeholder.
        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['apellido' => ''])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $existing->refresh();
        $this->assertEquals('Ana', $existing->first_name);
        $this->assertEquals('Reales', $existing->last_name);
        $this->assertDatabaseCount('clients', 1);
    }

    // ---------- Sin compra (cliente contactado, QTY vacio) --------------------

    public function test_confirm_creates_client_and_cancelado_order_when_no_purchase(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow([
            'qty' => '', 'importe' => '', 'salsas' => '', 'dinero_cobrado' => '', 'a_cobrar' => '',
        ])]))->json('token');

        $result = $this->confirm($admin, $token)->json('result');

        $this->assertEquals(1, $result['clients_created']);
        $this->assertEquals(1, $result['orders_created']);
        $this->assertEquals(0, $result['portions_imported']);
        $this->assertEquals(0, $result['total_amount']);

        $client = Client::where('phone', '11-2345-6789')->firstOrFail();
        $order = Order::where('client_id', $client->id)->firstOrFail();

        $this->assertEquals('cancelado', $order->status);
        $this->assertEquals(0, $order->total_portions);
        $this->assertEquals('0.00', $order->total_amount);
        $this->assertEquals('0.00', $order->balance_due);
        $this->assertEquals(0, $order->items()->count());
        $this->assertEquals(0, $order->payments()->count());
    }

    public function test_confirm_no_purchase_row_does_not_mark_contact_status_as_pedido_realizado(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['qty' => '', 'importe' => ''])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $client = Client::where('phone', '11-2345-6789')->firstOrFail();
        $this->assertDatabaseMissing('client_year_assignments', [
            'client_id' => $client->id,
            'year_id' => $this->year->id,
            'contact_status' => 'pedido_realizado',
        ]);
    }

    public function test_confirm_ignores_dinero_cobrado_when_no_purchase(): void
    {
        $admin = $this->makeAdmin();

        // Dato inconsistente a proposito (no deberia pasar en la practica,
        // pero si pasa, sin compra nunca se registra un pago).
        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow([
            'qty' => '', 'importe' => '', 'dinero_cobrado' => 5000,
        ])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(0, $order->payments()->count());
    }

    public function test_no_purchase_rows_do_not_count_as_bought_in_assignments_export(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['qty' => '', 'importe' => ''])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $order = Order::firstOrFail();
        // Mismo filtro que ya usa ClientAssignmentController::export() para
        // la columna "Compro" (status != 'cancelado'): un pedido sin compra
        // no debe aparecer ahi.
        $this->assertFalse(
            Order::where('id', $order->id)->where('status', '!=', 'cancelado')->exists(),
        );
    }

    // ---------- Reutilizacion de clientes / duplicados ------------------------

    public function test_confirm_reuses_existing_client_by_phone(): void
    {
        $admin = $this->makeAdmin();
        $existing = Client::createWithAutoHistoricalNumber([
            'first_name' => 'Maria', 'last_name' => 'Gomez', 'phone' => '1123456789',
        ]);

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow()]))->json('token');
        $result = $this->confirm($admin, $token)->json('result');

        $this->assertEquals(0, $result['clients_created']);
        $this->assertEquals(1, $result['clients_reused']);
        $this->assertDatabaseCount('clients', 1);
        $this->assertEquals($existing->id, Order::firstOrFail()->client_id);
    }

    public function test_confirm_creates_one_client_and_two_orders_for_duplicate_phone_rows(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([
            $this->legacyRow(['id_orden' => '1001']),
            $this->legacyRow(['id_orden' => '1002']),
        ]))->json('token');

        $result = $this->confirm($admin, $token)->json('result');

        $this->assertEquals(1, $result['clients_created']);
        $this->assertEquals(1, $result['clients_reused']);
        $this->assertEquals(2, $result['orders_created']);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('orders', 2);
    }

    // ---------- Rovers ---------------------------------------------------------

    public function test_unresolved_rover_is_not_autoassigned_without_override(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['rover' => 'Nombre Desconocido'])]))->json('token');
        $this->confirm($admin, $token)->assertOk();

        $this->assertNull(Order::firstOrFail()->rover_id);
    }

    public function test_rover_override_assigns_the_chosen_user(): void
    {
        $admin = $this->makeAdmin();
        $rover = User::factory()->create(['name' => 'Otro Nombre', 'is_active' => true]);
        $rover->assignRole('logistica');

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['rover' => 'Nombre Desconocido'])]))->json('token');
        $this->confirm($admin, $token, ['Nombre Desconocido' => $rover->id])->assertOk();

        $this->assertEquals($rover->id, Order::firstOrFail()->rover_id);
    }

    // ---------- Validacion / archivos invalidos ---------------------------------

    public function test_confirm_rejects_when_preview_had_blocking_errors(): void
    {
        $admin = $this->makeAdmin();

        $token = $this->analyze($admin, $this->legacyXlsx([$this->legacyRow(['telefono' => ''])]))->json('token');

        $this->confirm($admin, $token)->assertStatus(422);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_confirm_with_unknown_token_fails_gracefully(): void
    {
        $admin = $this->makeAdmin();

        $this->confirm($admin, (string) Str::uuid())->assertStatus(422);
    }

    // ---------- Todo o nada (rollback) ------------------------------------------

    public function test_import_rolls_back_everything_on_mid_transaction_failure(): void
    {
        $admin = $this->makeAdmin();

        $calls = 0;
        $this->partialMock(ClientAssignmentService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('syncFromOrder')->andReturnUsing(function (Order $order) use (&$calls) {
                $calls++;
                if ($calls === 2) {
                    throw new \RuntimeException('fallo forzado para probar rollback');
                }

                return new ClientAssignment;
            });
        });

        $file = $this->legacyXlsx([
            $this->legacyRow(['id_orden' => '1001', 'telefono' => '1123456789']),
            $this->legacyRow(['id_orden' => '1002', 'telefono' => '1155501234']),
        ]);
        $path = $file->storeAs('imports/staging', 'rollback-test.xlsx', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        $threw = false;
        try {
            app(ImportService::class)->import($absolutePath, $this->year->id, ImportFormat::LegacySite, [], $admin->id);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Se esperaba que import() propague la excepcion forzada.');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
