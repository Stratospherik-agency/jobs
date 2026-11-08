<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Opportunity;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleException;
use Illuminate\Support\Str;

/**
 * Reprend un brouillon Gmail (préparé par Claude ou par toi) dans la candidature d'une opportunité :
 * destinataire, objet, texte et pièces jointes. Le brouillon est supprimé de Gmail après l'envoi.
 */
class ApplicationDraftImporter
{
    public function __construct(private Gmail $gmail) {}

    public function import(Opportunity $opportunity, string $draftId): Application
    {
        $draft = $this->gmail->getDraft($draftId);
        $application = $opportunity->application ?? $opportunity->application()->make();

        if ($application->isSent()) {
            throw new GoogleException('La candidature a déjà été envoyée.');
        }

        $application->fill([
            'to_email' => $this->firstAddress($draft['to']),
            'cc' => $this->addresses($draft['cc']),
            'subject' => $draft['subject'],
            'body' => $draft['body'],
            'source_draft_id' => $draftId,
            'status' => ApplicationStatus::ToValidate,
            'last_error' => null,
        ])->save();

        if ($draft['attachments']) {
            $application->attachments->each->delete();

            foreach ($draft['attachments'] as $file) {
                $path = 'candidatures/'.$application->id.'/'.Str::random(12).'-'.Str::slug(pathinfo($file['name'], PATHINFO_FILENAME)).'.'.strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin');
                Application::attachmentDisk()->put($path, $file['data']);
                $application->attachments()->create([
                    'name' => $file['name'],
                    'path' => $path,
                    'mime' => $file['mime'],
                    'size' => strlen($file['data']),
                ]);
            }
        }

        $opportunity->events()->create([
            'type' => 'note',
            'body' => 'Brouillon Gmail importé : « '.$draft['subject'].' »',
            'happened_at' => now(),
        ]);

        return $application->fresh('attachments');
    }

    private function firstAddress(string $header): ?string
    {
        return collect($this->parse($header))->first();
    }

    private function addresses(string $header): ?string
    {
        return implode(', ', $this->parse($header)) ?: null;
    }

    /** @return list<string> */
    private function parse(string $header): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $header, $matches);

        return array_values(array_unique($matches[0]));
    }
}
