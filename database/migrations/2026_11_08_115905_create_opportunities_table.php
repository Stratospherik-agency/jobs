<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            // Offre
            $table->string('company');
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('rate')->nullable(); // taux d'activité ou type de contrat
            $table->string('source')->nullable();
            $table->string('url', 2048)->nullable();
            $table->date('published_at')->nullable();
            $table->unsignedTinyInteger('relevance')->nullable(); // 1 à 3
            // Candidature
            $table->string('status')->default('a_evaluer')->index();
            $table->date('applied_at')->nullable();
            $table->date('follow_up_at')->nullable()->index();
            $table->text('feedback')->nullable();
            $table->text('notes')->nullable();
            $table->date('added_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
