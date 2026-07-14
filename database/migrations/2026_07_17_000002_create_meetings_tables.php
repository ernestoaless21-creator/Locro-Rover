<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('year_id')->constrained('years')->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->string('type', 50);
            $table->text('development')->nullable();
            $table->text('presentes')->nullable();
            $table->text('ausentes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->text('text');
            $table->string('category', 50);
            $table->string('team', 50)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('meeting_team_document', function (Blueprint $table) {
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('team_document_id')->constrained('team_documents')->cascadeOnDelete();
            $table->primary(['meeting_id', 'team_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_team_document');
        Schema::dropIfExists('meeting_decisions');
        Schema::dropIfExists('meetings');
    }
};
