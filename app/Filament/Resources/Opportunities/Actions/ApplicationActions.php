<?php

namespace App\Filament\Resources\Opportunities\Actions;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Application;
use App\Models\Opportunity;
use App\Services\ApplicationDraftImporter;
use App\Services\ApplicationSender;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Préparation, import et envoi validé d'une candidature.
 */
class ApplicationActions
{
    public const ACCEPTED_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    public static function prepare(): Action
    {
        return Action::make('prepare_application')
            ->label(fn (Opportunity $record) => $record->application ? 'Modifier la candidature' : 'Préparer la candidature')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (Opportunity $record) => ! $record->application?->isSent())
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (Opportunity $record) => [
                'to_email' => $record->application?->to_email ?? $record->prospect?->contact_email,
                'cc' => $record->application?->cc,
                'subject' => $record->application?->subject ?? $record->message_subject ?? ($record->prospect ? 'Candidature spontanée' : 'Candidature – '.$record->title),
                'body' => $record->application?->body ?? $record->message_body ?? (filled(config('dashboard.signature')) ? "\n\n".config('dashboard.signature') : null),
                'keep' => $record->application?->attachments->pluck('id')->map(fn ($id) => (string) $id)->all() ?? [],
            ])
            ->schema([
                TextInput::make('to_email')->label('Destinataire')->email()->required()->placeholder('recrutement@entreprise.ch'),
                TextInput::make('cc')->label('Copie (facultatif)')->placeholder('adresse1@…, adresse2@…'),
                TextInput::make('subject')->label('Objet')->required()->maxLength(255),
                Textarea::make('body')->label('Message')->required()->rows(12),
                CheckboxList::make('keep')
                    ->label('Pièces jointes actuelles')
                    ->options(fn (Opportunity $record) => $record->application?->attachments->mapWithKeys(fn ($a) => [(string) $a->id => $a->name.' ('.self::size($a->size).')'])->all() ?? [])
                    ->visible(fn (Opportunity $record) => $record->application?->attachments->isNotEmpty() ?? false)
                    ->helperText('Décoche un fichier pour le retirer.'),
                FileUpload::make('new_files')
                    ->label('Ajouter des pièces jointes')
                    ->helperText('CV, lettre, dossier… PDF de préférence, 10 Mo maximum par fichier.')
                    ->multiple()
                    ->disk('local')
                    ->directory('candidatures/tmp')
                    ->visibility('private')
                    ->acceptedFileTypes(self::ACCEPTED_TYPES)
                    ->maxSize(10240)
                    ->storeFileNamesIn('new_file_names'),
            ])
            ->action(function (Opportunity $record, array $data) {
                $application = $record->application ?? $record->application()->make();
                $application->fill([
                    'to_email' => trim($data['to_email']),
                    'cc' => $data['cc'] ?: null,
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'status' => ApplicationStatus::ToValidate,
                    'last_error' => null,
                ])->save();

                $keep = array_map('intval', $data['keep'] ?? []);
                $application->attachments()->whereNotIn('id', $keep)->get()->each->delete();

                foreach ($data['new_files'] ?? [] as $tmpPath) {
                    $name = $data['new_file_names'][$tmpPath] ?? basename($tmpPath);
                    $final = 'candidatures/'.$application->id.'/'.Str::random(12).'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    Application::attachmentDisk()->move($tmpPath, $final);
                    $application->attachments()->create([
                        'name' => $name,
                        'path' => $final,
                        'mime' => Application::attachmentDisk()->mimeType($final) ?: 'application/octet-stream',
                        'size' => Application::attachmentDisk()->size($final),
                    ]);
                }

                Notification::make()->title('Candidature enregistrée')->body('Elle est prête à être validée.')->success()->send();
            })
            ->after(fn ($livewire) => $livewire->redirect(OpportunityResource::getUrl('view', ['record' => $livewire->getRecord()]), navigate: true));
    }

    public static function importDraft(): Action
    {
        return Action::make('import_draft')
            ->label('Importer un brouillon Gmail')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('gray')
            ->visible(fn (Opportunity $record) => GoogleClient::isConnected() && ! $record->application?->isSent())
            ->modalDescription('Choisis le brouillon préparé dans Gmail (par Claude ou par toi). Destinataire, objet, texte et pièces jointes seront repris ici pour validation.')
            ->modalSubmitActionLabel('Importer')
            ->schema([
                Radio::make('draft')
                    ->label('Brouillons récents')
                    ->required()
                    ->options(function () {
                        try {
                            return collect(app(Gmail::class)->listDrafts())
                                ->mapWithKeys(fn ($d) => [$d['id'] => ($d['subject'] ?: '(sans objet)').' → '.($d['to'] ?: 'sans destinataire')])
                                ->all();
                        } catch (GoogleException $e) {
                            return [];
                        }
                    })
                    ->descriptions(function () {
                        try {
                            return collect(app(Gmail::class)->listDrafts())->mapWithKeys(fn ($d) => [$d['id'] => Str::limit($d['snippet'], 120)])->all();
                        } catch (GoogleException $e) {
                            return [];
                        }
                    }),
            ])
            ->action(function (Opportunity $record, array $data, ApplicationDraftImporter $importer) {
                try {
                    $importer->import($record, $data['draft']);
                    Notification::make()->title('Brouillon importé')->body('Relis la candidature, puis valide l\'envoi.')->success()->send();
                } catch (GoogleException $e) {
                    Notification::make()->title('Import impossible')->body($e->getMessage())->danger()->send();
                }
            })
            ->after(fn ($livewire) => $livewire->redirect(OpportunityResource::getUrl('view', ['record' => $livewire->getRecord()]), navigate: true));
    }

    public static function validateAndSend(): Action
    {
        return Action::make('validate_and_send')
            ->label('Valider et envoyer')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn (Opportunity $record) => $record->application && ! $record->application->isSent())
            ->modalHeading('Valider et envoyer la candidature')
            ->modalDescription('Vérifie une dernière fois : le message partira immédiatement depuis ton Gmail.')
            ->modalSubmitActionLabel('Envoyer maintenant')
            ->modalWidth('3xl')
            ->schema(fn (Opportunity $record) => [
                Text::make(new HtmlString(self::summaryHtml($record->application))),
                Checkbox::make('confirm')
                    ->label('J\'ai relu la candidature et je valide son envoi.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Coche la case pour confirmer l\'envoi.']),
            ])
            ->action(function (Opportunity $record, ApplicationSender $sender, Action $action) {
                $application = $record->application;

                if ($missing = $application->missingForSending()) {
                    Notification::make()->title('Candidature incomplète')->body('Il manque '.implode(', ', $missing).'.')->danger()->send();

                    return;
                }

                try {
                    $sender->send($application);
                } catch (GoogleException $e) {
                    Notification::make()->title('Envoi impossible')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Candidature envoyée')
                    ->body('Envoyée à '.$application->to_email.'. Relance prévue dans '.Opportunity::followUpDays().' jours.')
                    ->success()
                    ->persistent()
                    ->send();
            })
            ->after(fn ($livewire) => $livewire->redirect(OpportunityResource::getUrl('view', ['record' => $livewire->getRecord()]), navigate: true));
    }

    public static function discard(): Action
    {
        return Action::make('discard_application')
            ->label('Supprimer la candidature')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Opportunity $record) => $record->application && ! $record->application->isSent())
            ->requiresConfirmation()
            ->modalDescription('Le brouillon de candidature et ses pièces jointes seront supprimés du tableau de bord.')
            ->action(function (Opportunity $record) {
                $record->application->delete();
                Notification::make()->title('Candidature supprimée')->success()->send();
            })
            ->after(fn ($livewire) => $livewire->redirect(OpportunityResource::getUrl('view', ['record' => $livewire->getRecord()]), navigate: true));
    }

    public static function summaryHtml(Application $application): string
    {
        $e = fn ($v) => e((string) $v);
        $attachments = $application->attachments->isEmpty()
            ? '<em>Aucune</em>'
            : $application->attachments->map(fn ($a) => $e($a->name).' ('.self::size($a->size).')')->implode('<br>');

        return '<dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;font-size:.9rem">'
            .'<dt><strong>À</strong></dt><dd>'.$e($application->to_email).'</dd>'
            .($application->cc ? '<dt><strong>Cc</strong></dt><dd>'.$e($application->cc).'</dd>' : '')
            .'<dt><strong>Objet</strong></dt><dd>'.$e($application->subject).'</dd>'
            .'<dt><strong>Pièces jointes</strong></dt><dd>'.$attachments.'</dd>'
            .'</dl>'
            .'<div style="margin-top:1rem;padding:1rem;border:1px solid rgba(127,127,127,.3);border-radius:.5rem;white-space:pre-wrap;font-size:.9rem;max-height:22rem;overflow:auto">'.$e($application->body).'</div>';
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '').' Mo' : max(1, (int) round($bytes / 1024)).' Ko';
    }
}
