<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Clusters\Statistics\StatisticsCluster;
use App\Services\Statistics\OrpReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Justificatif mensuel des recherches d'emploi pour l'ORP (CSV ou version imprimable / PDF).
 */
class OrpProof extends Page
{
    protected static ?string $cluster = StatisticsCluster::class;

    protected static ?string $title = 'Justificatif ORP';

    protected static ?string $slug = 'justificatif-orp';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['month' => array_key_first(OrpReport::months())]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('month')
                ->label('Mois')
                ->options(fn () => OrpReport::months())
                ->selectablePlaceholder(false)
                ->live()
                ->native(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $month = OrpReport::month($this->data['month'] ?? null);
        $rows = OrpReport::rows($month);

        return $schema->components([
            Section::make('Preuves des recherches personnelles')
                ->description('Toutes les candidatures envoyées dans le mois, au format demandé par l\'ORP. Vérifie les adresses avant de remettre le document.')
                ->schema([
                    EmbeddedSchema::make('form'),
                    Text::make(new HtmlString($this->table($rows->all()))),
                ])
                ->footerActions([
                    Action::make('print')
                        ->label('Version imprimable (PDF)')
                        ->icon('heroicon-o-printer')
                        ->url(route('filament.admin.orp.print', ['month' => $month->format('Y-m')]), shouldOpenInNewTab: true),
                    Action::make('csv')
                        ->label('Télécharger pour Excel (CSV)')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->url(route('filament.admin.orp.csv', ['month' => $month->format('Y-m')])),
                ]),
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function table(array $rows): string
    {
        if ($rows === []) {
            return '<p style="color:#6b7280">Aucune candidature envoyée ce mois-ci.</p>';
        }

        $head = implode('', array_map(fn ($l) => '<th style="text-align:left;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.3)">'.e($l).'</th>', OrpReport::COLUMNS));
        $body = implode('', array_map(fn ($row) => '<tr>'.implode('', array_map(
            fn ($v) => '<td style="padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);vertical-align:top">'.e($v).'</td>',
            $row
        )).'</tr>', $rows));

        return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }
}
