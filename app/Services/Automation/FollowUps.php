<?php

namespace App\Services\Automation;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Prospects\ProspectResource;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\Prospect;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Relances automatiques : à la date de relance, un BROUILLON est créé dans Gmail (dans le fil
 * de la candidature envoyée). Il n'est jamais envoyé par le tableau de bord : Pierre le relit et
 * l'envoie lui-même depuis Gmail. Sans réponse après le délai de rappel, une notification le signale.
 */
class FollowUps
{
    public const DEFAULT_TEMPLATE = "Madame, Monsieur,\n\n"
        .'Le {date_envoi}, je vous ai adressé ma candidature au poste de {poste}. '
        ."Je me permets de revenir vers vous, car ce poste m'intéresse toujours vivement.\n\n"
        ."Je reste volontiers à votre disposition pour un entretien ou pour tout complément d'information.\n\n"
        ."Avec mes meilleures salutations,\n\n{signature}";

    public function __construct(private Gmail $gmail) {}

    /**
     * @return array{drafts: int, todo: int, reminders: int, prospects: int, errors: list<string>}
     */
    public function run(): array
    {
        $result = ['drafts' => 0, 'todo' => 0, 'reminders' => 0, 'prospects' => 0, 'errors' => []];

        // 1. Relances arrivées à échéance.
        Opportunity::query()
            ->where('status', OpportunityStatus::Applied->value)
            ->whereNotNull('follow_up_at')
            ->whereDate('follow_up_at', '<=', today())
            ->whereNull('follow_up_drafted_at')
            ->with('application')
            ->each(function (Opportunity $o) use (&$result) {
                try {
                    $draftId = $this->draft($o);
                } catch (Throwable $e) {
                    $result['errors'][] = "{$o->company} : {$e->getMessage()}";
                    Log::warning('Brouillon de relance impossible', ['opportunity' => $o->id, 'erreur' => $e->getMessage()]);

                    return; // nouvel essai au prochain passage
                }

                $o->forceFill(['follow_up_draft_id' => $draftId, 'follow_up_drafted_at' => now()])->saveQuietly();
                $url = OpportunityResource::getUrl('view', ['record' => $o]);

                if ($draftId) {
                    $o->events()->create(['type' => 'note', 'body' => 'Brouillon de relance créé dans Gmail (à relire et envoyer depuis Gmail).', 'happened_at' => now()]);
                    $result['drafts']++;
                    NotificationCenter::notify(
                        'relance',
                        "Relance prête dans Gmail : {$o->company}",
                        "{$o->title} – candidature envoyée le {$o->applied_at?->format('d.m.Y')}, toujours sans réponse.\n\n"
                            .'Le brouillon de relance t\'attend dans Gmail : relis-le et envoie-le toi-même. Puis clique sur « Marquer relancé » (ou laisse le tableau de bord le repérer).',
                        ['Ouvrir les brouillons Gmail' => 'https://mail.google.com/mail/u/0/#drafts', 'Ouvrir la fiche' => $url],
                    );
                } else {
                    $result['todo']++;
                    NotificationCenter::notify(
                        'relance',
                        "Relance à faire : {$o->company}",
                        "{$o->title} – candidature envoyée le {$o->applied_at?->format('d.m.Y')}, toujours sans réponse.\n\n"
                            .'Pas d\'adresse e-mail connue (dépôt sur une plateforme) : relance par téléphone, LinkedIn ou via la plateforme, puis clique sur « Marquer relancé ».',
                        ['Ouvrir la fiche' => $url],
                    );
                }
            });

        // 2. Rappel « toujours sans réponse » après la relance.
        $reminderDays = max(1, (int) config('dashboard.reminder_days', 21));
        Opportunity::query()
            ->whereIn('status', [OpportunityStatus::Applied->value, OpportunityStatus::FollowedUp->value])
            ->whereNull('reminder_sent_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', OpportunityStatus::FollowedUp->value)->whereDate('followed_up_at', '<=', today()->subDays($reminderDays)))
                ->orWhere(fn ($q) => $q->where('status', OpportunityStatus::Applied->value)->where('follow_up_drafted_at', '<=', now()->subDays($reminderDays))))
            ->each(function (Opportunity $o) use (&$result, $reminderDays) {
                $o->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $result['reminders']++;
                NotificationCenter::notify(
                    'relance',
                    "Toujours sans réponse : {$o->company}",
                    "{$o->title} – aucune réponse {$reminderDays} jours après la relance. "
                        .'Tu peux tenter un dernier contact (téléphone, LinkedIn) ou classer la candidature en « Refus ».',
                    ['Ouvrir la fiche' => OpportunityResource::getUrl('view', ['record' => $o])],
                );
            });

        // 3. Candidatures spontanées suivies seulement dans l'onglet Spontanées.
        Prospect::query()->followUpDue()->whereNull('follow_up_notified_at')->whereNull('opportunity_id')
            ->each(function (Prospect $p) use (&$result) {
                $p->forceFill(['follow_up_notified_at' => now()])->saveQuietly();
                $result['prospects']++;
                NotificationCenter::notify(
                    'relance',
                    "Relance à faire : {$p->company} (spontanée)",
                    'Candidature spontanée envoyée le '.$p->sent_at?->format('d.m.Y').', sans réponse depuis 10 jours ouvrables.',
                    ['Ouvrir la fiche' => ProspectResource::getUrl('view', ['record' => $p])],
                );
            });

        return $result;
    }

    /**
     * Crée le brouillon de relance. Retourne null si la candidature n'est pas partie par e-mail.
     */
    public function draft(Opportunity $o): ?string
    {
        $application = $o->application;

        if (! config('dashboard.follow_up_drafts', true) || ! $application?->isSent() || blank($application->to_email) || ! GoogleClient::isConnected()) {
            return null;
        }

        $from = GoogleConnection::current()->email;
        $subject = (string) $application->subject;
        if (! preg_match('/^(re|tr|fw|aw)\s*:/i', $subject)) {
            $subject = 'Re: '.$subject;
        }

        $email = (new Email)
            ->from(new Address($from, (string) (NotificationCenter::owner()?->name ?? '')))
            ->to($application->to_email)
            ->subject($subject)
            ->text(self::body($o));

        if ($cc = $application->ccList()) {
            $email->cc(...$cc);
        }

        return $this->gmail->createDraft($email->toString(), $application->gmail_thread_id);
    }

    public static function body(Opportunity $o): string
    {
        $template = trim((string) config('dashboard.follow_up_template')) ?: self::DEFAULT_TEMPLATE;

        return trim(strtr($template, [
            '{poste}' => (string) $o->title,
            '{entreprise}' => (string) $o->company,
            '{date_envoi}' => $o->applied_at?->locale('fr')->isoFormat('D MMMM YYYY') ?? 'récemment',
            '{signature}' => trim((string) config('dashboard.signature')),
        ]));
    }
}
