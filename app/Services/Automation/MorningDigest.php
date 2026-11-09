<?php

namespace App\Services\Automation;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Prospects\ProspectResource;
use App\Models\AutomationRun;
use App\Models\Opportunity;
use App\Models\Prospect;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Résumé du matin : nouvelles offres, candidatures à valider, relances et entretiens,
 * avec un lien direct vers chaque fiche. Rien n'est envoyé s'il n'y a rien à signaler.
 */
class MorningDigest
{
    /**
     * @return array{offers: list<array>, validate: list<array>, followups: list<array>, interviews: list<array>, signals: list<array>}
     */
    public function collect(): array
    {
        $since = AutomationRun::lastRun('resume_matin') ?? now()->subDay();
        $view = fn (Opportunity $o) => OpportunityResource::getUrl('view', ['record' => $o]);

        $offers = Opportunity::query()
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', [OpportunityStatus::Discarded->value])
            ->orderByDesc('relevance_score')
            ->limit(15)
            ->get()
            ->map(fn (Opportunity $o) => [
                'title' => "{$o->company} – {$o->title}",
                'detail' => trim(($o->relevance_score !== null ? "score {$o->relevance_score}/100" : '').($o->location ? " · {$o->location}" : '')),
                'url' => $view($o),
            ])->all();

        $validate = Opportunity::query()
            ->whereHas('application', fn ($q) => $q->whereIn('status', [ApplicationStatus::ToValidate->value, ApplicationStatus::Failed->value]))
            ->with('application')
            ->get()
            ->map(fn (Opportunity $o) => [
                'title' => "{$o->company} – {$o->title}",
                'detail' => $o->application->status === ApplicationStatus::Failed ? 'échec d\'envoi à corriger' : ($o->application->missingForSending() ? 'à compléter' : 'prête à envoyer'),
                'url' => $view($o),
            ])->all();

        $followups = Opportunity::query()->followUpDue()->orderBy('follow_up_at')->get()
            ->map(fn (Opportunity $o) => [
                'title' => "{$o->company} – {$o->title}",
                'detail' => 'envoyée le '.$o->applied_at?->format('d.m.Y').($o->follow_up_draft_id ? ' · brouillon prêt dans Gmail' : ''),
                'url' => $view($o),
            ])
            ->merge(Prospect::query()->followUpDue()->get()->map(fn (Prospect $p) => [
                'title' => "{$p->company} (spontanée)",
                'detail' => 'envoyée le '.$p->sent_at?->format('d.m.Y'),
                'url' => ProspectResource::getUrl('view', ['record' => $p]),
            ]))->all();

        $interviews = Opportunity::query()->where('status', OpportunityStatus::Interview->value)->get()
            ->map(fn (Opportunity $o) => [
                'title' => "{$o->company} – {$o->title}",
                'detail' => $o->interview_prep ? 'fiche de préparation prête' : 'fiche à créer',
                'url' => $view($o),
            ])->all();

        $signals = Prospect::query()->whereNotNull('signal_date')->whereDate('signal_date', '>=', $since->toDateString())->get()
            ->map(fn (Prospect $p) => [
                'title' => $p->company,
                'detail' => (string) $p->signal,
                'url' => ProspectResource::getUrl('view', ['record' => $p]),
            ])->all();

        return compact('offers', 'validate', 'followups', 'interviews', 'signals');
    }

    /**
     * @return array{sent: bool, counts: array<string, int>}
     */
    public function send(): array
    {
        $data = $this->collect();
        $counts = array_map('count', $data);

        if (array_sum($counts) === 0) {
            return ['sent' => false, 'counts' => $counts];
        }

        $summary = collect([
            $counts['offers'] ? $counts['offers'].' nouvelle(s) offre(s)' : null,
            $counts['validate'] ? $counts['validate'].' candidature(s) à valider' : null,
            $counts['followups'] ? $counts['followups'].' relance(s)' : null,
            $counts['interviews'] ? $counts['interviews'].' entretien(s)' : null,
            $counts['signals'] ? $counts['signals'].' signal(aux) de veille' : null,
        ])->filter()->implode(', ');

        $mail = (new MailMessage)
            ->subject('Ton résumé du '.now()->locale('fr')->isoFormat('dddd D MMMM').' : '.$summary)
            ->markdown('mail.resume-matin', ['data' => $data, 'summary' => $summary, 'dashboard' => Dashboard::getUrl()]);

        NotificationCenter::notify('resume_matin', 'Résumé du matin', ucfirst($summary).'.', ['Ouvrir le tableau de bord' => Dashboard::getUrl()], $mail);

        return ['sent' => true, 'counts' => $counts];
    }
}
