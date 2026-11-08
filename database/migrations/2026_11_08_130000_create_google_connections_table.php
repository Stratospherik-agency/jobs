<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jetons OAuth du compte Google, chiffrés par l'application (APP_KEY).
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->text('scopes')->nullable();
            $table->string('drive_root_folder_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
