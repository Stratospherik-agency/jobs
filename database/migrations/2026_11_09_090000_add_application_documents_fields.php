<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['opportunities', 'prospects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('documents_url', 500)->nullable();   // dossier Google Drive privé
                $table->text('cover_letter_text')->nullable();      // lettre à copier sur une plateforme
                $table->string('message_subject')->nullable();      // objet de l'e-mail ou titre du message
                $table->text('message_body')->nullable();           // e-mail ou message d'accompagnement
                $table->text('documents_links')->nullable();        // liens d'export PDF Canva (temporaires)
            });
        }

        Schema::table('application_attachments', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable(); // lien d'origine déjà importé
        });
    }

    public function down(): void
    {
        foreach (['opportunities', 'prospects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['documents_url', 'cover_letter_text', 'message_subject', 'message_body', 'documents_links']);
            });
        }

        Schema::table('application_attachments', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });
    }
};
