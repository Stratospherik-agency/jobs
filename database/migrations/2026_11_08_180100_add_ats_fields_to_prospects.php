<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('ats', 10)->nullable();
            $table->string('ats_system')->nullable();
            $table->unsignedTinyInteger('ats_score')->nullable();
            $table->text('ats_missing_keywords')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn(['ats', 'ats_system', 'ats_score', 'ats_missing_keywords']);
        });
    }
};
