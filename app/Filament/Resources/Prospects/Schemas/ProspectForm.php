<?php

namespace App\Filament\Resources\Prospects\Schemas;

use App\Enums\ProspectStatus;
use App\Support\Catalog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProspectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Entreprise')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('company')
                            ->label('Entreprise')
                            ->required()
                            ->maxLength(255),
                        Select::make('family')->label('Famille')->options(Catalog::PROSPECT_FAMILIES),
                        Select::make('sector')->label('Secteur')->options(array_combine(Catalog::SECTORS, Catalog::SECTORS))->searchable(),
                        TextInput::make('locality')->label('Localité'),
                        TextInput::make('website')->label('Site web')->url()->maxLength(500),
                        TextInput::make('employees')->label('Employés')->placeholder('Environ 120'),
                        Select::make('relevance')->label('Note (1-3)')->options([3 => '3 – excellente cible', 2 => '2 – bonne cible', 1 => '1 – à creuser']),
                        Select::make('language')->label('Langue')->options(Catalog::LANGUAGES),
                    ]),
                Section::make('Pourquoi la viser')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('reasons')->label('Raisons')->rows(4)->columnSpanFull(),
                        Textarea::make('contributions')->label('Pistes d\'apport')->rows(4)->columnSpanFull()
                            ->helperText('Ce que tu peux apporter concrètement : sert de base à la lettre.'),
                        Textarea::make('signal')->label('Signal (actualité, recrutement, projet…)')->rows(2)->columnSpanFull(),
                        DatePicker::make('signal_date')->label('Date du signal'),
                        TextInput::make('signal_source')->label('Source du signal')->maxLength(500),
                    ]),
                Section::make('Contact')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('contact_name')->label('Contact'),
                        TextInput::make('contact_role')->label('Fonction'),
                        TextInput::make('contact_email')->label('E-mail')->email(),
                        TextInput::make('contact_linkedin')->label('LinkedIn')->url()->maxLength(500),
                        Select::make('channel')->label('Canal')->options(Catalog::PROSPECT_CHANNELS),
                        Select::make('ats')->label('Passe par un ATS')->options(Catalog::ATS),
                        TextInput::make('ats_system')->label('Système ATS')->datalist(Catalog::ATS_SYSTEMS),
                        TextInput::make('ats_score')->label('Score ATS (%)')->integer()->minValue(0)->maxValue(100),
                        Textarea::make('ats_missing_keywords')->label('Mots-clés manquants')->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Suivi')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('status')->label('Statut')->options(ProspectStatus::class)->default(ProspectStatus::ToQualify)->required(),
                        DatePicker::make('sent_at')->label('Date d\'envoi'),
                        DatePicker::make('follow_up_at')->label('Relance prévue')->helperText('Calculée : envoi + 10 jours ouvrables.'),
                        DatePicker::make('followed_up_at')->label('Relancée le'),
                        DatePicker::make('response_at')->label('Date de réponse'),
                        Select::make('response_type')->label('Type de réponse')->options(Catalog::PROSPECT_RESPONSES),
                        DatePicker::make('recontact_at')->label('Recontacter dès le')->helperText('Calculée : refus + 6 mois.'),
                        Textarea::make('documents')->label('Documents')->rows(2)->columnSpan(2),
                        Textarea::make('notes')->label('Notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
