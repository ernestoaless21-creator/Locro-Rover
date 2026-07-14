<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['type', 'presentes', 'ausentes']);

            // Secretario: ID para enlace activo + snapshot de nombre para historial
            $table->foreignId('secretary_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('secretary_name')->nullable()->after('secretary_id');

            // Invitados externos al sistema
            $table->text('otros_asistentes')->nullable()->after('secretary_name');
        });

        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            // user_id nullable: si el usuario es eliminado, la asistencia queda
            // preservada via user_name (snapshot histórico).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name'); // snapshot en el momento de redactar el acta
            $table->boolean('is_present')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendances');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['secretary_id']);
            $table->dropColumn(['secretary_id', 'secretary_name', 'otros_asistentes']);
            $table->string('type', 50)->after('date');
            $table->text('presentes')->nullable();
            $table->text('ausentes')->nullable();
        });
    }
};
