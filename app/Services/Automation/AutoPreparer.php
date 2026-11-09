<?php

namespace App\Services\Automation;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;

/**
 * Candidature préparée d'office : dès qu'une offre très pertinente (notée 3/3 ou au-dessus du
 * score choisi) a ses documents dans le registre (lettre, message, PDF Canva), la candidature
 * est remplie et passe « À valider ». Rien n'est envoyé : seul le bouton « Valider et envoyer »
 * de la fiche fait partir la candidature.
 */
class AutoPreparer
{
    /**
     * @return array{prepared: int, notified: int}
     */
    public function run(): array
    {
        $result = ['prepared' => 0, 'notified' => 0];

        if (! config('dashboard.auto_prepare', true)) {
            return $result;
        }

        Opportunity::query()
            ->whereIn('status', [OpportunityStatus::ToEvaluate->value, OpportunityStatus::ToApply->value, OpportunityStatus::InPreparation->value])
            ->where(fn ($q) => $q->whereNotNull('message_body')->orWhereNotNull('cover_letter_text')->orWhereNotNull('documents_links'))
            ->with(['application.attachments', 'prospect'])
            ->each(function (Opportunity $opportunity) use (&$result) {
                if (! $opportunity->isTopMatch() || ! $this->hasDocuments($opportunity)) {
                    return;
                }

                $application = $opportunity->application;

                // Une candidature déjà relue ou modifiée à la main n'est jamais réécrite.
                if ($application && $application->status !== ApplicationStatus::Draft) {
                    return;
                }

                $this->prepare($opportunity);
                $result['prepared']++;

                if ($this->notifyReady($opportunity->fresh(['application.attachments']))) {
                    $result['notified']++;
                }
            });

        return $result;
    }

    public function hasDocuments(Opportunity $o): bool
    {
        return filled($o->message_body) || filled($o->cover_letter_text) || $o->application?->attachments->isNotEmpty();
    }

    public function prepare(Opportunity $opportunity): void
    {
        $application = $opportunity->application ?? $opportunity->application()->make();
        $signature = trim((string) config('dashboard.signature'));

        $body = trim((string) $opportunity->message_body);
        if ($body === '') {
            $body = "Madame, Monsieur,\n\nVous trouverez ci-joint ma candidature au poste de {$opportunity->title}"
                ." : CV, lettre de motivation et dossier de candidature.\n\nJe me tiens volontiers à votre disposition pour un entretien.\n\nAvec mes meilleures salutations,";
        }
        if ($signature !== '' && ! str_contains($body, $signature)) {
            $body .= "\n\n".$signature;
        }

        $application->fill([
            'to_email' => $application->to_email ?: ($opportunity->contact_email ?: $opportunity->prospect?->contact_email),
            'subject' => $application->subject ?: ($opportunity->message_subject ?: ($opportunity->prospect ? 'Candidature spontanée' : 'Candidature – '.$opportunity->title)),
            'body' => $body,
            'status' => ApplicationStatus::ToValidate,
            'auto_prepared_at' => now(),
            'last_error' => null,
        ])->save();

        if (in_array($opportunity->status, [OpportunityStatus::ToEvaluate, OpportunityStatus::ToApply], true)) {
            $opportunity->update(['status' => OpportunityStatus::InPreparation]);
        }

        $opportunity->events()->create([
            'type' => 'note',
            'body' => 'Candidature préparée d\'office (score '.($opportunity->relevance_score ?? '—').'/100'
                .($opportunity->relevance ? ', note '.$opportunity->relevance.'/3' : '').') : à relire et valider.',
            'happened_at' => now(),
        ]);
    }

    private function notifyReady(Opportunity $opportunity): bool
    {
        $application = $opportunity->application;

        if (! $application || $application->ready_notified_at) {
            return false;
        }

        $files = $application->attachments->pluck('name')->implode(', ');
        $missing = $application->missingForSending();

        $body = "{$opportunity->title} – score {$opportunity->relevance_score}/100.";
        $body .= "\n\nPièces jointes : ".($files ?: 'aucune pour l\'instant (les PDF Canva arrivent à la prochaine synchronisation).');
        $body .= $missing
            ? "\n\nIl manque ".implode(', ', $missing).' : complète la candidature, ou dépose le kit toi-même sur la plateforme si l\'annonce ne donne pas d\'adresse.'
            : "\n\nIl ne reste qu'à relire et cliquer sur « Valider et envoyer ».";

        NotificationCenter::notify(
            'candidature_prete',
            "Candidature prête à valider : {$opportunity->company}",
            $body,
            ['Relire et valider' => OpportunityResource::getUrl('view', ['record' => $opportunity])],
        );

        $application->forceFill(['ready_notified_at' => now()])->saveQuietly();

        return true;
    }
}
