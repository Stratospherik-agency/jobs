<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use App\Services\Google\GoogleClient;
use App\Services\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    private function saveForm(array $data): Testable
    {
        $this->actingAs($this->owner());

        return Livewire::test(SettingsPage::class)->fillForm($data)->call('save');
    }

    public function test_settings_page_requires_login(): void
    {
        $this->get('/reglages')->assertRedirect('/login');
    }

    public function test_google_credentials_are_saved_encrypted_and_applied(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->saveForm([
            'google_client_id' => '123-abc.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-tres-secret',
        ])->assertHasNoFormErrors();

        $this->assertTrue(GoogleClient::isConfigured());
        $this->assertSame('GOCSPX-tres-secret', config('services.google.client_secret'));

        $raw = DB::table('settings')->where('key', 'google_client_secret')->value('value');
        $this->assertStringNotContainsString('GOCSPX', $raw);
    }

    public function test_secret_is_never_sent_back_to_the_browser(): void
    {
        Settings::save(['google_client_secret' => 'GOCSPX-tres-secret']);

        $this->actingAs($this->owner())
            ->get('/reglages')
            ->assertOk()
            ->assertSee('enregistré')
            ->assertDontSee('GOCSPX-tres-secret');
    }

    public function test_empty_secret_keeps_the_stored_one(): void
    {
        Settings::save(['google_client_secret' => 'GOCSPX-ancien']);

        $this->saveForm(['google_client_id' => '123-abc.apps.googleusercontent.com', 'google_client_secret' => ''])
            ->assertHasNoFormErrors();

        $this->assertSame('GOCSPX-ancien', Setting::query()->where('key', 'google_client_secret')->first()->value);
    }

    public function test_invalid_client_id_is_rejected(): void
    {
        $this->saveForm(['google_client_id' => 'pas-un-id'])->assertHasFormErrors(['google_client_id']);
        $this->assertFalse(Settings::isStored('google_client_id'));
    }

    public function test_registry_url_is_reduced_to_its_id(): void
    {
        $this->saveForm(['google_registry_sheet_id' => 'https://docs.google.com/spreadsheets/d/1AbC_def-123/edit#gid=0'])
            ->assertHasNoFormErrors();

        $this->assertSame('1AbC_def-123', config('services.google.registry_spreadsheet_id'));
    }

    public function test_follow_up_delay_is_used(): void
    {
        $this->saveForm(['follow_up_days' => 7, 'login_alerts' => false])->assertHasNoFormErrors();

        $opportunity = Opportunity::factory()->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()]);

        $this->assertTrue($opportunity->follow_up_at->isSameDay(today()->addDays(7)));
        $this->assertFalse(config('dashboard.login_alerts'));
    }

    public function test_settings_are_loaded_on_each_request(): void
    {
        Settings::save(['signature' => 'Pierre-Ollivier Tissier']);
        config(['dashboard.signature' => null]);

        Settings::apply();

        $this->assertSame('Pierre-Ollivier Tissier', config('dashboard.signature'));
    }

    public function test_google_guide_opens_in_a_window(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(SettingsPage::class)
            ->assertActionExists('guide_header')
            ->mountAction('guide_header')
            ->assertActionMounted('guide_header');

        $html = SettingsPage::guideHtml();
        $this->assertStringContainsString('Créer le projet', $html);
        $this->assertStringContainsString(config('services.google.redirect'), $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('jobs.pierreolliviertissier.ch/google/callback', $html);
    }

    public function test_mail_server_is_configured_from_the_settings_page(): void
    {
        $this->saveForm([
            'mail_username' => 'jobs@pierreolliviertissier.ch',
            'mail_password' => 'mot-de-passe-boite',
            'mail_from_address' => 'jobs@pierreolliviertissier.ch',
            'notification_email' => 'pierre@example.test',
        ])->assertHasNoFormErrors();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.infomaniak.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('jobs@pierreolliviertissier.ch', config('mail.mailers.smtp.username'));
        $this->assertSame('mot-de-passe-boite', config('mail.mailers.smtp.password'));
        $this->assertSame('jobs@pierreolliviertissier.ch', config('mail.from.address'));
        $this->assertSame('pierre@example.test', User::query()->first()->routeNotificationForMail());

        $raw = DB::table('settings')->where('key', 'mail_password')->value('value');
        $this->assertStringNotContainsString('mot-de-passe-boite', $raw);

        $this->get('/reglages')->assertOk()->assertDontSee('mot-de-passe-boite');

        // Mot de passe laissé vide : l'ancien est conservé.
        Livewire::test(SettingsPage::class)->fillForm(['mail_password' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertSame('mot-de-passe-boite', config('mail.mailers.smtp.password'));
    }

    public function test_mail_settings_can_be_tested_before_saving(): void
    {
        $state = [
            'mail_username' => 'jobs@pierreolliviertissier.ch',
            'mail_password' => 'mot-de-passe-boite',
            'mail_from_address' => '',
            'notification_email' => 'pierre@example.test',
            'mail_host' => 'mail.infomaniak.com',
            'mail_port' => 465,
            'mail_scheme' => 'smtps',
        ];

        $result = SettingsPage::testMailSettings($state, 'array');
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('pierre@example.test', $result['message']);
        $this->assertStringContainsString('Enregistrer', $result['message']);

        $missing = SettingsPage::testMailSettings(['notification_email' => 'pierre@example.test', 'mail_username' => '']);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('Il manque', $missing['message']);

        // Serveur injoignable : l'erreur exacte et une piste sont affichées.
        $down = SettingsPage::testMailSettings(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_scheme' => 'smtp'] + $state);
        $this->assertFalse($down['ok']);
        $this->assertStringContainsString('Erreur du serveur', $down['message']);

        Livewire::test(SettingsPage::class)
            ->fillForm(['mail_username' => '', 'notification_email' => 'pierre@example.test'])
            ->callAction(TestAction::make('test_mail_settings')->schemaComponent('mail_test_actions'))
            ->assertNotified('Échec de l\'envoi');
    }

    public function test_mail_sender_defaults_to_the_sending_mailbox(): void
    {
        $this->saveForm([
            'mail_username' => 'jobs@pierreolliviertissier.ch',
            'mail_password' => 'mot-de-passe-boite',
            'mail_from_address' => '',
            'notification_email' => 'pierre@example.test',
        ])->assertHasNoFormErrors();

        $this->assertSame('jobs@pierreolliviertissier.ch', config('mail.from.address'));

        // Réglage absent en base (enregistré avant ce correctif) : la boîte d'envoi sert d'expéditeur.
        Setting::query()->where('key', 'mail_from_address')->delete();
        config(['mail.from.address' => null]);
        Settings::apply();
        $this->assertSame('jobs@pierreolliviertissier.ch', config('mail.from.address'));
    }
}
