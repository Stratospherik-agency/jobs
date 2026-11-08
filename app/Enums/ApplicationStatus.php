<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ApplicationStatus: string implements HasColor, HasLabel
{
    case Draft = 'brouillon';
    case ToValidate = 'a_valider';
    case Sent = 'envoyee';
    case Failed = 'echec';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::ToValidate => 'À valider',
            self::Sent => 'Envoyée',
            self::Failed => 'Échec d\'envoi',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ToValidate => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
