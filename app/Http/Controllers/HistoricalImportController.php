<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeImportRequest;
use App\Http\Requests\ConfirmImportRequest;
use App\Models\User;
use App\Models\Year;
use App\Services\Import\ImportException;
use App\Services\Import\ImportFormat;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase P2: importacion historica de clientes/pedidos desde Excel. Flujo en 2
 * pasos obligatorios (analyze -> confirm, nunca se puede confirmar sin haber
 * analizado antes: confirm() solo acepta un token que analyze() genero). Ver
 * App\Services\Import\ImportService para el pipeline real.
 */
class HistoricalImportController extends Controller
{
    private const DISK = 'local';

    private const STAGING_DIR = 'imports/staging';

    public function create(): Response
    {
        Gate::authorize('historico.importar');

        return Inertia::render('Imports/Create', [
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            // Para los selects de "Rover no encontrado, elegí a quién asignarlo".
            'users' => User::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function analyze(AnalyzeImportRequest $request, ImportService $importService): JsonResponse
    {
        $this->cleanStaleStagingFiles();

        $token = (string) Str::uuid();
        $path = $request->file('file')->storeAs(self::STAGING_DIR, "{$token}.xlsx", self::DISK);

        $format = $request->filled('format') ? ImportFormat::from($request->string('format')->toString()) : null;

        try {
            $preview = $importService->analyzeFile(Storage::disk(self::DISK)->path($path), $format);
        } catch (ImportException $e) {
            Storage::disk(self::DISK)->delete($path);

            return $this->importExceptionResponse($e);
        }

        return response()->json([
            'token' => $token,
            'preview' => $preview,
        ]);
    }

    public function confirm(ConfirmImportRequest $request, ImportService $importService): JsonResponse
    {
        $path = $this->stagingPath($request->string('token')->toString());

        if (! Storage::disk(self::DISK)->exists($path)) {
            return response()->json([
                'message' => 'El archivo analizado ya no está disponible. Volvé a subirlo y analizarlo de nuevo.',
            ], 422);
        }

        $format = ImportFormat::from($request->string('format')->toString());

        try {
            $result = $importService->import(
                Storage::disk(self::DISK)->path($path),
                $request->integer('year_id'),
                $format,
                $request->input('rover_overrides', []),
                $request->user()->id,
            );
        } catch (ImportException $e) {
            return $this->importExceptionResponse($e);
        } finally {
            Storage::disk(self::DISK)->delete($path);
        }

        return response()->json(['result' => $result]);
    }

    public function cancel(string $token): JsonResponse
    {
        Gate::authorize('historico.importar');

        if (Str::isUuid($token)) {
            Storage::disk(self::DISK)->delete(self::STAGING_DIR."/{$token}.xlsx");
        }

        return response()->json(['deleted' => true]);
    }

    private function stagingPath(string $token): string
    {
        // La regla 'uuid' del FormRequest ya lo valida; se revalida aca por
        // las dudas, para nunca construir un path de disco a partir de un
        // valor no confiable.
        abort_unless(Str::isUuid($token), 422, 'Token de importación inválido.');

        return self::STAGING_DIR."/{$token}.xlsx";
    }

    private function importExceptionResponse(ImportException $e): JsonResponse
    {
        if ($e->candidates() !== []) {
            return response()->json([
                'message' => $e->getMessage(),
                'needs_format_selection' => true,
                'candidates' => array_map(
                    fn (ImportFormat $f) => ['value' => $f->value, 'label' => $f->label()],
                    $e->candidates(),
                ),
            ], 422);
        }

        return response()->json(['message' => $e->getMessage()], 422);
    }

    /**
     * Limpieza oportunista de archivos de staging abandonados (analyze sin
     * confirm posterior) con mas de 24h. No hay scheduler configurado en
     * este proyecto (ver routes/console.php), asi que se hace aca en vez de
     * agregar infraestructura nueva solo para esto.
     */
    private function cleanStaleStagingFiles(): void
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists(self::STAGING_DIR)) {
            return;
        }

        foreach ($disk->files(self::STAGING_DIR) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->timestamp) {
                $disk->delete($file);
            }
        }
    }
}
