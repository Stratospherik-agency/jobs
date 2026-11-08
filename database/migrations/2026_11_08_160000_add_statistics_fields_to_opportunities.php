<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            // Caractéristiques de l'offre (statistiques).
            $table->string('sector')->nullable();
            $table->string('company_size')->nullable();
            $table->string('company_address')->nullable(); // justificatif ORP
            $table->string('seniority')->nullable();
            $table->string('job_family')->nullable();
            $table->string('contract_type')->nullable();
            $table->unsignedTinyInteger('rate_min')->nullable();
            $table->unsignedTinyInteger('rate_max')->nullable();
            $table->string('remote')->nullable();
            $table->string('language', 5)->nullable();
            $table->unsignedInteger('salary_min')->nullable();
            $table->unsignedInteger('salary_max')->nullable();

            // Déroulé de la candidature.
            $table->date('followed_up_at')->nullable();
            $table->date('first_response_at')->nullable();
            $table->string('response_type')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->date('interview_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'sector', 'company_size', 'company_address', 'seniority', 'job_family', 'contract_type',
                'rate_min', 'rate_max', 'remote', 'language', 'salary_min', 'salary_max',
                'followed_up_at', 'first_response_at', 'response_type', 'refusal_reason', 'interview_at',
            ]);
        });
    }
};
