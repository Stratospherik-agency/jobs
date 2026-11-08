<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->date('added_at')->nullable();
            $table->string('company');
            $table->string('family')->nullable();
            $table->string('sector')->nullable();
            $table->string('locality')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('employees')->nullable();
            $table->text('signal')->nullable();
            $table->date('signal_date')->nullable();
            $table->string('signal_source', 500)->nullable();
            $table->text('reasons')->nullable();
            $table->text('contributions')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_linkedin', 500)->nullable();
            $table->string('channel')->nullable();
            $table->unsignedTinyInteger('relevance')->nullable();
            $table->string('language')->nullable();
            $table->string('status')->default('a_qualifier')->index();
            $table->date('sent_at')->nullable();
            $table->date('follow_up_at')->nullable();
            $table->date('followed_up_at')->nullable();
            $table->date('response_at')->nullable();
            $table->string('response_type')->nullable();
            $table->date('recontact_at')->nullable();
            $table->text('documents')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->json('registry_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
