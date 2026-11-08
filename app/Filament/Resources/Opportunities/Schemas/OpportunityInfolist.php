<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Models\Opportunity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OpportunityInfolist
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
                        TextEntry::make('company')->label('Entreprise')->weight('bold'),
                        TextEntry::make('title')->label('Poste')->weight('bold'),
                        TextEntry::make('location')->label('Lieu')->placeholder('—'),
                        TextEntry::make('rate')->label('Taux / contrat')->placeholder('—'),
                        TextEntry::make('source')->label('Source')->placeholder('—'),
                        TextEntry::make('published_at')->label('Publiée le')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('url')
                            ->label('Lien de l\'offre')
                            ->url(fn (Opportunity $record) => $record->url, shouldOpenInNewTab: true)
                            ->limit(80)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Suivi')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('relevance')->label('Pertinence')->formatStateUsing(fn ($state) => $state ? $state.' / 3' : null)->placeholder('—'),
                        TextEntry::make('applied_at')->label('Date de candidature')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('follow_up_at')
                            ->label('Relance prévue')
                            ->date('d.m.Y')
                            ->color(fn (Opportunity $record) => $record->follow_up_at?->isPast() ? 'danger' : null)
                            ->placeholder('—'),
                        TextEntry::make('feedback')->label('Retour de l\'entreprise')->placeholder('—'),
                        TextEntry::make('added_at')->label('Ajoutée le')->date('d.m.Y'),
                    ]),
            ]);
    }
}
