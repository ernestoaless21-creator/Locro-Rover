<?php

namespace App\Services;

use App\Models\PublicityMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicityMaterialService
{
    private const DISK = 'local';

    /**
     * Persiste el archivo en el disco privado y crea el registro.
     *
     * Ruta de almacenamiento: publicity-materials/{yearId}/{filename}
     * La ruta real es storage/app/private/... (nunca expuesta al navegador).
     * Las descargas pasan SIEMPRE por el controlador, que verifica permisos.
     */
    public function store(
        UploadedFile $file,
        int $yearId,
        int $categoryId,
        string $title,
        ?string $description,
        ?string $notes,
        ?string $materialDate,
        int $uploadedBy,
    ): PublicityMaterial {
        $path = $file->store("publicity-materials/{$yearId}", self::DISK);

        return PublicityMaterial::create([
            'year_id'               => $yearId,
            'publicity_category_id' => $categoryId,
            'title'                 => $title,
            'description'           => $description,
            'file_path'             => $path,
            'file_name'             => $file->getClientOriginalName(),
            'file_size'             => $file->getSize(),
            'mime_type'             => $file->getMimeType(),
            'notes'                 => $notes,
            'material_date'         => $materialDate,
            'uploaded_by'           => $uploadedBy,
        ]);
    }

    /**
     * Actualiza los metadatos y, opcionalmente, reemplaza el archivo.
     *
     * Al reemplazar: se guarda el archivo nuevo y se actualizan
     * file_path/file_name/file_size/mime_type; título, categoría,
     * descripción, observaciones y fecha se mantienen tal cual vengan en
     * $data (el formulario de edición los reenvía sin tocar). El archivo
     * viejo solo se borra del disco si, después de repuntar este registro al
     * nuevo, ningún otro material sigue apuntando a esa ruta — mismo
     * criterio de conteo de referencias que delete().
     */
    public function update(PublicityMaterial $material, array $data, ?UploadedFile $newFile = null): void
    {
        $oldPath = $material->file_path;

        if ($newFile) {
            $data['file_path'] = $newFile->store("publicity-materials/{$material->year_id}", self::DISK);
            $data['file_name'] = $newFile->getClientOriginalName();
            $data['file_size'] = $newFile->getSize();
            $data['mime_type'] = $newFile->getMimeType();
        }

        $material->update($data);

        if ($newFile && $oldPath !== $material->file_path) {
            $stillReferenced = PublicityMaterial::where('file_path', $oldPath)->exists();
            if (! $stillReferenced) {
                Storage::disk(self::DISK)->delete($oldPath);
            }
        }
    }

    /**
     * Elimina el registro y, salvo que otro material (por ejemplo uno
     * importado de/hacia otra edición) todavía apunte al mismo archivo
     * físico, también lo elimina del disco. La importación de Fase 16
     * reutiliza el archivo original en vez de duplicarlo (ver
     * PublicityImportService), así que varios registros pueden compartir el
     * mismo file_path — borrar uno no debe romper el acceso de los demás.
     */
    public function delete(PublicityMaterial $material): void
    {
        $stillReferenced = PublicityMaterial::where('file_path', $material->file_path)
            ->where('id', '!=', $material->id)
            ->exists();

        if (! $stillReferenced) {
            Storage::disk(self::DISK)->delete($material->file_path);
        }

        $material->delete();
    }

    /**
     * Devuelve una respuesta de descarga autenticada.
     * El nombre original del archivo se conserva en la cabecera Content-Disposition.
     */
    public function download(PublicityMaterial $material): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($material->file_path), 404);

        return Storage::disk(self::DISK)->download($material->file_path, $material->file_name);
    }

    /**
     * Igual que download(), pero con disposición 'inline' (comportamiento por
     * defecto de Storage::response()) para que el navegador lo abra en una
     * pestaña nueva en vez de forzar la descarga, cuando el tipo de archivo
     * lo permite (PDF, imágenes, video).
     */
    public function view(PublicityMaterial $material): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($material->file_path), 404);

        return Storage::disk(self::DISK)->response($material->file_path, $material->file_name);
    }
}
