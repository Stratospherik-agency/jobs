<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use App\Enums\ProspectStatus;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entreprise cible d'une candidature spontanée.
 */
class Prospect extends Model
{
    use HasFactory;

    /** Relance : 10 jours ouvrables après l'envoi. */
    public const FOLLOW_UP_WORKING_DAYS = 10;

    /** Sans réponse 3 semaines après la relance : la candidature est classée. */
    public const NO_ANSWER_AFTER_DAYS = 21;

    /** Après un refus, nouvelle prise de contact possible au bout de 6 mois. */
    public const RECONTACT_AFTER_MONTHS = 6;

    protected $fillable = [
        'added_at', 'company', 'family', 'sector', 'locality', 'website', 'employees', 'signal', 'signal_date',
        'signal_source', 'reasons', 'contributions', 'contact_name', 'contact_role', 'contact_email',
        'contact_linkedin', 'channel', 'relevance', 'language', 'status', 'sent_at', 'follow_up_at',
        'followed_up_at', 'response_at', 'response_type', 'recontact_at', 'documents', 'notes',
        'ats', 'ats_system', 'ats_score', 'ats_missing_keywords',
    ];

    protected $attributes = [
        'status' => 'a_qualifier',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProspectStatus::class,
            'added_at' => 'date',
            'signal_date' => 'date',
            'sent_at' => 'date',
            'follow_up_at' => 'date',
            'followed_up_at' => 'date',
            'response_at' => 'date',
            'recontact_at' => 'date',
            'relevance' => 'integer',
            'ats_score' => 'integer',
            'registry_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Prospect $prospect) {
            $prospect->added_at ??= today();

            if ($prospect->isDirty('status')) {
                match ($prospect->status) {
                    ProspectStatus::Sent => $prospect->sent_at ??= today(),
                    ProspectStatus::FollowedUp => $prospect->followed_up_at ??= today(),
                    ProspectStatus::Interview => (function () use ($prospect) {
                        $prospect->response_at ??= today();
                        $prospect->response_type ??= 'entretien';
                    })(),
                    ProspectStatus::Rejected => (function () use ($prospect) {
                        $prospect->response_at ??= today();
                        $prospect->response_type ??= 'refus';
                    })(),
                    ProspectStatus::NoAnswer => $prospect->response_type ??= 'sans_reponse',
                    default => null,
                };
            }

            // Relance prévue 10 jours ouvrables après l'envoi.
            if ($prospect->sent_at && $prospect->status === ProspectStatus::Sent && ($prospect->follow_up_at === null || $prospect->isDirty('sent_at'))) {
                $prospect->follow_up_at = $prospect->sent_at->copy()->addWeekdays(self::FOLLOW_UP_WORKING_DAYS);
            }

            // Refus : on pourra recontacter dans 6 mois.
            if ($prospect->response_type === 'refus' && $prospect->response_at) {
                $prospect->recontact_at ??= $prospect->response_at->copy()->addMonths(self::RECONTACT_AFTER_MONTHS);
            }

            if (! $prospect->status->isAwaitingAnswer()) {
                $prospect->follow_up_at = null;
            }
        });
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function isWebAgency(): bool
    {
        return Catalog::isWebAgency($this->company, $this->sector);
    }

    /** Jours entre l'envoi et la réponse. */
    public function responseDays(): ?int
    {
        return $this->sent_at && $this->response_at && $this->response_type !== 'sans_reponse'
            ? (int) $this->sent_at->diffInDays($this->response_at)
            : null;
    }

    public function responded(): bool
    {
        return $this->status->isSent() && in_array($this->response_type, ['entretien', 'refus', 'vivier'], true);
    }

    public function interviewed(): bool
    {
        return $this->status === ProspectStatus::Interview || $this->response_type === 'entretien';
    }

    /** Relances à faire. */
    public function scopeFollowUpDue(Builder $query): Builder
    {
        return $query->where('status', ProspectStatus::Sent->value)
            ->whereNotNull('follow_up_at')
            ->whereDate('follow_up_at', '<=', today());
    }

    /**
     * Reporte sur la cible l'avancement de la candidature préparée dans le tableau de bord.
     */
    public static function mirror(Opportunity $opportunity): void
    {
        $prospect = static::query()->where('opportunity_id', $opportunity->id)->first();

        if (! $prospect) {
            return;
        }

        $status = match ($opportunity->status) {
            OpportunityStatus::Applied => ProspectStatus::Sent,
            OpportunityStatus::FollowedUp => ProspectStatus::FollowedUp,
            OpportunityStatus::Interview, OpportunityStatus::OfferReceived => ProspectStatus::Interview,
            OpportunityStatus::Rejected => ProspectStatus::Rejected,
            OpportunityStatus::Discarded => ProspectStatus::Discarded,
            default => null,
        };

        if ($status && $status !== $prospect->status) {
            $prospect->status = $status;
            if ($status === ProspectStatus::Sent) {
                $prospect->sent_at = $opportunity->applied_at ?? today();
            }
            $prospect->save();
        }
    }

    /**
     * Tâche quotidienne : classe les candidatures restées sans réponse 3 semaines après la relance,
     * et remet à recontacter les refus de plus de 6 mois.
     *
     * @return array{no_answer: int, recontact: int}
     */
    public static function housekeeping(): array
    {
        $noAnswer = 0;
        static::query()->where('status', ProspectStatus::FollowedUp->value)
            ->whereDate('followed_up_at', '<=', today()->subDays(self::NO_ANSWER_AFTER_DAYS))
            ->each(function (Prospect $p) use (&$noAnswer) {
                $p->update(['status' => ProspectStatus::NoAnswer]);
                $noAnswer++;
            });

        $recontact = 0;
        static::query()->where('status', ProspectStatus::Rejected->value)
            ->whereNotNull('recontact_at')
            ->whereDate('recontact_at', '<=', today())
            ->each(function (Prospect $p) use (&$recontact) {
                $p->update(['status' => ProspectStatus::Recontact]);
                $recontact++;
            });

        return ['no_answer' => $noAnswer, 'recontact' => $recontact];
    }
}
