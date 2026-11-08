<?php

namespace App\Filament\Widgets;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ApplicationsToValidate extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Candidatures à valider';

    public static function canView(): bool
    {
        return Opportunity::query()->whereHas('application', fn ($q) => $q->whereIn('status', [ApplicationStatus::ToValidate->value, ApplicationStatus::Failed->value]))->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Opportunity::query()
                ->whereHas('application', fn ($q) => $q->whereIn('status', [ApplicationStatus::ToValidate->value, ApplicationStatus::Failed->value]))
                ->with('application'))
            ->paginated(false)
            ->recordUrl(fn (Opportunity $record) => OpportunityResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('company')->label('Entreprise')->weight('bold')->description(fn (Opportunity $record) => $record->title),
                TextColumn::make('application.to_email')->label('Destinataire'),
                TextColumn::make('application.subject')->label('Objet')->limit(50),
                TextColumn::make('application.status')->label('État')->badge(),
            ]);
    }
}
