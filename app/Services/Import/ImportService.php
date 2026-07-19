<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\ClientAssignmentService;
use App\Services\Import\Contracts\ImportFormatAdapter;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Orquestador y unica clase que habla el Controller. analyzeFile() e
 * import() comparten el mismo pipeline privado (process()) con un flag
 * persist: true/false, para garantizar que la vista previa sea EXACTAMENTE
 * lo que va a pasar en la importacion real (mismo parseo/validacion/
 * transformacion; la unica diferencia es si al final se escribe en la base
 * dentro de una transaccion). Evita la clase de bug "la preview decia una
 * cosa y la importacion hizo otra".
 */
class ImportService
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportAdapterRegistry $registry,
        private readonly RowValidator $validator,
        private readonly RowTransformer $transformer,
        private readonly ClientMatcher $clientMatcher,
        private readonly RoverMatcher $roverMatcher,
        private readonly ClientAssignmentService $assignments,
    ) {}

    /** @return array<string,mixed> preview, ver process() */
    public function analyzeFile(string $storedPath, ?ImportFormat $format = null): array
    {
        return $this->process($storedPath, $format, null, [], null, persist: false)['preview'];
    }

    /**
     * @param  array<string,int|null>  $roverOverrides  nombre original (tal
     *                                                  cual aparece en unresolved_rovers del preview) => user_id|null
     * @return array<string,mixed> resumen final
     */
    public function import(string $storedPath, int $yearId, ImportFormat $format, array $roverOverrides, int $userId): array
    {
        return $this->process($storedPath, $format, $yearId, $roverOverrides, $userId, persist: true)['result'];
    }

    /**
     * @return array{preview: array<string,mixed>, result: array<string,mixed>|null}
     */
    private function process(
        string $storedPath,
        ?ImportFormat $format,
        ?int $yearId,
        array $roverOverrides,
        ?int $userId,
        bool $persist,
    ): array {
        $startedAt = microtime(true);

        $sheet = $this->reader->load($storedPath);
        $adapter = $format !== null ? $this->registry->get($format) : $this->resolveAdapter($sheet);
        $rows = $adapter->parse($sheet);

        [$errors, $warnings, $rowIssues] = $this->validateRows($rows);
        $canImport = $errors === [];

        $preloadedClients = $this->clientMatcher->preload($rows);
        $roverNames = collect($rows)->map(fn (ImportRow $r) => trim((string) $r->roverName))->filter()->unique()->values()->all();
        $roverMatch = $this->roverMatcher->matchMany($roverNames);

        [$existingClientsMatched, $newClientPhonesCount] = $this->countClients($rows, $preloadedClients);

        $validRows = array_values(array_filter(
            $rows,
            fn (ImportRow $r) => ($rowIssues[$r->sourceRowNumber]['errors'] ?? []) === [],
        ));

        $preview = [
            'format' => $adapter::format()->value,
            'format_label' => $adapter::format()->label(),
            'total_rows' => count($rows),
            'existing_clients_matched' => $existingClientsMatched,
            'new_clients_to_create' => $newClientPhonesCount,
            'orders_to_create' => $canImport ? count($validRows) : 0,
            'total_portions' => $canImport ? (int) collect($validRows)->sum(fn (ImportRow $r) => $this->effectivePortions($r)) : 0,
            'total_amount' => $canImport ? round((float) collect($validRows)->sum(fn (ImportRow $r) => $this->effectiveAmount($r)), 2) : 0.0,
            'error_rows' => count(array_unique(array_column($errors, 'row'))),
            'duplicate_rows' => count(array_unique(array_column(array_filter($warnings, fn ($w) => $w['code'] === 'duplicate_phone'), 'row'))),
            'invalid_phone_rows' => count(array_unique(array_column(array_filter($warnings, fn ($w) => $w['code'] === 'invalid_phone_format'), 'row'))),
            'missing_data_rows' => count(array_unique(array_column(array_filter($errors, fn ($e) => in_array($e['code'], ['missing_name', 'missing_phone'], true)), 'row'))),
            'errors' => $errors,
            'warnings' => $warnings,
            'unresolved_rovers' => $roverMatch['unresolved'],
            'can_import' => $canImport,
            'sample_rows' => array_slice(array_map(fn (ImportRow $r) => $this->rowSummary($r, $rowIssues), $rows), 0, 25),
        ];

        if (! $persist) {
            return ['preview' => $preview, 'result' => null];
        }

        if (! $canImport) {
            throw ImportException::blockedByErrors();
        }

        $result = DB::transaction(fn () => $this->persist($validRows, $yearId, $roverOverrides, $userId, $roverMatch, $preloadedClients));
        $result['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return ['preview' => $preview, 'result' => $result];
    }

    /**
     * @param  ImportRow[]  $rows
     * @return array{0: array<int,array{row:int,code:string,message:string}>, 1: array<int,array{row:int,code:string,message:string}>, 2: array<int,array{errors:array,warnings:array}>}
     */
    private function validateRows(array $rows): array
    {
        $duplicatePhoneCounts = collect($rows)
            ->map(fn (ImportRow $r) => $this->phoneKey($r))
            ->filter()
            ->countBy()
            ->all();

        $errors = [];
        $warnings = [];
        $rowIssues = [];

        foreach ($rows as $row) {
            $issues = $this->validator->validate($row, $duplicatePhoneCounts);
            $rowIssues[$row->sourceRowNumber] = $issues;

            foreach ($issues['errors'] as $e) {
                $errors[] = ['row' => $row->sourceRowNumber, ...$e];
            }
            foreach ($issues['warnings'] as $w) {
                $warnings[] = ['row' => $row->sourceRowNumber, ...$w];
            }
        }

        return [$errors, $warnings, $rowIssues];
    }

    /**
     * @param  ImportRow[]  $rows
     * @return array{0:int,1:int} [clientes existentes matcheados, clientes nuevos distintos a crear]
     */
    private function countClients(array $rows, array $preloadedClients): array
    {
        $existing = 0;
        $newPhones = [];

        foreach ($rows as $row) {
            $key = $this->phoneKey($row);
            if ($key === null) {
                continue;
            }

            if ($this->clientMatcher->match($row, $preloadedClients) !== null) {
                $existing++;
            } else {
                $newPhones[$key] = true;
            }
        }

        return [$existing, count($newPhones)];
    }

    private function phoneKey(ImportRow $row): ?string
    {
        $raw = trim((string) $row->phoneRaw);
        if ($raw === '') {
            return null;
        }

        return Client::normalizePhone($raw) ?? $raw;
    }

    /**
     * Mismo criterio que RowTransformer::effectivePortions(): QTY vacio o
     * null es un cliente contactado que no compro (0 porciones), no un
     * error. Se recalcula aca (en vez de exponer el metodo de
     * RowTransformer) porque ImportService lo necesita para el AGREGADO de
     * preview/resumen, un uso distinto a construir atributos de modelo.
     */
    private function effectivePortions(ImportRow $row): int
    {
        return max(0, (int) ($row->portions ?? 0));
    }

    /**
     * Importe efectivo: 0 si la fila no tuvo compra (0 porciones), sin
     * importar que haya quedado algun valor suelto en la columna Importe.
     * Mantiene el preview EXACTAMENTE alineado con lo que persist() termina
     * grabando (ver docblock de la clase).
     */
    private function effectiveAmount(ImportRow $row): float
    {
        return $this->effectivePortions($row) > 0 ? (float) ($row->totalAmount ?? 0) : 0.0;
    }

    /** @return array<string,mixed> */
    private function persist(array $rows, int $yearId, array $roverOverrides, int $userId, array $roverMatch, array $preloadedClients): array
    {
        // 'mercado_pago' no existe como medio de pago independiente (ver
        // adapters de import, que ya normalizan cualquier hint equivalente a
        // 'transferencia' antes de llegar aca).
        $paymentMethods = PaymentMethod::query()->whereIn('slug', ['efectivo', 'transferencia'])->pluck('id', 'slug');

        $clientsCreated = 0;
        $clientsReused = 0;
        $ordersCreated = 0;
        $portionsImported = 0;
        $totalAmount = 0.0;

        /** @var array<string,int> $clientCache telefono normalizado => client id, incluye los creados en esta misma corrida */
        $clientCache = [];

        foreach ($rows as $row) {
            $phoneKey = $this->phoneKey($row);

            if (isset($clientCache[$phoneKey])) {
                $clientId = $clientCache[$phoneKey];
                $clientsReused++;
            } else {
                $existing = $this->clientMatcher->match($row, $preloadedClients);
                if ($existing !== null) {
                    $clientId = $existing->id;
                    $clientsReused++;
                } else {
                    $client = Client::createWithAutoHistoricalNumber($this->transformer->toClientAttributes($row, $userId));
                    $clientId = $client->id;
                    $clientsCreated++;
                }
                $clientCache[$phoneKey] = $clientId;
            }

            $roverName = trim((string) $row->roverName);
            $roverId = $roverName !== ''
                ? ($roverMatch['matched'][$roverName] ?? ($roverOverrides[$roverName] ?? null))
                : null;

            /** @var Order $order */
            $order = Order::create($this->transformer->toOrderAttributes($row, $clientId, $yearId, $roverId, $userId));

            foreach ($this->transformer->toOrderItemsAttributes($row, $userId) as $itemAttrs) {
                $order->items()->create($itemAttrs);
            }

            $order->recalculateTotals();

            $paymentMethodId = $paymentMethods->get($row->paymentMethodHint ?? 'efectivo') ?? $paymentMethods->get('efectivo');
            if ($paymentMethodId !== null) {
                $paymentAttrs = $this->transformer->toPaymentAttributes($row, $paymentMethodId, $userId);
                if ($paymentAttrs !== null) {
                    $order->payments()->create($paymentAttrs);
                }
            }

            // Sin compra (0 porciones): NO se sincroniza la asignacion
            // cliente/edicion. syncFromOrder() marca contact_status como
            // 'pedido_realizado' incondicionalmente, y eso seria falso para
            // un cliente que fue contactado pero no compro ese año (ver
            // RowTransformer::toOrderAttributes). Su asignacion queda tal
            // cual estuviera (o se crea mas adelante con el default
            // 'pendiente', igual que para cualquier cliente sin gestionar).
            if ($this->effectivePortions($row) > 0) {
                $this->assignments->syncFromOrder($order->fresh());
            }

            $ordersCreated++;
            $portionsImported += $this->effectivePortions($row);
            $totalAmount += $this->effectiveAmount($row);
        }

        return [
            'clients_created' => $clientsCreated,
            'clients_reused' => $clientsReused,
            'orders_created' => $ordersCreated,
            'portions_imported' => $portionsImported,
            'total_amount' => round($totalAmount, 2),
        ];
    }

    private function resolveAdapter(Worksheet $sheet): ImportFormatAdapter
    {
        $matches = $this->registry->detectFormat($sheet);

        if (count($matches) === 0) {
            throw ImportException::unsupportedFormat();
        }

        if (count($matches) > 1) {
            throw ImportException::ambiguousFormat(array_map(fn (ImportFormatAdapter $a) => $a::format(), $matches));
        }

        return $matches[0];
    }

    /** @return array<string,mixed> */
    private function rowSummary(ImportRow $row, array $rowIssues): array
    {
        $issues = $rowIssues[$row->sourceRowNumber] ?? ['errors' => [], 'warnings' => []];

        return [
            'row' => $row->sourceRowNumber,
            'name' => trim(($row->firstName ?? '').' '.($row->lastName ?? '')),
            'phone' => $row->phoneRaw,
            'portions' => $row->portions,
            'total_amount' => $row->totalAmount,
            'status' => $issues['errors'] !== [] ? 'error' : ($issues['warnings'] !== [] ? 'warning' : 'ok'),
        ];
    }
}
