<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Candidature préparée pour une opportunité : envoyée uniquement après validation explicite.
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('brouillon');
            $table->string('to_email')->nullable();
            $table->string('cc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->string('source_draft_id')->nullable(); // brouillon Gmail importé
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('gmail_message_id')->nullable();
            $table->string('gmail_thread_id')->nullable()->index();
            $table->string('drive_folder_url', 2048)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('application_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path'); // disque privé « local », jamais exposé publiquement
            $table->string('mime')->default('application/pdf');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('drive_file_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_attachments');
        Schema::dropIfExists('applications');
    }
};
