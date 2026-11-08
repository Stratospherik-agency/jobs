<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    /** Délai par défaut avant relance, en jours. */
    public const FOLLOW_UP_DAYS = 10;

    protected $fillable = [
        'company', 'title', 'location', 'rate', 'source', 'url', 'published_at', 'relevance',
        'status', 'applied_at', 'follow_up_at', 'feedback', 'notes', 'added_at',
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
                $opportunity->follow_up_at ??= $opportunity->applied_at->copy()->addDays(self::FOLLOW_UP_DAYS);
            }

            // Une fois relancé, la prochaine relance est repoussée.
            if ($opportunity->isDirty('status') && $opportunity->status === OpportunityStatus::FollowedUp) {
                $opportunity->follow_up_at = today()->addDays(self::FOLLOW_UP_DAYS);
            }

            // Dossier clos : plus de relance.
            if ($opportunity->status?->isClosed()) {
                $opportunity->follow_up_at = null;
            }
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
            }
        });
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
