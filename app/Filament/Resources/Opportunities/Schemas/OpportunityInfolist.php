<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Filament\Resources\Opportunities\Actions\ApplicationActions;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

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
                Section::make('Documents de candidature')
                    ->icon('heroicon-o-document-text')
                    ->description('Préparés pour cette offre. Les PDF arrivent dans la section Candidature ci-dessous.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Opportunity $record) => filled($record->documents_url) || filled($record->cover_letter_text) || filled($record->message_body))
                    ->schema([
                        TextEntry::make('documents_url')
                            ->label('Dossier des documents')
                            ->formatStateUsing(fn () => 'Ouvrir le dossier Google Drive')
                            ->url(fn (Opportunity $record) => $record->documents_url, shouldOpenInNewTab: true)
                            ->icon('heroicon-o-folder-open')
                            ->visible(fn (Opportunity $record) => filled($record->documents_url)),
                        self::copyBlock('cover_letter_text', 'Lettre de motivation (texte à copier)', 'Lettre copiée'),
                        TextEntry::make('message_subject')->label('Objet')->copyable()->copyMessage('Objet copié')
                            ->visible(fn (Opportunity $record) => filled($record->message_subject)),
                        self::copyBlock('message_body', 'Message d\'accompagnement', 'Message copié'),
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

    /**
     * Bloc de texte lisible, avec un bouton « Copier » pour le coller sur une plateforme.
     */
    private static function copyBlock(string $field, string $label, string $done): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->visible(fn (Opportunity $record) => filled($record->{$field}))
            ->formatStateUsing(fn (?string $state) => new HtmlString('<div style="white-space:pre-wrap;line-height:1.6">'.e((string) $state).'</div>'))
            ->extraAttributes(['class' => 'rounded-lg border border-gray-200 p-4 dark:border-white/10'])
            ->hintAction(
                Action::make('copy_'.$field)
                    ->label('Copier')
                    ->icon('heroicon-o-clipboard-document')
                    ->alpineClickHandler(fn (Opportunity $record) => 'window.navigator.clipboard.writeText('.Js::from((string) $record->{$field}).')'
                        .'.then(() => new FilamentNotification().title('.Js::from($done).').success().send())'
                        .'.catch(() => new FilamentNotification().title(\'Copie impossible : sélectionne le texte à la main.\').danger().send())')
            );
    }
}
