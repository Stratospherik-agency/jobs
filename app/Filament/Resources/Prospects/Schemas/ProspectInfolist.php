<?php

namespace App\Filament\Resources\Prospects\Schemas;

use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Prospect;
use App\Support\Catalog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProspectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Pourquoi la viser')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('reasons')->label('Raisons')->placeholder('—')->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                        TextEntry::make('contributions')->label('Pistes d\'apport')->placeholder('—')->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                        TextEntry::make('signal')->label('Signal')->placeholder('—')
                            ->helperText(fn (Prospect $record) => collect([$record->signal_date?->format('d.m.Y'), $record->signal_source])->filter()->implode(' · ') ?: null),
                        TextEntry::make('notes')->label('Notes')->placeholder('—')->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                    ]),
                Section::make('Suivi')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('relevance')->label('Note')->formatStateUsing(fn ($state) => $state.' / 3')->placeholder('—'),
                        TextEntry::make('opportunity.title')->label('Candidature')
                            ->url(fn (Prospect $record) => $record->opportunity ? OpportunityResource::getUrl('view', ['record' => $record->opportunity]) : null)
                            ->formatStateUsing(fn () => 'Ouvrir la fiche de candidature')
                            ->visible(fn (Prospect $record) => $record->opportunity !== null),
                        TextEntry::make('sent_at')->label('Envoyée le')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('follow_up_at')->label('Relance prévue')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('response_type')->label('Réponse')
                            ->formatStateUsing(fn ($state) => Catalog::PROSPECT_RESPONSES[$state] ?? $state)
                            ->helperText(fn (Prospect $record) => $record->response_at?->format('d.m.Y'))
                            ->placeholder('—'),
                        TextEntry::make('recontact_at')->label('Recontacter dès le')->date('d.m.Y')->placeholder('—'),
                    ]),
                Section::make('Entreprise et contact')
                    ->columnSpanFull()
                    ->columns(4)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('family')->label('Famille')->formatStateUsing(fn ($state) => Catalog::PROSPECT_FAMILIES[$state] ?? $state)->placeholder('—'),
                        TextEntry::make('employees')->label('Employés')->placeholder('—'),
                        TextEntry::make('website')->label('Site web')->url(fn ($state) => $state, shouldOpenInNewTab: true)->limit(40)->placeholder('—'),
                        TextEntry::make('language')->label('Langue')->formatStateUsing(fn ($state) => Catalog::LANGUAGES[$state] ?? $state)->placeholder('—'),
                        TextEntry::make('contact_name')->label('Contact')->helperText(fn (Prospect $record) => $record->contact_role)->placeholder('—'),
                        TextEntry::make('contact_email')->label('E-mail')->placeholder('—'),
                        TextEntry::make('contact_linkedin')->label('LinkedIn')->url(fn ($state) => $state, shouldOpenInNewTab: true)->limit(40)->placeholder('—'),
                        TextEntry::make('channel')->label('Canal')->formatStateUsing(fn ($state) => Catalog::PROSPECT_CHANNELS[$state] ?? $state)->placeholder('—'),
                        TextEntry::make('documents')->label('Documents')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
