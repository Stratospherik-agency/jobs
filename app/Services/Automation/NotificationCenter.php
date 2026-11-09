<?php

namespace App\Services\Automation;

use App\Models\User;
use App\Notifications\OwnerAlert;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications du propriétaire : par e-mail et/ou dans la web app (cloche en haut à droite).
 * Chaque type se règle séparément dans Réglages > Notifications. Le canal « ios » est déjà
 * enregistré pour la future application iPhone, qui lira les notifications de la web app.
 */
class NotificationCenter
{
    public const CHANNELS = [
        'mail' => 'E-mail',
        'app' => 'Web app',
        'ios' => 'App iOS',
    ];

    public const WEEKDAYS = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    /**
     * Types de notification et réglages par défaut.
     * every_days : intervalle en jours entre deux envois automatiques ; time : heure d'envoi ; weekday : jour (1 = lundi).
     *
     * @return array<string, array{label: string, description: string, icon: string, mail: bool, app: bool, ios: bool, every_days?: int, time?: string, weekday?: int}>
     */
    public static function types(): array
    {
        return [
            'resume_matin' => [
                'label' => 'Résumé du matin',
                'description' => 'Nouvelles offres, candidatures à valider, relances et entretiens du jour, avec un lien vers chaque fiche.',
                'icon' => 'heroicon-o-sun',
                'mail' => true, 'app' => true, 'ios' => false,
                'every_days' => 1, 'time' => '07:15',
            ],
            'candidature_prete' => [
                'label' => 'Candidature prête à valider',
                'description' => 'Une offre très pertinente a ses documents : la candidature est préparée, il ne reste qu\'à la valider.',
                'icon' => 'heroicon-o-document-check',
                'mail' => true, 'app' => true, 'ios' => false,
            ],
            'offre_pertinente' => [
                'label' => 'Nouvelle offre très pertinente',
                'description' => 'Dès qu\'une offre atteint le score de préparation d\'office.',
                'icon' => 'heroicon-o-sparkles',
                'mail' => false, 'app' => true, 'ios' => false,
            ],
            'relance' => [
                'label' => 'Relances',
                'description' => 'Brouillon de relance créé dans Gmail, puis rappel si la candidature reste sans réponse.',
                'icon' => 'heroicon-o-arrow-path',
                'mail' => true, 'app' => true, 'ios' => false,
                'time' => '07:00',
            ],
            'reponse' => [
                'label' => 'Réponse d\'un recruteur',
                'description' => 'Refus, invitation à un entretien ou accusé de réception repéré dans Gmail.',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'mail' => true, 'app' => true, 'ios' => false,
            ],
            'entretien' => [
                'label' => 'Préparation d\'entretien',
                'description' => 'Fiche créée dès qu\'un entretien est fixé : entreprise, questions probables, preuves chiffrées.',
                'icon' => 'heroicon-o-academic-cap',
                'mail' => true, 'app' => true, 'ios' => false,
            ],
            'veille' => [
                'label' => 'Veille des entreprises cibles',
                'description' => 'Recrutement, nomination, levée de fonds ou nouvelle offre chez une entreprise de l\'onglet Spontanées.',
                'icon' => 'heroicon-o-eye',
                'mail' => false, 'app' => true, 'ios' => false,
                'every_days' => 1, 'time' => '06:30',
            ],
            'bilan' => [
                'label' => 'Bilan hebdomadaire',
                'description' => 'Taux de réponse par source, secteur et type de candidature, avec des ajustements proposés.',
                'icon' => 'heroicon-o-chart-bar',
                'mail' => true, 'app' => true, 'ios' => false,
                'every_days' => 7, 'time' => '17:00', 'weekday' => 5,
            ],
        ];
    }

    /**
     * Réglages effectifs d'un type (valeurs enregistrées, sinon valeurs par défaut).
     *
     * @return array{label: string, description: string, icon: string, mail: bool, app: bool, ios: bool, every_days?: int, time?: string, weekday?: int}
     */
    public static function preferences(string $type): array
    {
        $defaults = self::types()[$type] ?? throw new \InvalidArgumentException("Type de notification inconnu : {$type}");
        $stored = (array) (config('dashboard.notifications')[$type] ?? []);

        $prefs = $defaults;
        foreach (['mail', 'app', 'ios'] as $channel) {
            if (array_key_exists($channel, $stored)) {
                $prefs[$channel] = (bool) $stored[$channel];
            }
        }
        if (isset($defaults['every_days']) && filled($stored['every_days'] ?? null)) {
            $prefs['every_days'] = max(1, min(60, (int) $stored['every_days']));
        }
        if (isset($defaults['time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($stored['time'] ?? ''))) {
            $prefs['time'] = $stored['time'];
        }
        if (isset($defaults['weekday']) && array_key_exists($stored['weekday'] ?? null, self::WEEKDAYS)) {
            $prefs['weekday'] = (int) $stored['weekday'];
        }

        return $prefs;
    }

    /** Au moins un canal actif pour ce type. */
    public static function enabled(string $type): bool
    {
        $prefs = self::preferences($type);

        return $prefs['mail'] || $prefs['app'] || $prefs['ios'];
    }

    /** Compte du propriétaire, destinataire de toutes les notifications. */
    public static function owner(): ?User
    {
        $email = config('dashboard.owner_email');

        return filled($email) ? User::query()->where('email', $email)->first() : null;
    }

    /**
     * Prévient le propriétaire selon ses réglages pour ce type.
     *
     * @param  array<string, string>  $links  libellé du bouton → adresse (le premier est le lien principal)
     * @param  MailMessage|null  $mail  e-mail détaillé ; à défaut, un e-mail simple est construit
     * @return list<string> canaux utilisés
     */
    public static function notify(string $type, string $title, string $body, array $links = [], ?MailMessage $mail = null): array
    {
        $prefs = self::preferences($type);
        $owner = self::owner();
        $sent = [];

        if (! $owner) {
            return $sent;
        }

        // La web app sert aussi de boîte de réception à la future app iOS.
        if ($prefs['app'] || $prefs['ios']) {
            try {
                FilamentNotification::make()
                    ->title($title)
                    ->body($body)
                    ->icon($prefs['icon'])
                    ->actions(collect($links)->take(2)->map(
                        fn (string $url, string $label) => Action::make('lien_'.md5($label))->label($label)->url($url)->markAsRead()
                    )->values()->all())
                    ->sendToDatabase($owner);
                $sent[] = 'app';
            } catch (Throwable $e) {
                Log::warning('Notification web app non enregistrée', ['type' => $type, 'erreur' => $e->getMessage()]);
            }
        }

        if ($prefs['mail']) {
            try {
                $owner->notify(new OwnerAlert($mail ?? self::simpleMail($title, $body, $links)));
                $sent[] = 'mail';
            } catch (Throwable $e) {
                Log::warning('Notification par e-mail non envoyée', ['type' => $type, 'erreur' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Message de test sur l'e-mail et la web app, quels que soient les réglages.
     *
     * @return list<string> canaux qui ont fonctionné
     */
    public static function test(): array
    {
        $owner = self::owner();
        $sent = [];

        if (! $owner) {
            return $sent;
        }

        try {
            FilamentNotification::make()->title('Test de notification')->body('Les notifications de la web app fonctionnent.')->icon('heroicon-o-bell-alert')->sendToDatabase($owner);
            $sent[] = 'web app';
        } catch (Throwable $e) {
            Log::warning('Test web app en échec', ['erreur' => $e->getMessage()]);
        }

        try {
            $owner->notify(new OwnerAlert(self::simpleMail('Test de notification', 'Les e-mails du tableau de bord arrivent bien.')));
            $sent[] = 'e-mail';
        } catch (Throwable $e) {
            Log::warning('Test e-mail en échec', ['erreur' => $e->getMessage()]);
        }

        return $sent;
    }

    /**
     * @param  array<string, string>  $links
     */
    public static function simpleMail(string $title, string $body, array $links = []): MailMessage
    {
        $mail = (new MailMessage)->subject($title)->greeting('Bonjour,');

        foreach (preg_split('/\R{2,}/', $body) as $paragraph) {
            $mail->line($paragraph);
        }

        $first = true;
        foreach ($links as $label => $url) {
            $first ? $mail->action($label, $url) : $mail->line("[{$label}]({$url})");
            $first = false;
        }

        return $mail->salutation('Ton tableau de bord jobs');
    }
}
