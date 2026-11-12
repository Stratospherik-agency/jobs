<?php

namespace App\Console\Commands;

use App\Models\AutomationRun;
use App\Services\Automation\AutoPreparer;
use App\Services\Automation\FollowUps;
use App\Services\Automation\HealthCheck;
use App\Services\Automation\MorningDigest;
use App\Services\Automation\NotificationCenter;
use App\Services\Automation\RelevanceScorer;
use App\Services\Automation\ResponseTracker;
use App\Services\Automation\TargetWatch;
use App\Services\Automation\WeeklyReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lance les automatisations arrivées à échéance (planifié toutes les 5 minutes).
 * Les heures, jours et intervalles se règlent dans Réglages > Notifications.
 * Aucune automatisation n'envoie de candidature ni d'e-mail à un recruteur.
 */
class RunAutomations extends Command
{
    protected $signature = 'automatisations:executer
        {tache? : scores, preparation, relances, reponses, veille, resume_matin, bilan ou sante (toutes par défaut)}
        {--force : lancer maintenant, sans attendre l\'heure prévue}';

    protected $description = 'Lance les automatisations du tableau de bord (préparation, relances, résumés, veille…)';

    public const TASKS = ['scores', 'preparation', 'relances', 'reponses', 'veille', 'resume_matin', 'bilan', 'sante'];

    public function handle(): int
    {
        $only = $this->argument('tache');

        if ($only && ! in_array($only, self::TASKS, true)) {
            $this->error('Tâche inconnue. Possibles : '.implode(', ', self::TASKS));

            return self::INVALID;
        }

        $failed = false;
        foreach (self::TASKS as $task) {
            if ($only && $task !== $only) {
                continue;
            }

            if (! $this->option('force') && ! $this->isDue($task)) {
                continue;
            }

            try {
                $summary = $this->runTask($task);
                AutomationRun::mark($task, $summary);
                $this->info("{$task} : {$summary}");
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                AutomationRun::mark($task.'_erreur', $e->getMessage());
                $this->error("{$task} : {$e->getMessage()}");
            }
        }

        $mailError = AutomationRun::query()->where('task', 'mail_erreur')->first();
        $mailOk = AutomationRun::lastRun('mail_ok');
        if ($mailError && (! $mailOk || $mailError->ran_at->gt($mailOk)) && $mailError->ran_at->gt(now()->subMinutes(5))) {
            $this->error('E-mails : envoi impossible. '.$mailError->summary);
            $this->line('Vérifie les lignes MAIL_… du fichier .env (voir docs/automatisations.md, « Les e-mails n\'arrivent pas »).');
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(string $task): bool
    {
        return match ($task) {
            'scores' => AutomationRun::dueAt('scores', '05:30'),
            'preparation' => AutomationRun::dueEvery('preparation', 15),
            'relances' => AutomationRun::dueAt('relances', NotificationCenter::preferences('relance')['time']),
            'reponses' => AutomationRun::dueEvery('reponses', 60),
            'veille' => ($p = NotificationCenter::preferences('veille')) && AutomationRun::dueAt('veille', $p['time'], $p['every_days']),
            'resume_matin' => NotificationCenter::enabled('resume_matin')
                && ($p = NotificationCenter::preferences('resume_matin'))
                && AutomationRun::dueAt('resume_matin', $p['time'], $p['every_days']),
            'bilan' => NotificationCenter::enabled('bilan')
                && ($p = NotificationCenter::preferences('bilan'))
                && AutomationRun::dueAt('bilan', $p['time'], $p['every_days'], $p['every_days'] % 7 === 0 ? $p['weekday'] : null),
            'sante' => AutomationRun::dueEvery('sante', 60),
        };
    }

    private function runTask(string $task): string
    {
        return match ($task) {
            'scores' => RelevanceScorer::refreshAll().' score(s) mis à jour',
            'preparation' => (fn ($r) => "{$r['prepared']} candidature(s) préparée(s), {$r['notified']} signalée(s)")(app(AutoPreparer::class)->run()),
            'relances' => (fn ($r) => "{$r['drafts']} brouillon(s), {$r['todo']} relance(s) à faire, {$r['reminders']} rappel(s), {$r['prospects']} spontanée(s)"
                .($r['errors'] ? ' ; erreurs : '.implode(' | ', $r['errors']) : ''))(app(FollowUps::class)->run()),
            'reponses' => (fn ($r) => "{$r['found']} réponse(s), {$r['updated']} statut(s) mis à jour, {$r['followed_up']} relance(s) repérée(s)")(app(ResponseTracker::class)->run()),
            'veille' => (fn ($r) => "{$r['signals']} signal(aux)")(app(TargetWatch::class)->run(NotificationCenter::preferences('veille')['every_days'])),
            'resume_matin' => (fn ($r) => $r['sent'] ? 'envoyé ('.http_build_query($r['counts'], '', ', ').')' : 'rien à signaler')(app(MorningDigest::class)->send()),
            'bilan' => (fn ($r) => "envoyé ({$r['week']['sent']} envoi(s) cette semaine)")(app(WeeklyReport::class)->send()),
            'sante' => (fn ($r) => $r['failing'] ? 'à vérifier : '.implode(', ', $r['failing']) : 'tout fonctionne')(app(HealthCheck::class)->run()),
        };
    }
}
