<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statuts d'une opportunité, identiques à la liste déroulante du registre Google Sheets.
 */
enum OpportunityStatus: string implements HasColor, HasLabel
{
    case ToEvaluate = 'a_evaluer';
    case ToApply = 'a_postuler';
    case Applied = 'envoyee';
    case FollowedUp = 'relance';
    case Interview = 'entretien';
    case Rejected = 'refus';
    case OfferReceived = 'offre';
    case Discarded = 'ecartee';

    public function getLabel(): string
    {
        return match ($this) {
            self::ToEvaluate => 'À évaluer',
            self::ToApply => 'À postuler',
            self::Applied => 'Candidature envoyée',
            self::FollowedUp => 'Relancé',
            self::Interview => 'Entretien',
            self::Rejected => 'Refus',
            self::OfferReceived => 'Offre reçue',
            self::Discarded => 'Écartée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ToEvaluate => 'gray',
            self::ToApply => 'info',
            self::Applied, self::FollowedUp => 'warning',
            self::Interview, self::OfferReceived => 'success',
            self::Rejected, self::Discarded => 'danger',
        };
    }

    /** Candidature partie, en attente d'une réponse. */
    public function isAwaitingAnswer(): bool
    {
        return in_array($this, [self::Applied, self::FollowedUp], true);
    }

    /** Dossier clos. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Discarded, self::OfferReceived], true);
    }

    /** Retrouve un statut depuis son libellé (import du registre Sheets). */
    public static function fromLabel(?string $label): ?self
    {
        $needle = mb_strtolower(trim((string) $label));

        foreach (self::cases() as $case) {
            if (mb_strtolower($case->getLabel()) === $needle) {
                return $case;
            }
        }

        return null;
    }
}
