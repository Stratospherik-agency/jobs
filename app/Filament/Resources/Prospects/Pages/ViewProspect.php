<?php

namespace App\Filament\Resources\Prospects\Pages;

use App\Filament\Resources\Prospects\ProspectActions;
use App\Filament\Resources\Prospects\ProspectResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProspect extends ViewRecord
{
    protected static string $resource = ProspectResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->company;
    }

    public function getSubheading(): ?string
    {
        $line = collect([$this->getRecord()->sector, $this->getRecord()->locality])->filter()->implode(' · ');

        if ($this->getRecord()->isWebAgency()) {
            $line .= ($line ? ' · ' : '').'Agence : à retenir seulement pour un poste de gestion de projets';
        }

        return $line ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ProspectActions::prepare(),
            ProspectActions::select(),
            ProspectActions::discard(),
            EditAction::make()->color('gray'),
        ];
    }
}
