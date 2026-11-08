<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('ats', 10)->nullable();          // oui | non | inconnu
            $table->string('ats_system')->nullable();       // Workday, SuccessFactors…
            $table->unsignedTinyInteger('ats_score')->nullable(); // couverture des mots-clés, 0-100
            $table->text('ats_missing_keywords')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['ats', 'ats_system', 'ats_score', 'ats_missing_keywords']);
        });
    }
};
