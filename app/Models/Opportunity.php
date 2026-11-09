<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
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
            }
        });
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
