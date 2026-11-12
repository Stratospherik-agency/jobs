<?php

namespace Tests\Feature;

use App\Models\AutomationRun;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\OwnerAlert;
use App\Services\Automation\HealthCheck;
use App\Services\Automation\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    }

    private function healthy(): void
    {
        AutomationRun::mark('preparation');
        GoogleConnection::query()->create([
            'email' => 'owner@example.test', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(),
            'scopes' => 'https://www.googleapis.com/auth/spreadsheets.readonly', 'registry_synced_at' => now(),
        ]);
        Opportunity::factory()->create();
    }

    public function test_everything_ok(): void
    {
        $this->healthy();
        AutomationRun::mark('alertes_gmail', '5');

        $this->assertTrue(HealthCheck::allOk());
    }

    public function test_missing_gmail_alerts_trigger_one_alert_per_day(): void
    {
        Notification::fake();
        $this->healthy();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => []])]);

        $result = app(HealthCheck::class)->run();

        $this->assertSame(['alertes'], $result['failing']);
        $this->assertSame(['alertes'], $result['alerted']);
        Notification::assertSentTo($this->owner, OwnerAlert::class);

        // Pas de nouvelle alerte le même jour.
        $this->assertSame([], app(HealthCheck::class)->run()['alerted']);
    }

    public function test_registry_sync_error_is_reported(): void
    {
        $this->healthy();
        GoogleConnection::current()->forceFill(['registry_sync_error' => 'Accès refusé'])->save();

        $checks = HealthCheck::checks();
        $this->assertFalse($checks['registre']['ok']);
        $this->assertStringContainsString('Accès refusé', $checks['registre']['detail']);
    }

    public function test_mail_failure_is_recorded_and_shown_in_the_web_app(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'serveur-introuvable.invalid', 'mail.mailers.smtp.port' => 2525]);
        $this->healthy();

        NotificationCenter::notify('reponse', 'Titre', 'Texte');

        $this->assertFalse(HealthCheck::checks()['mail']['ok']);
        $this->assertTrue($this->owner->notifications()->get()->contains(fn ($n) => str_contains($n->data['title'], 'ne partent pas')));
    }

    public function test_dashboard_shows_the_system_state(): void
    {
        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee('État du système');
    }
}
