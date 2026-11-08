<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statuts d'une entreprise cible (candidature spontanée), identiques à l'onglet « Spontanées » du registre.
 */
enum ProspectStatus: string implements HasColor, HasLabel
{
    case ToQualify = 'a_qualifier';
    case Selected = 'retenue';
    case InPreparation = 'en_preparation';
    case ToValidate = 'a_valider';
    case Sent = 'envoyee';
    case FollowedUp = 'relancee';
    case Interview = 'entretien';
    case Rejected = 'refus';
    case NoAnswer = 'sans_reponse';
    case Recontact = 'a_recontacter';
    case Discarded = 'ecartee';

    public function getLabel(): string
    {
        return match ($this) {
            self::ToQualify => 'À qualifier',
            self::Selected => 'Retenue',
            self::InPreparation => 'En préparation',
            self::ToValidate => 'À valider',
            self::Sent => 'Envoyée',
            self::FollowedUp => 'Relancée',
            self::Interview => 'Entretien',
            self::Rejected => 'Refus',
            self::NoAnswer => 'Sans réponse',
            self::Recontact => 'À recontacter',
            self::Discarded => 'Écartée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ToQualify => 'gray',
            self::Selected, self::InPreparation, self::ToValidate => 'info',
            self::Sent, self::FollowedUp, self::Recontact => 'warning',
            self::Interview => 'success',
            self::Rejected, self::NoAnswer, self::Discarded => 'danger',
        };
    }

    /** Candidature partie (les statistiques comptent ces cibles comme envois). */
    public function isSent(): bool
    {
        return in_array($this, [self::Sent, self::FollowedUp, self::Interview, self::Rejected, self::NoAnswer, self::Recontact], true);
    }

    public function isAwaitingAnswer(): bool
    {
        return in_array($this, [self::Sent, self::FollowedUp], true);
    }

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
