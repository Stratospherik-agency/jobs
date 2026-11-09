<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Services\Automation\InterviewPrep;
use App\Services\Automation\NotificationCenter;
use App\Services\Automation\RelevanceScorer;
use App\Support\Catalog;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    /** Délai par défaut avant relance, en jours. */
    public static function followUpDays(): int
    {
        return max(1, (int) config('dashboard.follow_up_days', 10));
    }

    protected $fillable = [
        'company', 'title', 'location', 'rate', 'source', 'url', 'published_at', 'relevance',
        'status', 'applied_at', 'follow_up_at', 'feedback', 'notes', 'added_at',
        'sector', 'company_size', 'company_address', 'seniority', 'job_family', 'contract_type',
        'rate_min', 'rate_max', 'remote', 'language', 'salary_min', 'salary_max',
        'followed_up_at', 'first_response_at', 'response_type', 'refusal_reason', 'interview_at',
        'ats', 'ats_system', 'ats_score', 'ats_missing_keywords',
        'documents_url', 'cover_letter_text', 'message_subject', 'message_body', 'documents_links',
        'contact_email', 'relevance_score', 'relevance_reasons', 'follow_up_draft_id', 'follow_up_drafted_at',
        'reminder_sent_at', 'interview_prep', 'interview_prep_at',
    ];

    /** Champs qui influencent le score de pertinence. */
    private const SCORED = [
        'company', 'title', 'job_family', 'location', 'company_address', 'rate', 'remote', 'sector',
        'seniority', 'contract_type', 'ats_score',
    ];

    protected $attributes = [
        'status' => 'a_evaluer',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpportunityStatus::class,
            'published_at' => 'date',
            'applied_at' => 'date',
            'follow_up_at' => 'date',
            'added_at' => 'date',
            'relevance' => 'integer',
            'registry_snapshot' => 'array',
            'followed_up_at' => 'date',
            'first_response_at' => 'date',
            'interview_at' => 'date',
            'rate_min' => 'integer',
            'rate_max' => 'integer',
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'ats_score' => 'integer',
            'relevance_score' => 'integer',
            'relevance_reasons' => 'array',
            'follow_up_drafted_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'interview_prep_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Opportunity $opportunity) {
            $opportunity->added_at ??= today();
        });

        static::saving(function (Opportunity $opportunity) {
            // Candidature envoyée : date d'envoi et relance prévue remplies automatiquement.
            if ($opportunity->status === OpportunityStatus::Applied) {
                $opportunity->applied_at ??= today();
                $opportunity->follow_up_at ??= $opportunity->applied_at->copy()->addDays(self::followUpDays());
            }

            // Une fois relancé, la prochaine relance est repoussée.
            if ($opportunity->isDirty('status') && $opportunity->status === OpportunityStatus::FollowedUp) {
                $opportunity->follow_up_at = today()->addDays(self::followUpDays());
            }

            // Système ATS deviné depuis le lien de l'annonce (Workday, SuccessFactors…).
            if (blank($opportunity->ats_system) && ($system = Catalog::atsFromUrl($opportunity->url))) {
                $opportunity->ats_system = $system;
                $opportunity->ats ??= 'oui';
            }

            // Taux et contrat déduits du champ libre « Taux / contrat ».
            if ($opportunity->isDirty('rate')) {
                [$opportunity->rate_min, $opportunity->rate_max] = Catalog::parseRate($opportunity->rate);
                $opportunity->contract_type ??= Catalog::contractFromText($opportunity->rate);
            }

            // Dates clés du déroulé, pour les statistiques.
            if ($opportunity->isDirty('status')) {
                match ($opportunity->status) {
                    OpportunityStatus::FollowedUp => $opportunity->followed_up_at ??= today(),
                    OpportunityStatus::Interview, OpportunityStatus::OfferReceived => (function () use ($opportunity) {
                        $opportunity->interview_at ??= today();
                        $opportunity->first_response_at ??= today();
                        $opportunity->response_type ??= 'positive';
                    })(),
                    OpportunityStatus::Rejected => (function () use ($opportunity) {
                        if ($opportunity->applied_at) {
                            $opportunity->first_response_at ??= today();
                            $opportunity->response_type ??= 'refus';
                        }
                    })(),
                    default => null,
                };
            }

            // Dossier clos : plus de relance.
            if ($opportunity->status?->isClosed()) {
                $opportunity->follow_up_at = null;
            }

            // Nouvelle date de relance : un nouveau brouillon et un nouveau rappel seront proposés.
            if ($opportunity->exists && $opportunity->isDirty('follow_up_at')) {
                $opportunity->follow_up_draft_id = null;
                $opportunity->follow_up_drafted_at = null;
                $opportunity->reminder_sent_at = null;
            }

            // Score de pertinence sur 100, recalculé quand un critère change.
            if ($opportunity->relevance_score === null || $opportunity->isDirty(self::SCORED)) {
                RelevanceScorer::refresh($opportunity);
            }
        });

        // Supprime aussi les pièces jointes stockées sur le serveur.
        static::deleting(function (Opportunity $opportunity) {
            $opportunity->application?->delete();
        });

        static::created(function (Opportunity $opportunity) {
            $opportunity->events()->create([
                'type' => 'statut',
                'to_status' => $opportunity->status->value,
                'body' => 'Opportunité ajoutée',
                'happened_at' => now(),
            ]);

            if ($opportunity->status === OpportunityStatus::Interview) {
                rescue(fn () => InterviewPrep::prepare($opportunity), report: true);
            }

            if ($opportunity->isTopMatch() && ! $opportunity->status->isClosed()) {
                rescue(fn () => NotificationCenter::notify(
                    'offre_pertinente',
                    "Offre très pertinente : {$opportunity->company}",
                    "{$opportunity->title} – score {$opportunity->relevance_score}/100.",
                    ['Ouvrir la fiche' => OpportunityResource::getUrl('view', ['record' => $opportunity])],
                ), report: true);
            }
        });

        static::updated(function (Opportunity $opportunity) {
            if ($opportunity->wasChanged('status')) {
                $from = $opportunity->getOriginal('status');
                $opportunity->events()->create([
                    'type' => 'statut',
                    'from_status' => $from instanceof OpportunityStatus ? $from->value : $from,
                    'to_status' => $opportunity->status->value,
                    'happened_at' => now(),
                ]);

                // Candidature spontanée : la cible suit l'avancement.
                Prospect::mirror($opportunity);

                // Annonce ignorée : le score des autres offres en tient compte.
                if ($opportunity->status === OpportunityStatus::Discarded) {
                    RelevanceScorer::forget();
                }

                // Entretien fixé : fiche de préparation et notification.
                if ($opportunity->status === OpportunityStatus::Interview && blank($opportunity->interview_prep)) {
                    rescue(fn () => InterviewPrep::prepare($opportunity), report: true);
                }
            }
        });
    }

    /** Offre assez pertinente pour que sa candidature soit préparée d'office. */
    public function isTopMatch(): bool
    {
        return ($this->relevance_score !== null && $this->relevance_score >= (int) config('dashboard.auto_prepare_min_score', 75))
            || (config('dashboard.auto_prepare_top_rated', true) && $this->relevance === 3);
    }

    /** Offre ou candidature spontanée liée. */
    public function isSpontaneous(): bool
    {
        return $this->prospect()->exists();
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    /** Entreprise cible, pour une candidature spontanée. */
    public function prospect(): HasOne
    {
        return $this->hasOne(Prospect::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OpportunityEvent::class)->orderByDesc('happened_at')->orderByDesc('id');
    }

    /** Candidatures en attente dont la date de relance est atteinte. */
    public function scopeFollowUpDue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [OpportunityStatus::Applied->value, OpportunityStatus::FollowedUp->value])
            ->whereNotNull('follow_up_at')
            ->whereDate('follow_up_at', '<=', today());
    }
}
