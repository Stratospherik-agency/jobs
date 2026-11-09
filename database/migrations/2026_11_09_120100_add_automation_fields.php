<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('contact_email')->nullable();              // adresse de candidature, si l'annonce en donne une
            $table->unsignedTinyInteger('relevance_score')->nullable(); // score de pertinence sur 100
            $table->json('relevance_reasons')->nullable();             // détail du score, critère par critère
            $table->string('follow_up_draft_id')->nullable();          // brouillon Gmail de relance
            $table->timestamp('follow_up_drafted_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();         // rappel « toujours sans réponse »
            $table->longText('interview_prep')->nullable();            // fiche de préparation d'entretien (Markdown)
            $table->timestamp('interview_prep_at')->nullable();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('auto_prepared_at')->nullable(); // préparée d'office par le tableau de bord
            $table->timestamp('ready_notified_at')->nullable(); // « candidature prête » déjà signalée
        });

        // Dernière exécution de chaque automatisation (résumé du matin, bilan, veille…).
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task')->unique();
            $table->timestamp('ran_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        // Messages Gmail déjà analysés (réponses des recruteurs, veille), pour ne jamais les traiter deux fois.
        Schema::create('tracked_messages', function (Blueprint $table) {
            $table->id();
            $table->string('gmail_message_id');
            $table->string('purpose', 20);                 // reponse | veille
            $table->string('kind', 30)->nullable();        // refus, entretien, accuse, nomination…
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['gmail_message_id', 'purpose']);
        });

        Schema::table('prospects', function (Blueprint $table) {
            $table->timestamp('follow_up_notified_at')->nullable(); // relance signalée
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn('follow_up_notified_at');
        });
        Schema::dropIfExists('tracked_messages');
        Schema::dropIfExists('automation_runs');

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['auto_prepared_at', 'ready_notified_at']);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'contact_email', 'relevance_score', 'relevance_reasons', 'follow_up_draft_id', 'follow_up_drafted_at',
                'reminder_sent_at', 'interview_prep', 'interview_prep_at',
            ]);
        });
    }
};
