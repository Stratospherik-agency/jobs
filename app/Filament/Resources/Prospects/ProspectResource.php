<?php

namespace App\Filament\Resources\Prospects;

use App\Filament\Resources\Prospects\Pages\CreateProspect;
use App\Filament\Resources\Prospects\Pages\EditProspect;
use App\Filament\Resources\Prospects\Pages\ListProspects;
use App\Filament\Resources\Prospects\Pages\ViewProspect;
use App\Filament\Resources\Prospects\Schemas\ProspectForm;
use App\Filament\Resources\Prospects\Schemas\ProspectInfolist;
use App\Filament\Resources\Prospects\Tables\ProspectsTable;
use App\Models\Prospect;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProspectResource extends Resource
{
    protected static ?string $model = Prospect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'entreprise cible';

    protected static ?string $pluralModelLabel = 'candidatures spontanées';

    protected static ?string $navigationLabel = 'Candidatures spontanées';

    protected static string|UnitEnum|null $navigationGroup = 'Candidatures';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'candidatures-spontanees';

    protected static ?string $recordTitleAttribute = 'company';

    public static function getBreadcrumb(): string
    {
        return 'Candidatures spontanées';
    }

    public static function form(Schema $schema): Schema
    {
        return ProspectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProspectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProspectsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Prospect::query()->followUpDue()->count();

        return $count > 0 ? (string) $count : null;
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
        return ['company', 'sector', 'locality'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProspects::route('/'),
            'create' => CreateProspect::route('/create'),
            'view' => ViewProspect::route('/{record}'),
            'edit' => EditProspect::route('/{record}/edit'),
        ];
    }
}
