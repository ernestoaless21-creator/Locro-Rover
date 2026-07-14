<?php

namespace App\Services;

use App\Models\TeamDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamDocumentService
{
    private const DISK = 'local';

    /**
     * Persiste el archivo en el disco privado y crea el registro.
     *
     * Ruta de almacenamiento: team-documents/{yearId}/{team}/{filename}
     * La ruta real es storage/app/private/... (nunca expuesta al navegador).
     * Las descargas pasan SIEMPRE por el controlador, que verifica permisos.
     */
    public function store(
        UploadedFile $file,
        string $team,
        int $yearId,
        string $name,
        ?string $description,
        int $uploadedBy,
    ): TeamDocument {
        $path = $file->store("team-documents/{$yearId}/{$team}", self::DISK);

        return TeamDocument::create([
            'team'        => $team,
            'year_id'     => $yearId,
            'name'        => $name,
            'description' => $description,
            'file_path'   => $path,
            'file_name'   => $file->getClientOriginalName(),
            'file_size'   => $file->getSize(),
            'mime_type'   => $file->getMimeType(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function update(TeamDocument $doc, string $name, ?string $description): void
    {
        $doc->update(['name' => $name, 'description' => $description]);
    }

    /**
     * Elimina el archivo físico y el registro de base de datos.
     * Si el archivo ya no existe en disco, continúa y borra solo el registro.
     */
    public function delete(TeamDocument $doc): void
    {
        Storage::disk(self::DISK)->delete($doc->file_path);
        $doc->delete();
    }

    /**
     * Devuelve una respuesta de descarga autenticada.
     * El nombre original del archivo se conserva en la cabecera Content-Disposition.
     */
    public function download(TeamDocument $doc): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($doc->file_path), 404);

        return Storage::disk(self::DISK)->download($doc->file_path, $doc->file_name);
    }
}
