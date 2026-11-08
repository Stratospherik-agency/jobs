<?php

namespace App\Filament\Resources\Opportunities\Widgets;

use App\Models\Opportunity;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleException;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Échanges Gmail liés à l'offre : fil de la candidature envoyée et messages avec le domaine du recruteur.
 */
class Correspondence extends Widget
{
    protected string $view = 'filament.widgets.correspondence';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public bool $refresh = false;

    protected function getViewData(): array
    {
        /** @var Opportunity|null $opportunity */
        $opportunity = $this->record;
        $application = $opportunity?->application;

        if (! GoogleClient::isConnected()) {
            return ['state' => 'disconnected', 'messages' => []];
        }

        if (! $application?->gmail_thread_id && ! $application?->to_email) {
            return ['state' => 'empty', 'messages' => []];
        }

        $key = 'correspondence-'.$opportunity->id.'-'.md5($application->gmail_thread_id.'|'.$application->to_email);

        if ($this->refresh) {
            Cache::forget($key);
            $this->refresh = false;
        }

        try {
            $messages = Cache::remember($key, now()->addMinutes(10), fn () => app(Gmail::class)->correspondence($application->gmail_thread_id, $application->to_email));
        } catch (GoogleException $e) {
            return ['state' => 'error', 'error' => $e->getMessage(), 'messages' => []];
        }

        return ['state' => 'ok', 'messages' => $messages, 'query' => Gmail::queryFor($application->to_email)];
    }

    public function reload(): void
    {
        $this->refresh = true;
    }
}
