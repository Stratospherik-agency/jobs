<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Filament\Resources\Opportunities\Actions\ApplicationActions;
use App\Models\Opportunity;
use Illuminate\Support\HtmlString;
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
                Section::make('Candidature')
                    ->icon('heroicon-o-envelope')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Opportunity $record) => $record->application !== null)
                    ->schema([
                        TextEntry::make('application.status')->label('État')->badge(),
                        TextEntry::make('application.to_email')->label('Destinataire')->copyable(),
                        TextEntry::make('application.sent_at')->label('Envoyée le')->dateTime('d.m.Y à H:i')->placeholder('Pas encore envoyée'),
                        TextEntry::make('application.subject')->label('Objet')->columnSpan(2),
                        TextEntry::make('application.cc')->label('Copie')->placeholder('—'),
                        TextEntry::make('attachments')
                            ->label('Pièces jointes')
                            ->state(fn (Opportunity $record) => $record->application?->attachments->isEmpty()
                                ? null
                                : new HtmlString($record->application->attachments->map(fn ($a) => '<a class="underline" href="'.e(route('filament.admin.attachments.download', $a)).'">'.e($a->name).'</a>'
                                    .'&nbsp;<span style="opacity:.6">('.ApplicationActions::size($a->size).')</span>'
                                    .($a->drive_file_url ? '&nbsp;·&nbsp;<a class="underline" target="_blank" rel="noopener" href="'.e($a->drive_file_url).'">Drive</a>' : ''))->implode('<br>')))
                            ->placeholder('Aucune')
                            ->columnSpan(2),
                        TextEntry::make('application.drive_folder_url')
                            ->label('Archive Drive')
                            ->formatStateUsing(fn () => 'Ouvrir le dossier')
                            ->url(fn (Opportunity $record) => $record->application?->drive_folder_url, shouldOpenInNewTab: true)
                            ->placeholder('—'),
                        TextEntry::make('application.last_error')
                            ->label('Dernière erreur')
                            ->color('danger')
                            ->visible(fn (Opportunity $record) => filled($record->application?->last_error))
                            ->columnSpanFull(),
                        TextEntry::make('application.body')
                            ->label('Message')
                            ->formatStateUsing(fn (?string $state) => new HtmlString(nl2br(e((string) $state))))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
