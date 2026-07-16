<?php

namespace App\Services;

use App\Models\LogisticsRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogisticsRecordService
{
    private const DISK = 'local';

    /**
     * Persiste el archivo en el disco privado y crea el registro.
     *
     * Ruta de almacenamiento: logistics-records/{yearId}/{filename}
     * La ruta real es storage/app/private/... (nunca expuesta al navegador).
     * Las descargas/vistas pasan SIEMPRE por el controlador, que verifica permisos.
     */
    public function store(
        UploadedFile $file,
        int $yearId,
        int $categoryId,
        string $title,
        ?string $description,
        ?string $purpose,
        ?string $notes,
        ?string $recordDate,
        int $uploadedBy,
    ): LogisticsRecord {
        $path = $file->store("logistics-records/{$yearId}", self::DISK);

        return LogisticsRecord::create([
            'year_id'                => $yearId,
            'logistics_category_id'  => $categoryId,
            'title'                  => $title,
            'description'            => $description,
            'purpose'                => $purpose,
            'file_path'              => $path,
            'file_name'              => $file->getClientOriginalName(),
            'file_size'              => $file->getSize(),
            'mime_type'              => $file->getMimeType(),
            'notes'                  => $notes,
            'record_date'            => $recordDate,
            'uploaded_by'            => $uploadedBy,
        ]);
    }

    /**
     * Actualiza los metadatos y, opcionalmente, reemplaza el archivo.
     *
     * Al reemplazar: se guarda el archivo nuevo y se actualizan
     * file_path/file_name/file_size/mime_type; el resto de los campos se
     * mantiene tal cual venga en $data. El archivo viejo solo se borra del
     * disco si, después de repuntar este registro al nuevo, ningún otro
     * registro sigue apuntando a esa ruta — mismo criterio de conteo de
     * referencias que delete() (necesario porque la importación reutiliza
     * el archivo original en vez de duplicarlo).
     */
    public function update(LogisticsRecord $record, array $data, ?UploadedFile $newFile = null): void
    {
        $oldPath = $record->file_path;

        if ($newFile) {
            $data['file_path'] = $newFile->store("logistics-records/{$record->year_id}", self::DISK);
            $data['file_name'] = $newFile->getClientOriginalName();
            $data['file_size'] = $newFile->getSize();
            $data['mime_type'] = $newFile->getMimeType();
        }

        $record->update($data);

        if ($newFile && $oldPath !== $record->file_path) {
            $stillReferenced = LogisticsRecord::where('file_path', $oldPath)->exists();
            if (! $stillReferenced) {
                Storage::disk(self::DISK)->delete($oldPath);
            }
        }
    }

    /**
     * Elimina el registro y, salvo que otro registro (por ejemplo uno
     * importado de/hacia otra edición) todavía apunte al mismo archivo
     * físico, también lo elimina del disco.
     */
    public function delete(LogisticsRecord $record): void
    {
        $stillReferenced = LogisticsRecord::where('file_path', $record->file_path)
            ->where('id', '!=', $record->id)
            ->exists();

        if (! $stillReferenced) {
            Storage::disk(self::DISK)->delete($record->file_path);
        }

        $record->delete();
    }

    /**
     * Devuelve una respuesta de descarga autenticada.
     * El nombre original del archivo se conserva en la cabecera Content-Disposition.
     */
    public function download(LogisticsRecord $record): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($record->file_path), 404);

        return Storage::disk(self::DISK)->download($record->file_path, $record->file_name);
    }

    /**
     * Igual que download(), pero con disposición 'inline' para que el
     * navegador lo abra en una pestaña nueva en vez de forzar la descarga,
     * cuando el tipo de archivo lo permite (PDF, imágenes).
     */
    public function view(LogisticsRecord $record): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($record->file_path), 404);

        return Storage::disk(self::DISK)->response($record->file_path, $record->file_name);
    }
}
