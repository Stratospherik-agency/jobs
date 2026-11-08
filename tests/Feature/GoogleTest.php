<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Models\Application;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationDraftImporter;
use App\Services\ApplicationSender;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    private function connect(array $attributes = []): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'email' => 'owner@example.test',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(),
            ...$attributes,
        ]);
    }

    private function application(array $attributes = []): Application
    {
        $opportunity = Opportunity::factory()->create(['company' => 'Nestlé', 'title' => 'Communications Manager']);
        $application = $opportunity->application()->create([
            'status' => ApplicationStatus::ToValidate,
            'to_email' => 'jobs@nestle.com',
            'subject' => 'Candidature – Communications Manager',
            'body' => "Madame, Monsieur,\n\nVeuillez trouver ma candidature.",
            ...$attributes,
        ]);
        Storage::disk('local')->put('candidatures/1/cv.pdf', '%PDF-1.4 cv');
        $application->attachments()->create(['name' => 'CV Pierre.pdf', 'path' => 'candidatures/1/cv.pdf', 'mime' => 'application/pdf', 'size' => 11]);

        return $application->fresh();
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'msg-1', 'threadId' => 'thread-1']),
            'www.googleapis.com/drive/v3/files*' => Http::response(['id' => 'folder-1', 'webViewLink' => 'https://drive.google.com/folder-1']),
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'file-1', 'webViewLink' => 'https://drive.google.com/file-1']),
        ]);
    }

    public function test_sending_a_validated_application(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $application = $this->application();

        app(ApplicationSender::class)->send($application);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Sent, $application->status);
        $this->assertSame('thread-1', $application->gmail_thread_id);
        $this->assertSame('https://drive.google.com/folder-1', $application->drive_folder_url);
        $this->assertSame(OpportunityStatus::Applied, $application->opportunity->status);
        $this->assertNotNull($application->opportunity->follow_up_at);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/messages/send')) {
                return false;
            }
            $mime = Gmail::unb64url($request['raw']);

            return str_contains($mime, 'To: jobs@nestle.com')
                && str_contains($mime, 'From: owner@example.test')
                && str_contains($mime, 'CV Pierre.pdf')
                && $request->header('Authorization')[0] === 'Bearer access-1';
        });
    }

    public function test_an_application_is_never_sent_twice(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $application = $this->application();

        app(ApplicationSender::class)->send($application);

        $this->expectException(GoogleException::class);
        app(ApplicationSender::class)->send($application);
    }

    public function test_incomplete_application_is_refused(): void
    {
        $this->connect();
        $application = $this->application(['to_email' => 'pas-une-adresse']);

        try {
            app(ApplicationSender::class)->send($application);
            $this->fail('Une exception était attendue.');
        } catch (GoogleException $e) {
            $this->assertStringContainsString('adresse', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(ApplicationStatus::ToValidate, $application->fresh()->status);
    }

    public function test_failed_send_is_recorded(): void
    {
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => ['message' => 'Quota dépassé']], 429)]);
        $application = $this->application();

        try {
            app(ApplicationSender::class)->send($application);
        } catch (GoogleException $e) {
        }

        $this->assertSame(ApplicationStatus::Failed, $application->fresh()->status);
        $this->assertStringContainsString('Quota dépassé', $application->fresh()->last_error);
        $this->assertSame(OpportunityStatus::ToEvaluate, $application->opportunity->fresh()->status);
    }

    public function test_expired_token_is_refreshed(): void
    {
        $this->connect(['expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'expires_in' => 3600])]);

        $this->assertSame('access-2', app(GoogleClient::class)->accessToken());
        $this->assertSame('access-2', GoogleConnection::current()->access_token);
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $this->connect();

        $raw = \DB::table('google_connections')->first();
        $this->assertNotSame('refresh-1', $raw->refresh_token);
        $this->assertNotSame('access-1', $raw->access_token);
    }

    public function test_oauth_callback_with_wrong_state_is_rejected(): void
    {
        $this->actingAs($this->owner())
            ->withSession(['google_oauth' => ['state' => 'bon-state', 'verifier' => 'v']])
            ->get('/google/callback?code=abc&state=mauvais-state')
            ->assertRedirect('/compte-google');

        Http::assertNothingSent();
        $this->assertNull(GoogleConnection::current());
    }

    public function test_oauth_callback_connects_the_owner_account(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets.readonly',
            ]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'Owner@example.test']),
        ]);

        $this->actingAs($this->owner())
            ->withSession(['google_oauth' => ['state' => 's', 'verifier' => 'v']])
            ->get('/google/callback?code=abc&state=s')
            ->assertRedirect('/compte-google');

        $this->assertSame('Owner@example.test', GoogleConnection::current()?->email);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/token') && $r['code_verifier'] === 'v');
    }

    public function test_oauth_callback_rejects_another_google_account(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/drive.file',
            ]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'intrus@example.test']),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);

        $this->actingAs($this->owner())
            ->withSession(['google_oauth' => ['state' => 's', 'verifier' => 'v']])
            ->get('/google/callback?code=abc&state=s');

        $this->assertNull(GoogleConnection::current());
    }

    public function test_google_routes_require_login(): void
    {
        $this->get('/google/callback?code=x&state=y')->assertRedirect('/login');
        $this->get('/pieces-jointes/1')->assertRedirect('/login');
        $this->get('/compte-google')->assertRedirect('/login');
    }

    public function test_owner_can_download_an_attachment(): void
    {
        $application = $this->application();

        $this->actingAs($this->owner())
            ->get('/pieces-jointes/'.$application->attachments->first()->id)
            ->assertOk()
            ->assertDownload('CV Pierre.pdf');
    }

    public function test_importing_a_gmail_draft(): void
    {
        $this->connect();
        $opportunity = Opportunity::factory()->create();
        $pdf = Gmail::b64url('%PDF-1.4 lettre');

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/drafts/d1*' => Http::response(['id' => 'd1', 'message' => [
                'id' => 'm1',
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'headers' => [
                        ['name' => 'To', 'value' => 'RH <rh@entreprise.ch>'],
                        ['name' => 'Subject', 'value' => 'Candidature – Responsable marketing'],
                    ],
                    'parts' => [
                        ['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => Gmail::b64url('Bonjour,')]],
                        ['mimeType' => 'application/pdf', 'filename' => 'Lettre.pdf', 'body' => ['attachmentId' => 'att1']],
                    ],
                ],
            ]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1/attachments/att1' => Http::response(['data' => $pdf]),
        ]);

        $application = app(ApplicationDraftImporter::class)->import($opportunity, 'd1');

        $this->assertSame('rh@entreprise.ch', $application->to_email);
        $this->assertSame('Bonjour,', $application->body);
        $this->assertSame(ApplicationStatus::ToValidate, $application->status);
        $this->assertSame('d1', $application->source_draft_id);
        $this->assertSame('Lettre.pdf', $application->attachments->first()->name);
        $this->assertSame('%PDF-1.4 lettre', $application->attachments->first()->contents());
    }

    public function test_correspondence_query(): void
    {
        $this->assertSame('(from:@nestle.com OR to:@nestle.com)', Gmail::queryFor('jobs@nestle.com'));
        $this->assertSame('(from:rh.boite@gmail.com OR to:rh.boite@gmail.com)', Gmail::queryFor('rh.boite@gmail.com'));
        $this->assertNull(Gmail::queryFor(null));
    }

    public function test_view_page_renders_with_an_application(): void
    {
        $application = $this->application();

        $this->actingAs($this->owner())
            ->get('/opportunites/'.$application->opportunity_id)
            ->assertOk()
            ->assertSee('Valider et envoyer')
            ->assertSee('jobs@nestle.com');

        $this->get('/compte-google')->assertOk()->assertSee('Non connecté');
    }

    public function test_deleting_an_opportunity_removes_attachment_files(): void
    {
        $application = $this->application();

        $application->opportunity->delete();

        Storage::disk('local')->assertMissing('candidatures/1/cv.pdf');
    }
}
