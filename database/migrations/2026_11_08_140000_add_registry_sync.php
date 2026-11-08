<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            // Dernières valeurs lues dans le registre Sheets, pour ne reporter que ses changements.
            $table->json('registry_snapshot')->nullable();
        });

        Schema::table('google_connections', function (Blueprint $table) {
            $table->timestamp('registry_synced_at')->nullable();
            $table->text('registry_sync_summary')->nullable();
            $table->text('registry_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', fn (Blueprint $table) => $table->dropColumn('registry_snapshot'));
        Schema::table('google_connections', fn (Blueprint $table) => $table->dropColumn(['registry_synced_at', 'registry_sync_summary', 'registry_sync_error']));
    }
};
