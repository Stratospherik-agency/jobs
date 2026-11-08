<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Models\Application;
use App\Services\Google\Drive;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envoie une candidature validée par le propriétaire, depuis son compte Gmail.
 *
 * Garde-fous : l'envoi n'a lieu que sur une action explicite « Valider et envoyer »,
 * une candidature n'est jamais envoyée deux fois, et un verrou empêche les doubles clics.
 */
class ApplicationSender
{
    public function __construct(private Gmail $gmail, private Drive $drive) {}

    public function send(Application $application): void
    {
        $lock = Cache::lock('application-send-'.$application->id, 60);

        if (! $lock->get()) {
            throw new GoogleException('Un envoi de cette candidature est déjà en cours.');
        }

        try {
            $application->refresh();

            if ($application->isSent()) {
                throw new GoogleException('Cette candidature a déjà été envoyée le '.$application->sent_at->format('d.m.Y à H:i').'.');
            }

            if ($missing = $application->missingForSending()) {
                throw new GoogleException('Il manque '.implode(', ', $missing).'.');
            }

            if (! GoogleClient::isConnected()) {
                throw new GoogleException('Connecte d\'abord ton compte Google (menu Compte Google).');
            }

            $owner = \App\Models\GoogleConnection::current();

            try {
                $sent = $this->gmail->sendRaw($this->buildMime($application, $owner->email));
            } catch (\Throwable $e) {
                $application->update(['status' => ApplicationStatus::Failed, 'last_error' => $e->getMessage()]);

                throw $e instanceof GoogleException ? $e : new GoogleException('Envoi impossible : '.$e->getMessage(), previous: $e);
            }

            $application->update([
                'status' => ApplicationStatus::Sent,
                'validated_at' => now(),
                'sent_at' => now(),
                'gmail_message_id' => $sent['id'],
                'gmail_thread_id' => $sent['threadId'],
                'last_error' => null,
            ]);

            if ($application->source_draft_id) {
                $this->gmail->deleteDraft($application->source_draft_id);
            }

            $opportunity = $application->opportunity;
            $opportunity->events()->create([
                'type' => 'note',
                'body' => "Candidature envoyée à {$application->to_email} : « {$application->subject} »"
                    .($application->attachments->isNotEmpty() ? ' ('.$application->attachments->pluck('name')->implode(', ').')' : ''),
                'happened_at' => now(),
            ]);

            if (! in_array($opportunity->status, [OpportunityStatus::Applied, OpportunityStatus::FollowedUp, OpportunityStatus::Interview], true)) {
                $opportunity->update(['status' => OpportunityStatus::Applied, 'applied_at' => today()]);
            }

            $this->archive($application);
        } finally {
            $lock->release();
        }
    }

    public function buildMime(Application $application, string $from): string
    {
        $email = (new Email)
            ->from(new Address($from, (string) (auth()->user()?->name ?? '')))
            ->to($application->to_email)
            ->subject((string) $application->subject)
            ->text((string) $application->body);

        if ($cc = $application->ccList()) {
            $email->cc(...$cc);
        }

        foreach ($application->attachments as $attachment) {
            $email->attach($attachment->contents(), $attachment->name, $attachment->mime);
        }

        return $email->toString();
    }

    /**
     * Copie le message et les pièces jointes dans Drive. Un échec n'annule pas l'envoi.
     */
    private function archive(Application $application): void
    {
        try {
            $opportunity = $application->opportunity;
            $folder = $this->drive->folderForApplication(
                now()->format('Y-m-d').' – '.$opportunity->company.' – '.$opportunity->title
            );

            $summary = "Envoyée le ".now()->format('d.m.Y à H:i')."\nÀ : {$application->to_email}\n"
                .($application->cc ? "Cc : {$application->cc}\n" : '')
                ."Objet : {$application->subject}\n\n{$application->body}\n";
            $this->drive->upload($folder['id'], 'Message envoyé.txt', 'text/plain', $summary);

            foreach ($application->attachments as $attachment) {
                $file = $this->drive->upload($folder['id'], $attachment->name, $attachment->mime, $attachment->contents());
                $attachment->update(['drive_file_url' => $file['url']]);
            }

            $application->update(['drive_folder_url' => $folder['url']]);
        } catch (\Throwable $e) {
            Log::warning('Archivage Drive impossible', ['application' => $application->id, 'erreur' => $e->getMessage()]);
        }
    }
}
