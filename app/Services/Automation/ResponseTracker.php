<?php

namespace App\Services\Automation;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\TrackedMessage;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Suivi des réponses : lit uniquement le libellé Gmail « Jobs » et les fils des candidatures
 * envoyées depuis le tableau de bord. Un refus ou une invitation à un entretien met à jour
 * le statut (réglable), un accusé de réception est noté dans l'historique.
 * Ne lit jamais le reste de la boîte (e-mails personnels).
 */
class ResponseTracker
{
    /** Ordre de test : le refus d'abord (il remercie souvent « pour votre candidature »). */
    public const PATTERNS = [
        'refus' => [
            'malheureusement', 'regrettons', 'au regret', 'pas en mesure de donner une suite', 'ne pas donner suite',
            'pas pu retenir', 'pas été retenu', 'pas été retenue', 'n\'avons pas retenu', 'ne correspond pas',
            'autre candidat', 'autre candidature', 'suite défavorable', 'poste a été pourvu', 'unfortunately',
            'not been successful', 'other candidates', 'regret to inform', 'not to proceed', 'will not be moving forward',
            'decided not to', 'leider', 'absage',
        ],
        'entretien' => [
            'vous rencontrer', 'vos disponibilités', 'vos disponibilites', 'fixer un entretien', 'convenir d\'un entretien',
            'vous inviter', 'vous convier', 'entretien le', 'entretien téléphonique', 'entretien telephonique', 'premier entretien',
            'invite you', 'schedule an interview', 'schedule a call', 'interview with', 'your availability',
            'meet you', 'vorstellungsgespräch',
        ],
        'accuse' => [
            'bien reçu', 'bien recu', 'accusé de réception', 'accuse de reception', 'avons reçu votre candidature',
            'nous accusons', 'received your application', 'thank you for applying', 'thank you for your application',
            'merci pour votre candidature', 'merci de votre candidature', 'eingang ihrer bewerbung',
        ],
    ];

    /** Alertes d'offres et lettres d'information : jamais une réponse à une candidature. */
    public const NOT_A_RESPONSE = [
        'alerte', 'job alert', 'nouvelles offres', 'nouvelle offre', 'new jobs', 'jobs you may', 'offres qui pourraient',
        'offres correspondant', 'recommended jobs', 'emplois recommandés', 'newsletter', 'se désabonner de cette alerte',
    ];

    public const KINDS = [
        'refus' => 'Refus',
        'entretien' => 'Invitation à un entretien',
        'accuse' => 'Accusé de réception',
        'autre' => 'Message à lire',
    ];

    public function __construct(private Gmail $gmail) {}

    public static function classify(string $text): string
    {
        $text = Str::lower($text);

        foreach (self::PATTERNS as $kind => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $kind;
                }
            }
        }

        return 'autre';
    }

    /**
     * @return array{found: int, updated: int, followed_up: int}
     */
    public function run(): array
    {
        $result = ['found' => 0, 'updated' => 0, 'followed_up' => 0];

        if (! GoogleClient::isConnected()) {
            return $result;
        }

        $own = mb_strtolower((string) GoogleConnection::current()->email);
        $awaiting = Opportunity::query()
            ->whereIn('status', [OpportunityStatus::Applied->value, OpportunityStatus::FollowedUp->value])
            ->with(['application', 'prospect'])
            ->get();

        if ($awaiting->isEmpty()) {
            return $result;
        }

        $messages = [];

        // 1. Fils des candidatures envoyées par le tableau de bord.
        foreach ($awaiting as $o) {
            if ($thread = $o->application?->gmail_thread_id) {
                foreach ($this->gmail->thread($thread) as $m) {
                    $messages[$m['id']] = $m + ['opportunity' => $o];
                }
            }
        }

        // 2. Libellé « Jobs » (les filtres Gmail y rangent les réponses des recruteurs et des plateformes).
        $label = trim((string) config('dashboard.gmail_label', 'Jobs')) ?: 'Jobs';
        $query = 'label:'.(str_contains($label, ' ') ? '"'.$label.'"' : $label).' newer_than:30d';
        foreach ($this->gmail->search($query, 50) as $id) {
            if (! isset($messages[$id]) && ! TrackedMessage::seen($id, 'reponse')) {
                if ($m = $this->gmail->message($id)) {
                    $messages[$id] = $m;
                }
            }
        }

        foreach ($messages as $m) {
            $fromOwner = Gmail::address($m['from']) === $own || in_array('SENT', $m['labels'], true);

            // Relance envoyée par Pierre depuis Gmail (brouillon préparé par le tableau de bord).
            if ($fromOwner) {
                $o = $m['opportunity'] ?? null;
                if ($o && $o->status === OpportunityStatus::Applied && $o->follow_up_drafted_at && $m['date']?->gt($o->follow_up_drafted_at)
                    && ! TrackedMessage::seen($m['id'], 'reponse')) {
                    $o->update(['status' => OpportunityStatus::FollowedUp, 'followed_up_at' => $m['date']->toDateString()]);
                    $o->events()->create(['type' => 'note', 'body' => 'Relance envoyée depuis Gmail, repérée automatiquement.', 'happened_at' => now()]);
                    TrackedMessage::query()->create(['gmail_message_id' => $m['id'], 'purpose' => 'reponse', 'kind' => 'relance', 'opportunity_id' => $o->id, 'subject' => Str::limit($m['subject'], 250), 'received_at' => $m['date']]);
                    $result['followed_up']++;
                }

                continue;
            }

            if (TrackedMessage::seen($m['id'], 'reponse')) {
                continue;
            }

            if (! isset($m['opportunity']) && Str::contains(Str::lower($m['subject']), self::NOT_A_RESPONSE)) {
                continue;
            }

            $o = $m['opportunity'] ?? $this->match($m, $awaiting);

            // Message du libellé sans lien avec une candidature en attente (alerte d'offres, newsletter…).
            if (! $o) {
                continue;
            }

            // Ignorer ce qui précède l'envoi de la candidature.
            $sentAt = $o->application?->sent_at ?? $o->applied_at;
            if ($sentAt && $m['date'] && $m['date']->lt($sentAt)) {
                continue;
            }

            $kind = self::classify($m['subject'].' '.$m['snippet'].' '.$m['text']);
            TrackedMessage::query()->create([
                'gmail_message_id' => $m['id'], 'purpose' => 'reponse', 'kind' => $kind, 'opportunity_id' => $o->id,
                'subject' => Str::limit($m['subject'], 250), 'received_at' => $m['date'],
            ]);
            $result['found']++;

            $o->events()->create([
                'type' => 'note',
                'body' => self::KINDS[$kind].' détecté(e) dans Gmail : « '.Str::limit($m['subject'], 120).' » de '.Str::limit($m['from'], 80),
                'happened_at' => $m['date'] ?? now(),
            ]);

            $updated = false;
            if (config('dashboard.response_auto_status', true)) {
                $updated = match ($kind) {
                    'refus' => $o->update(['status' => OpportunityStatus::Rejected, 'response_type' => 'refus', 'first_response_at' => ($m['date'] ?? now())->toDateString()]),
                    'entretien' => $o->update(['status' => OpportunityStatus::Interview, 'response_type' => 'positive', 'first_response_at' => ($m['date'] ?? now())->toDateString()]),
                    'accuse' => false,
                    default => false,
                };
                $updated && $result['updated']++;
            }

            $status = $updated ? "\n\nStatut mis à jour : {$o->status->getLabel()}." : '';
            NotificationCenter::notify(
                'reponse',
                self::KINDS[$kind]." : {$o->company}",
                "{$o->title}\n\n« ".Str::limit($m['subject'], 120).' »'."\n".Str::limit($m['snippet'], 220).$status,
                ['Ouvrir la fiche' => OpportunityResource::getUrl('view', ['record' => $o]), 'Lire dans Gmail' => 'https://mail.google.com/mail/u/0/#all/'.$m['threadId']],
            );
        }

        return $result;
    }

    /**
     * Retrouve la candidature : même domaine que l'adresse de candidature, sinon nom de l'entreprise.
     *
     * @param  array{from: string, subject: string, snippet: string}  $m
     * @param  Collection<int, Opportunity>  $awaiting
     */
    public function match(array $m, Collection $awaiting): ?Opportunity
    {
        $from = Gmail::address($m['from']);
        $domain = Str::after($from, '@');

        foreach ($awaiting as $o) {
            foreach (array_filter([$o->application?->to_email, $o->contact_email, $o->prospect?->contact_email]) as $email) {
                $email = mb_strtolower($email);
                if ($email === $from) {
                    return $o;
                }
                // Même domaine d'entreprise (pas pour les webmails comme gmail.com).
                if ($domain !== '' && Gmail::queryFor($email) === "(from:@{$domain} OR to:@{$domain})") {
                    return $o;
                }
            }
        }

        $haystack = Str::lower(Str::ascii($m['from'].' '.$m['subject']));
        $matches = $awaiting->filter(function (Opportunity $o) use ($haystack) {
            $name = Str::lower(Str::ascii(trim(preg_replace('/\b(sa|sàrl|sarl|ag|gmbh|ltd|inc)\b\.?/iu', '', (string) $o->company))));

            return mb_strlen($name) >= 4 && preg_match('/\b'.preg_quote($name, '/').'\b/u', $haystack);
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
