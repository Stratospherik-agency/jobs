<?php

namespace App\Services\Automation;

use App\Filament\Pages\Dashboard;
use App\Models\AutomationRun;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use App\Services\Google\Sheets;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * État du système : chaque maillon de la chaîne est contrôlé, et une panne déclenche une alerte
 * (web app et e-mail), au plus une fois par jour et par problème.
 *
 * Maillons : tâche planifiée (cron), envoi des e-mails, compte Google, synchronisation du registre,
 * arrivée des alertes d'emploi dans le libellé Gmail, nouvelles offres ajoutées par la recherche quotidienne.
 */
class HealthCheck
{
    /** Sans alerte d'emploi dans le libellé depuis ce nombre de jours : les filtres Gmail sont à vérifier. */
    public const ALERTS_DAYS = 3;

    /** Sans nouvelle offre au registre depuis ce nombre de jours : la recherche quotidienne est à vérifier. */
    public const OFFERS_DAYS = 3;

    public function __construct(private Gmail $gmail) {}

    /**
     * @return array<string, array{label: string, ok: bool, detail: string, fix: string}>
     */
    public static function checks(): array
    {
        $checks = [];

        // 1. Tâche planifiée : la préparation tourne toutes les 15 minutes.
        $last = AutomationRun::lastRun('preparation');
        $checks['cron'] = [
            'label' => 'Tâche planifiée (cron)',
            'ok' => $last !== null && $last->gt(now()->subMinutes(30)),
            'detail' => $last ? 'Dernier passage : '.$last->format('d.m.Y à H:i') : 'Jamais exécutée',
            'fix' => 'Le cron du serveur ne lance plus le tableau de bord : voir docs/automatisations.md, étape 5.',
        ];

        // 2. E-mails : la dernière tentative a-t-elle réussi ?
        $ok = AutomationRun::lastRun('mail_ok');
        $ko = AutomationRun::query()->where('task', 'mail_erreur')->first();
        $failing = $ko && (! $ok || $ko->ran_at->gt($ok));
        $checks['mail'] = [
            'label' => 'Envoi des e-mails',
            'ok' => ! $failing,
            'detail' => $failing
                ? 'Échec le '.$ko->ran_at->format('d.m.Y à H:i').' : '.$ko->summary
                : ($ok ? 'Dernier e-mail parti le '.$ok->format('d.m.Y à H:i').' vers '.(config('dashboard.notification_email') ?: config('dashboard.owner_email')).' (penser au dossier Spam)' : 'Aucun e-mail envoyé pour l\'instant'),
            'fix' => 'Vérifier l\'adresse et le mot de passe de la boîte d\'envoi dans Réglages > E-mails, puis cliquer sur « Tester les notifications ».',
        ];

        // 3. Compte Google et synchronisation du registre.
        $connection = GoogleConnection::current();
        $checks['google'] = [
            'label' => 'Compte Google',
            'ok' => $connection !== null,
            'detail' => $connection ? 'Connecté : '.$connection->email : 'Non connecté',
            'fix' => 'Reconnecter le compte Google depuis la page Compte Google.',
        ];

        $synced = $connection?->registry_synced_at;
        $checks['registre'] = [
            'label' => 'Synchronisation du registre',
            'ok' => $connection && blank($connection->registry_sync_error) && $synced && $synced->gt(now()->subHours(2)),
            'detail' => $connection?->registry_sync_error
                ? 'Erreur : '.$connection->registry_sync_error
                : ($synced ? 'Dernière synchronisation : '.$synced->format('d.m.Y à H:i') : 'Jamais synchronisé'),
            'fix' => $connection && ! $connection->hasScope(Sheets::SCOPE)
                ? 'Reconnecter le compte Google pour autoriser la lecture du registre.'
                : 'Ouvrir la page Compte Google et cliquer sur « Synchroniser maintenant » pour voir l\'erreur.',
        ];

        // 4. Alertes d'emploi dans le libellé Gmail (mesuré par la tâche « santé »).
        $alerts = AutomationRun::query()->where('task', 'alertes_gmail')->first();
        $count = $alerts ? (int) $alerts->summary : null;
        $label = (string) config('dashboard.gmail_label', 'Jobs');
        $checks['alertes'] = [
            'label' => 'Alertes d\'emploi dans Gmail',
            'ok' => $count === null || $count > 0,
            'detail' => $count === null
                ? 'Pas encore mesuré'
                : "{$count} alerte(s) dans le libellé « {$label} » ces ".self::ALERTS_DAYS.' derniers jours (mesuré le '.$alerts->ran_at->format('d.m.Y à H:i').')',
            'fix' => 'Vérifier dans Gmail que les alertes LinkedIn, jobup, jobs.ch, Indeed et Google arrivent et que le filtre les range dans « '.$label.' ».',
        ];

        // 5. Recherche quotidienne : de nouvelles offres arrivent-elles au registre ?
        $newest = Opportunity::query()->max('created_at');
        $newest = $newest ? Carbon::parse($newest) : null;
        $checks['recherche'] = [
            'label' => 'Nouvelles offres (recherche quotidienne)',
            'ok' => $newest !== null && $newest->gt(now()->subDays(self::OFFERS_DAYS)),
            'detail' => $newest ? 'Dernière offre ajoutée le '.$newest->format('d.m.Y à H:i') : 'Aucune offre',
            'fix' => 'Aucune offre depuis '.self::OFFERS_DAYS.' jours : vérifier le résumé de la recherche quotidienne dans le projet Claude, et les alertes Gmail.',
        ];

        return $checks;
    }

    public static function allOk(): bool
    {
        return collect(self::checks())->every(fn ($c) => $c['ok']);
    }

    /**
     * Tâche planifiée : mesure les alertes Gmail, puis alerte sur chaque maillon en panne.
     *
     * @return array{failing: list<string>, alerted: list<string>}
     */
    public function run(): array
    {
        if (GoogleClient::isConnected()) {
            try {
                $label = trim((string) config('dashboard.gmail_label', 'Jobs')) ?: 'Jobs';
                $query = 'label:'.(str_contains($label, ' ') ? '"'.$label.'"' : $label).' newer_than:'.self::ALERTS_DAYS.'d';
                AutomationRun::mark('alertes_gmail', (string) count($this->gmail->search($query, 100)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $failing = [];
        $alerted = [];

        foreach (self::checks() as $key => $check) {
            if ($check['ok']) {
                AutomationRun::query()->where('task', 'alerte_'.$key)->delete(); // réparé : une nouvelle panne sera signalée

                continue;
            }

            $failing[] = $key;

            // Le cron ne peut pas signaler sa propre panne, ni l'e-mail la sienne (géré par NotificationCenter).
            if ($key === 'cron' || $key === 'mail') {
                continue;
            }

            $last = AutomationRun::lastRun('alerte_'.$key);
            if ($last && $last->gt(now()->subDay())) {
                continue;
            }

            AutomationRun::mark('alerte_'.$key);
            $alerted[] = $key;
            NotificationCenter::notify(
                'systeme',
                'À vérifier : '.$check['label'],
                $check['detail']."\n\n".$check['fix'],
                ['Voir l\'état du système' => Dashboard::getUrl()],
            );
        }

        return ['failing' => $failing, 'alerted' => $alerted];
    }
}
