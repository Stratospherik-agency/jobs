<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Enums\OpportunityStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Offre')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('company')->label('Entreprise')->required()->maxLength(255),
                        TextInput::make('title')->label('Poste')->required()->maxLength(255),
                        TextInput::make('location')->label('Lieu')->maxLength(255),
                        TextInput::make('rate')->label('Taux / contrat')->placeholder('100%, CDI, 50-70%…')->maxLength(255),
                        Select::make('source')
                            ->label('Source')
                            ->options(fn () => self::sources())
                            ->searchable()
                            ->createOptionForm([TextInput::make('source')->label('Nouvelle source')->required()])
                            ->createOptionUsing(fn (array $data) => $data['source']),
                        DatePicker::make('published_at')->label('Publiée le')->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('url')
                            ->label('Lien de l\'offre')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Textarea::make('notes')->label('Notes')->rows(4)->columnSpanFull(),
                    ]),
                Section::make('Suivi')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('status')
                            ->label('Statut')
                            ->options(OpportunityStatus::class)
                            ->default(OpportunityStatus::ToEvaluate)
                            ->required()
                            ->native(false),
                        ToggleButtons::make('relevance')
                            ->label('Pertinence')
                            ->options([1 => '1', 2 => '2', 3 => '3'])
                            ->colors([1 => 'gray', 2 => 'info', 3 => 'success'])
                            ->inline(),
                        DatePicker::make('applied_at')
                            ->label('Date de candidature')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->helperText('Remplie automatiquement au passage à « Candidature envoyée ».'),
                        DatePicker::make('follow_up_at')
                            ->label('Relance prévue')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Textarea::make('feedback')->label('Retour de l\'entreprise')->rows(3),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function sources(): array
    {
        $defaults = ['Indeed', 'LinkedIn', 'jobup', 'jobs.ch', 'Site de l\'entreprise', 'Réseau', 'Candidature spontanée'];
        $used = \App\Models\Opportunity::query()->whereNotNull('source')->distinct()->pluck('source')->all();
        $all = array_values(array_unique(array_merge($defaults, $used)));

        return array_combine($all, $all);
    }
}
