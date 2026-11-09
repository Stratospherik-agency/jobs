<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Enums\ProspectStatus;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\AutomationRun;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\Prospect;
use App\Models\User;
use App\Notifications\OwnerAlert;
use App\Services\Automation\AutoPreparer;
use App\Services\Automation\FollowUps;
use App\Services\Automation\InterviewPrep;
use App\Services\Automation\MorningDigest;
use App\Services\Automation\NotificationCenter;
use App\Services\Automation\RelevanceScorer;
use App\Services\Automation\ResponseTracker;
use App\Services\Automation\TargetWatch;
use App\Services\Automation\WeeklyReport;
use App\Services\Google\Gmail;
use App\Services\Settings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        RelevanceScorer::forget();

        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    }

    private function connect(): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'email' => 'owner@example.test',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function offer(array $attributes = []): Opportunity
    {
        return Opportunity::factory()->create([
            'company' => 'Vaudoise Assurances',
            'title' => 'Responsable marketing digital',
            'location' => 'Lausanne',
            'rate' => '80-100 %',
            'sector' => 'Assurance',
            ...$attributes,
        ]);
    }

    /** Message Gmail au format de l'API. */
    private static function gmailMessage(string $id, string $thread, string $from, string $subject, string $text, array $labels = ['INBOX'], ?int $ms = null): array
    {
        return [
            'id' => $id,
            'threadId' => $thread,
            'labelIds' => $labels,
            'snippet' => mb_substr($text, 0, 120),
            'internalDate' => (string) ($ms ?? now()->getTimestampMs()),
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [['name' => 'From', 'value' => $from], ['name' => 'Subject', 'value' => $subject]],
                'body' => ['data' => Gmail::b64url($text)],
            ],
        ];
    }

    // ---- Réglages et notifications --------------------------------------------------------

    public function test_notification_preferences_are_saved_from_the_settings_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(SettingsPage::class)
            ->fillForm([
                'notifications.resume_matin.mail' => false,
                'notifications.resume_matin.app' => true,
                'notifications.resume_matin.every_days' => 2,
                'notifications.resume_matin.time' => '08:30',
                'notifications.bilan.weekday' => 1,
                'follow_up_days' => 12,
                'reminder_days' => 14,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $prefs = NotificationCenter::preferences('resume_matin');
        $this->assertFalse($prefs['mail']);
        $this->assertTrue($prefs['app']);
        $this->assertSame(2, $prefs['every_days']);
        $this->assertSame('08:30', $prefs['time']);
        $this->assertSame(1, NotificationCenter::preferences('bilan')['weekday']);
        $this->assertSame(12, Opportunity::followUpDays());
        $this->assertSame(14, config('dashboard.reminder_days'));
    }

    public function test_settings_page_renders_the_new_tabs(): void
    {
        $this->actingAs($this->owner)->get('/reglages?tab=notifications::data::tab')->assertOk()->assertSee('Résumé du matin');
    }

    public function test_channels_follow_the_preferences(): void
    {
        Notification::fake();
        Settings::save(['notifications' => ['reponse' => ['mail' => false, 'app' => true]]]);

        $channels = NotificationCenter::notify('reponse', 'Refus : Nestlé', 'Texte', ['Ouvrir' => 'https://jobs.test/x']);

        $this->assertSame(['app'], $channels);
        Notification::assertNotSentTo($this->owner, OwnerAlert::class);
        Notification::assertSentTo($this->owner, DatabaseNotification::class);
    }

    public function test_web_app_notification_is_stored_for_the_bell(): void
    {
        Settings::save(['notifications' => ['reponse' => ['mail' => false, 'app' => true]]]);

        NotificationCenter::notify('reponse', 'Refus : Nestlé', 'Texte', ['Ouvrir' => 'https://jobs.test/x']);

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame('Refus : Nestlé', $this->owner->notifications()->first()->data['title']);
    }

    public function test_mail_channel_sends_an_email_to_the_owner(): void
    {
        Notification::fake();
        Settings::save(['notifications' => ['reponse' => ['mail' => true, 'app' => false]]]);

        $this->assertSame(['mail'], NotificationCenter::notify('reponse', 'Entretien : Nestlé', 'Texte'));
        Notification::assertSentTo($this->owner, OwnerAlert::class);
        Notification::assertNotSentTo($this->owner, DatabaseNotification::class);
    }

    public function test_due_logic_respects_time_and_interval(): void
    {
        $this->travelTo(now()->setTime(7, 0));
        $this->assertFalse(AutomationRun::dueAt('resume_matin', '07:15', 1));

        $this->travelTo(now()->setTime(7, 20));
        $this->assertTrue(AutomationRun::dueAt('resume_matin', '07:15', 1));
        AutomationRun::mark('resume_matin');
        $this->assertFalse(AutomationRun::dueAt('resume_matin', '07:15', 1));

        $this->travelTo(now()->addDay());
        $this->assertTrue(AutomationRun::dueAt('resume_matin', '07:15', 1));
        $this->assertFalse(AutomationRun::dueAt('resume_matin', '07:15', 2));
    }

    // ---- Score de pertinence ----------------------------------------------------------------

    public function test_relevance_score_rewards_the_target_profile(): void
    {
        $good = $this->offer();
        $far = $this->offer(['company' => 'Firmenich', 'location' => 'Genève']);
        $part = $this->offer(['company' => 'Pro Senectute', 'rate' => '50 %']);

        $this->assertGreaterThanOrEqual(80, $good->relevance_score);
        $this->assertLessThan($good->relevance_score, $far->relevance_score);
        $this->assertLessThan($good->relevance_score, $part->relevance_score);
        $this->assertNotEmpty($good->relevance_reasons);
        $this->assertStringContainsString('région lausannoise', implode(' ', $good->relevance_reasons));
    }

    public function test_hep_offers_are_excluded(): void
    {
        $this->assertSame(0, $this->offer(['company' => 'HEP Fribourg'])->relevance_score);
    }

    public function test_ignored_offers_lower_similar_scores(): void
    {
        $before = $this->offer(['title' => 'Responsable marketing pharmaceutique'])->relevance_score;

        foreach (['Bobst', 'Logitech'] as $company) {
            $this->offer(['company' => $company, 'title' => 'Chef de produit pharmaceutique'])->update(['status' => OpportunityStatus::Discarded]);
        }
        RelevanceScorer::refreshAll();

        $after = Opportunity::query()->where('title', 'Responsable marketing pharmaceutique')->first();
        $this->assertLessThan($before, $after->relevance_score);
        $this->assertStringContainsString('ignorées', implode(' ', $after->relevance_reasons));
    }

    // ---- 1. Candidature préparée d'office ---------------------------------------------------

    public function test_top_rated_offer_with_documents_is_prepared_but_never_sent(): void
    {
        Notification::fake();
        Settings::save(['signature' => 'Pierre-Ollivier Tissier']);
        $offer = $this->offer([
            'relevance' => 3,
            'contact_email' => 'rh@vaudoise.ch',
            'message_subject' => 'Candidature – Responsable marketing digital',
            'message_body' => "Madame, Monsieur,\n\nVeuillez trouver ma candidature.",
        ]);

        $result = app(AutoPreparer::class)->run();

        $offer->refresh();
        $this->assertSame(1, $result['prepared']);
        $this->assertSame(ApplicationStatus::ToValidate, $offer->application->status);
        $this->assertSame('rh@vaudoise.ch', $offer->application->to_email);
        $this->assertStringContainsString('Pierre-Ollivier Tissier', $offer->application->body);
        $this->assertNotNull($offer->application->auto_prepared_at);
        $this->assertSame(OpportunityStatus::InPreparation, $offer->status);
        $this->assertNull($offer->application->sent_at);
        Notification::assertSentTo($this->owner, OwnerAlert::class);
        Http::assertNothingSent();

        // Deuxième passage : rien n'est refait ni renotifié.
        $this->assertSame(0, app(AutoPreparer::class)->run()['prepared']);
    }

    public function test_application_edited_by_hand_is_not_overwritten(): void
    {
        $offer = $this->offer(['relevance' => 3, 'message_body' => 'Texte du registre']);
        $offer->application()->create(['status' => ApplicationStatus::ToValidate, 'to_email' => 'a@b.ch', 'subject' => 'Mon objet', 'body' => 'Mon texte']);

        app(AutoPreparer::class)->run();

        $this->assertSame('Mon texte', $offer->fresh()->application->body);
    }

    public function test_offer_without_documents_or_low_score_is_left_alone(): void
    {
        $this->offer(['relevance' => 3]);
        $this->offer(['relevance' => 1, 'company' => 'Firmenich', 'location' => 'Genève', 'message_body' => 'Texte']);
        Settings::save(['auto_prepare_min_score' => 95]);

        $this->assertSame(0, app(AutoPreparer::class)->run()['prepared']);
    }

    // ---- 4. Relances --------------------------------------------------------------------------

    private function sentOffer(array $attributes = []): Opportunity
    {
        $offer = $this->offer(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(10), ...$attributes]);
        $offer->application()->create([
            'status' => ApplicationStatus::Sent, 'to_email' => 'rh@vaudoise.ch', 'subject' => 'Candidature – Responsable marketing digital',
            'body' => 'Texte', 'sent_at' => now()->subDays(10), 'gmail_thread_id' => 'thread-42',
        ]);

        return $offer->fresh();
    }

    public function test_follow_up_creates_a_gmail_draft_in_the_thread_and_never_sends(): void
    {
        Notification::fake();
        $this->connect();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/drafts' => Http::response(['id' => 'draft-9'])]);
        $offer = $this->sentOffer();

        $result = app(FollowUps::class)->run();

        $this->assertSame(1, $result['drafts']);
        $this->assertSame('draft-9', $offer->fresh()->follow_up_draft_id);
        Http::assertSent(function (Request $request) {
            $raw = Gmail::unb64url($request['message']['raw']);

            return str_ends_with($request->url(), '/drafts')
                && $request['message']['threadId'] === 'thread-42'
                && str_contains($raw, 'Re: Candidature')
                && str_contains($raw, 'rh@vaudoise.ch');
        });
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/send'));

        // Pas de second brouillon le lendemain.
        $this->assertSame(0, app(FollowUps::class)->run()['drafts']);
    }

    public function test_reminder_after_the_follow_up(): void
    {
        Notification::fake();
        $offer = $this->offer(['status' => OpportunityStatus::FollowedUp, 'applied_at' => today()->subDays(40)]);
        $offer->forceFill(['followed_up_at' => today()->subDays(22)])->saveQuietly();

        $this->assertSame(1, app(FollowUps::class)->run()['reminders']);
        $this->assertSame(0, app(FollowUps::class)->run()['reminders']);
    }

    // ---- 5. Suivi des réponses --------------------------------------------------------------

    public function test_classification(): void
    {
        $this->assertSame('refus', ResponseTracker::classify('Merci pour votre candidature. Malheureusement, nous avons retenu un autre profil.'));
        $this->assertSame('entretien', ResponseTracker::classify('Nous souhaiterions vous rencontrer. Merci de nous indiquer vos disponibilités.'));
        $this->assertSame('accuse', ResponseTracker::classify('Nous avons bien reçu votre candidature.'));
        $this->assertSame('autre', ResponseTracker::classify('Bonjour, une question sur votre dossier.'));
    }

    public function test_refusal_in_the_application_thread_updates_the_status(): void
    {
        Notification::fake();
        $this->connect();
        $offer = $this->sentOffer();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/threads/thread-42*' => Http::response(['messages' => [
                self::gmailMessage('m1', 'thread-42', 'owner@example.test', 'Candidature', 'Ma candidature', ['SENT'], now()->subDays(10)->getTimestampMs()),
                self::gmailMessage('m2', 'thread-42', 'RH Vaudoise <rh@vaudoise.ch>', 'Re: Candidature', 'Malheureusement, nous ne pouvons pas donner une suite favorable.'),
            ]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => []]),
        ]);

        $result = app(ResponseTracker::class)->run();

        $this->assertSame(1, $result['found']);
        $this->assertSame(OpportunityStatus::Rejected, $offer->fresh()->status);
        $this->assertSame('refus', $offer->fresh()->response_type);
        Notification::assertSentTo($this->owner, OwnerAlert::class);

        // Le même message n'est jamais traité deux fois.
        $this->assertSame(0, app(ResponseTracker::class)->run()['found']);
    }

    public function test_interview_invitation_in_the_jobs_label_creates_the_prep_sheet(): void
    {
        Notification::fake();
        $this->connect();
        Settings::save(['proof_bank' => "| # | Preuve | EN | Mots-clés | Force |\n|---|---|---|---|---|\n| P1 | Refonte d'un site de 4 500 pages | Redesign | refonte, web, digital | ★★★ |\n| P2 | Campagnes SEA | SEA | sea, campagnes | ★★ |"]);
        $offer = $this->offer(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(5), 'contact_email' => 'jobs@vaudoise.ch']);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm7']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m7*' => Http::response(self::gmailMessage('m7', 't7', 'Talent <talent@vaudoise.ch>', 'Votre candidature', 'Nous aimerions vous rencontrer : vos disponibilités ?', ['INBOX', 'Label_1'])),
        ]);

        app(ResponseTracker::class)->run();

        $offer->refresh();
        $this->assertSame(OpportunityStatus::Interview, $offer->status);
        $this->assertNotNull($offer->interview_prep);
        $this->assertStringContainsString('Pourquoi souhaitez-vous rejoindre Vaudoise Assurances', $offer->interview_prep);
        $this->assertStringContainsString('P1', $offer->interview_prep);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'q=label:Jobs'));
    }

    public function test_job_alerts_are_not_taken_for_answers(): void
    {
        $this->connect();
        $offer = $this->offer(['company' => 'Nestlé', 'status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(5)]);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'a1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/a1*' => Http::response(self::gmailMessage('a1', 'ta', 'jobup <alerts@jobup.ch>', 'Nouvelles offres : Nestlé recrute', 'Malheureusement…')),
        ]);

        $this->assertSame(0, app(ResponseTracker::class)->run()['found']);
        $this->assertSame(OpportunityStatus::Applied, $offer->fresh()->status);
    }

    // ---- 6. Préparation d'entretien ---------------------------------------------------------

    public function test_proof_bank_table_is_parsed_and_rules_are_ignored(): void
    {
        $bank = InterviewPrep::parseBank("Règles :\n- Ne jamais inventer\n\n| # | Preuve | EN | Mots-clés | Force |\n|---|---|---|---|---|\n| P1 | A | B | web | ★ |");

        $this->assertCount(1, $bank);
        $this->assertSame('P1', $bank[0]['id']);
    }

    // ---- 7. Veille ----------------------------------------------------------------------------

    public function test_new_offer_from_a_target_company_is_a_signal(): void
    {
        Notification::fake();
        $prospect = Prospect::factory()->create(['company' => 'Romande Energie SA', 'status' => ProspectStatus::ToQualify]);
        $this->offer(['company' => 'Romande Energie', 'title' => 'Chef de projet web']);

        $result = app(TargetWatch::class)->run();

        $this->assertSame(1, $result['signals']);
        $this->assertStringContainsString('Nouvelle offre publiée', $prospect->fresh()->signal);
    }

    public function test_alert_email_mentioning_a_target_is_a_signal(): void
    {
        Notification::fake();
        $this->connect();
        $prospect = Prospect::factory()->create(['company' => 'Loyco', 'status' => ProspectStatus::ToQualify]);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'g1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/g1*' => Http::response(self::gmailMessage('g1', 'tg', 'Google Alerts <alerts@google.com>', 'Alerte Google : Loyco', 'Loyco lève 12 millions de francs')),
        ]);

        app(TargetWatch::class)->run();

        $this->assertStringContainsString('Levée de fonds', $prospect->fresh()->signal);
    }

    // ---- 3 et 8. Résumé du matin et bilan ---------------------------------------------------

    public function test_morning_digest_lists_items_with_links(): void
    {
        Notification::fake();
        $offer = $this->offer();
        $offer->application()->create(['status' => ApplicationStatus::ToValidate, 'to_email' => 'a@b.ch', 'subject' => 'S', 'body' => 'B']);

        $result = app(MorningDigest::class)->send();

        $this->assertTrue($result['sent']);
        $this->assertSame(1, $result['counts']['validate']);
        Notification::assertSentTo($this->owner, OwnerAlert::class, function (OwnerAlert $n) use ($offer) {
            $html = (string) $n->toMail($this->owner)->render();

            return str_contains($html, 'Vaudoise Assurances') && str_contains($html, '/opportunites/'.$offer->id);
        });
    }

    public function test_morning_digest_is_skipped_when_there_is_nothing(): void
    {
        Notification::fake();
        AutomationRun::mark('resume_matin');

        $this->assertFalse(app(MorningDigest::class)->send()['sent']);
        Notification::assertNothingSent();
    }

    public function test_weekly_report(): void
    {
        Notification::fake();
        foreach (['Vaudoise', 'Retraites Populaires', 'BCV', 'CHUV'] as $i => $company) {
            $o = $this->offer(['company' => $company, 'source' => 'jobup', 'status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays($i)]);
            if ($i === 0) {
                $o->update(['status' => OpportunityStatus::Interview]);
            }
        }

        $report = app(WeeklyReport::class)->send();

        $this->assertSame(4, $report['week']['sent']);
        $this->assertSame(25, $report['total']['rate']);
        $this->assertNotEmpty($report['suggestions']);
        Notification::assertSentTo($this->owner, OwnerAlert::class, fn (OwnerAlert $n) => str_contains((string) $n->toMail($this->owner)->render(), 'Par source'));
    }

    public function test_offer_page_shows_score_and_interview_sheet(): void
    {
        $offer = $this->offer(['status' => OpportunityStatus::Interview]);

        $this->actingAs($this->owner)
            ->get('/opportunites/'.$offer->id)
            ->assertOk()
            ->assertSee('Score de pertinence')
            ->assertSee('Préparation d&#039;entretien', false)
            ->assertSee('Questions probables');

        $this->actingAs($this->owner)->get('/opportunites')->assertOk()->assertSee('/ 100');
    }

    public function test_command_runs_all_tasks_on_demand(): void
    {
        Notification::fake();
        $this->artisan('automatisations:executer', ['--force' => true])->assertSuccessful();

        $this->assertNotNull(AutomationRun::lastRun('preparation'));
        $this->assertNotNull(AutomationRun::lastRun('bilan'));
    }
}
