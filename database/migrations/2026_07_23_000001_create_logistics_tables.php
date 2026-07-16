<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 17: logistica historica. Archivo de todo lo que usa el equipo de
     * Logistica en cada edicion (recorridos, mapas, exportaciones de
     * clientes, listados telefonicos, etc.).
     *
     * Mismo patron que Publicidad (Fase 16): catalogo reutilizable
     * (logistics_categories) sin year_id ni team, y registros por edicion
     * (logistics_records) con su propio year_id. El equipo Logistica se
     * identifica por slug via las rutas, nunca por un ID de equipo
     * hardcodeado. No hay un catalogo separado de "registros": cada
     * logistics_record ES el registro (con su archivo).
     *
     * Categorias iniciales (revision de producto post-implementacion): se
     * reemplazo "Delivery" por "Mapas" y se sumo "Etiquetas". "Delivery" no
     * es un TIPO de archivo sino una actividad/finalidad -- ese matiz ahora
     * se captura en el campo "purpose" (Finalidad) de cada registro, p.ej.
     * una exportacion de clientes puede tener categoria "Exportaciones" y
     * finalidad "Entrega domicilio" o "Reparto turno mañana".
     */
    public function up(): void
    {
        Schema::create('logistics_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('logistics_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->foreignId('logistics_category_id')->constrained('logistics_categories')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Finalidad: contexto operativo corto (no reemplaza description).
            // Ej: "Reparto turno mañana", "Impresión de etiquetas".
            $table->string('purpose')->nullable();

            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100)->nullable();

            $table->text('notes')->nullable();
            $table->date('record_date')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Categorias iniciales (ver seccion "Categorias iniciales" del prompt).
        $now = now();
        DB::table('logistics_categories')->insert(
            collect(['Recorridos', 'Mapas', 'Exportaciones', 'Listados telefónicos', 'Etiquetas', 'Otros'])
                ->map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_records');
        Schema::dropIfExists('logistics_categories');
    }
};
