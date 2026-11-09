<?php

namespace App\Filament\Resources\Opportunities;

use App\Filament\Resources\Opportunities\Pages\CreateOpportunity;
use App\Filament\Resources\Opportunities\Pages\EditOpportunity;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Filament\Resources\Opportunities\Schemas\OpportunityForm;
use App\Filament\Resources\Opportunities\Schemas\OpportunityInfolist;
use App\Filament\Resources\Opportunities\Tables\OpportunitiesTable;
use App\Models\Opportunity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'opportunité';

    protected static ?string $pluralModelLabel = 'opportunités';

    protected static ?string $navigationLabel = 'Opportunités';

    protected static string|UnitEnum|null $navigationGroup = 'Candidatures';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'opportunites';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return OpportunityForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OpportunityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OpportunitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $due = Opportunity::query()->followUpDue()->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Relances à faire';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'company', 'location'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return ['Entreprise' => $record->company, 'Statut' => $record->status->getLabel()];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpportunities::route('/'),
            'create' => CreateOpportunity::route('/create'),
            'view' => ViewOpportunity::route('/{record}'),
            'edit' => EditOpportunity::route('/{record}/edit'),
        ];
    }
}
