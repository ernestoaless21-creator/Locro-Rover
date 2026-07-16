<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 16: publicidad historica. Archivo de todo el material publicitario
     * generado para cada edicion (flyers, historias, reels, folletos, etc.).
     *
     * Mismo patron que Compras/Infraestructura (Fases 14/15): catalogo
     * reutilizable (publicity_categories) sin year_id ni team, y datos
     * concretos por edicion (publicity_materials) con su propio year_id. El
     * equipo Publicidad se identifica por slug via las rutas, nunca por un
     * ID de equipo hardcodeado.
     *
     * A diferencia de esas fases, aca no hay una entidad "catalogo" separada
     * del registro por edicion: cada publicity_material ES el registro (con
     * su archivo). Las categorias si son un catalogo reutilizable clasico,
     * para poder agregar categorias nuevas sin tocar codigo.
     */
    public function up(): void
    {
        Schema::create('publicity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('publicity_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->foreignId('publicity_category_id')->constrained('publicity_categories')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100)->nullable();

            $table->text('notes')->nullable();
            $table->date('material_date')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Categorias iniciales (seccion "Categorias" del prompt de la fase).
        // Se insertan aca (no en un seeder aparte) porque son datos de
        // referencia que deben existir apenas se crea la tabla, igual que
        // los medios de pago iniciales en RolesAndPermissionsSeeder.
        $now = now();
        DB::table('publicity_categories')->insert(
            collect(['Flyers', 'Publicaciones', 'Historias', 'Reels', 'Folletos'])
                ->map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('publicity_materials');
        Schema::dropIfExists('publicity_categories');
    }
};
