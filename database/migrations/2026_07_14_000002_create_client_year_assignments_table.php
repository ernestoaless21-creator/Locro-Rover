<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6A. Asignaciones anuales de clientes ("call center"): CLIENTE !=
 * ASIGNACION ANUAL != PEDIDO (ver ClientAssignment). Representa que
 * usuario/Rover es responsable de contactar a un cliente durante UNA
 * edicion determinada, y en que estado de contacto esta ese seguimiento.
 * NO reemplaza ni duplica 'orders': una asignacion puede existir sin ningun
 * pedido real (cliente que nunca compro) y un pedido siempre reutiliza/crea
 * su asignacion correspondiente (ver ClientAssignmentService::syncFromOrder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_year_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('year_id')->constrained()->cascadeOnDelete();

            // Usuario responsable de contactar a este cliente en esta edicion.
            // Nullable: representa "sin asignar" (disponible para autoasignacion
            // o reparto). Si el usuario se elimina fisicamente (rara vez, ya que
            // User usa SoftDeletes), la asignacion queda sin responsable en vez
            // de romperse.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            // pendiente | no_respondio | volver_a_llamar | no_interesado | interesado | pedido_realizado
            $table->string('contact_status')->default('pendiente');

            $table->timestamp('last_contacted_at')->nullable();
            $table->foreignId('last_contacted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Regla estructural obligatoria: como maximo UNA asignacion por
            // combinacion cliente + edicion. Protege tanto al reparto manual
            // como a "Generar asignaciones desde edicion anterior" (idempotencia).
            $table->unique(['client_id', 'year_id']);

            // Indices para los filtros mas frecuentes de la pantalla de
            // asignaciones/call center (por edicion+responsable, por
            // edicion+estado, y busqueda por numero historico via clients).
            $table->index(['year_id', 'assigned_user_id']);
            $table->index(['year_id', 'contact_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_year_assignments');
    }
};
