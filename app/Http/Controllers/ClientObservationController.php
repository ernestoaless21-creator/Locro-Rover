<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientObservationRequest;
use App\Models\Client;
use App\Models\ClientObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ClientObservationController extends Controller
{
    /**
     * Endpoint JSON (no Inertia visit) para que el editor inline de la tabla
     * pueda guardar con Enter y actualizar su propia fila sin recargar toda
     * la pagina, tal como funcionaba el resto de las acciones rapidas de la
     * app anterior (checkboxes de retiro/pago via axios directo).
     *
     * Anti-duplicado: el frontend envia un 'client_request_id' (uuid generado
     * una vez por sesion de edicion de esa celda). Si Enter y blur disparan
     * casi simultaneamente, el segundo request con el mismo client_request_id
     * se ignora (se devuelve la observacion ya creada, sin crear una segunda).
     * Se usa cache con TTL corto en vez de una columna en DB porque es
     * unicamente para deduplicar eventos de UI, no para logica de negocio.
     */
    public function store(StoreClientObservationRequest $request, Client $client): JsonResponse
    {
        $text = trim((string) $request->validated('observation'));

        if ($text === '') {
            return response()->json(['skipped' => true]);
        }

        $requestId = $request->validated('client_request_id');

        if ($requestId) {
            $cacheKey = "client_observation_dedup:{$client->id}:{$requestId}";

            $existingId = Cache::get($cacheKey);
            if ($existingId) {
                return response()->json([
                    'observation' => ClientObservation::with('createdBy:id,name')->find($existingId),
                    'deduplicated' => true,
                ]);
            }
        }

        $observation = ClientObservation::create([
            'client_id' => $client->id,
            'year_id' => $request->validated('year_id'),
            'observation' => $text,
            'created_by' => $request->user()->id,
        ]);

        if ($requestId) {
            Cache::put("client_observation_dedup:{$client->id}:{$requestId}", $observation->id, now()->addSeconds(30));
        }

        return response()->json([
            'observation' => $observation->load('createdBy:id,name'),
        ]);
    }
}
