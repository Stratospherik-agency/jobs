<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RegistrySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrySyncTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ["Date d'ajout", 'Entreprise', 'Poste', 'Lieu', 'Taux', 'Source', 'Lien', 'Publiée le', 'Pertinence (1-3)', 'Statut', 'Date de candidature', 'Relance prévue', 'Retour', 'Notes'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function connect(string $scopes = 'openid email https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets.readonly'): void
    {
        GoogleConnection::query()->create([
            'email' => 'owner@example.test', 'access_token' => 'a', 'refresh_token' => 'r',
            'expires_at' => now()->addHour(), 'scopes' => $scopes,
        ]);
    }

    private array $sheet = [];

    private bool $faked = false;

    private function fakeSheet(array ...$rows): void
    {
        $this->sheet = [self::HEADER, ...$rows];

        if (! $this->faked) {
            $this->faked = true;
            Http::fake(['sheets.googleapis.com/*' => fn () => Http::response(['values' => $this->sheet])]);
        }
    }

    private function row(string $status = 'À évaluer', string $notes = ''): array
    {
        return ['2026-11-07', 'Neural Concept', 'Technology Marketing Manager', 'Lausanne', '100%', 'Indeed', 'https://to.indeed.com/aa66qyfklys8', '2026-11-05', '2', $status, '', '', '', $notes];
    }

    public function test_new_offers_are_created(): void
    {
        $this->connect();
        $this->fakeSheet($this->row('En préparation'), ['2026-11-08', 'Planzer', 'Content Manager']);

        $result = app(RegistrySync::class)->sync();

        $this->assertSame(2, $result['created']);
        $this->assertSame(OpportunityStatus::InPreparation, Opportunity::query()->where('company', 'Neural Concept')->first()->status);
        $this->assertNotNull(GoogleConnection::current()->registry_synced_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'Offres%21A1%3AZ1000'));
    }

    public function test_only_registry_changes_are_applied(): void
    {
        $this->connect();
        $this->fakeSheet($this->row());
        app(RegistrySync::class)->sync();

        // Pierre ajoute une note dans le tableau de bord et passe l'offre à « À postuler ».
        $opportunity = Opportunity::query()->first();
        $opportunity->update(['status' => OpportunityStatus::ToApply, 'feedback' => 'Appel RH prévu']);

        // Le registre ne change rien : le suivi du tableau de bord est conservé.
        $this->fakeSheet($this->row());
        $result = app(RegistrySync::class)->sync();
        $this->assertSame(0, $result['updated']);
        $this->assertSame(OpportunityStatus::ToApply, $opportunity->fresh()->status);

        // Le registre passe en « En préparation » et ajoute une note : c'est reporté.
        $this->fakeSheet($this->row('En préparation', 'CV prêt dans Canva'));
        $result = app(RegistrySync::class)->sync();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(OpportunityStatus::InPreparation, $opportunity->fresh()->status);
        $this->assertSame('CV prêt dans Canva', $opportunity->fresh()->notes);
        $this->assertSame('Appel RH prévu', $opportunity->fresh()->feedback);
    }

    public function test_offer_imported_by_hand_is_not_moved_backwards(): void
    {
        $this->connect();
        $opportunity = Opportunity::factory()->create([
            'company' => 'Neural Concept', 'title' => 'Technology Marketing Manager', 'url' => null,
            'status' => OpportunityStatus::Applied, 'notes' => null,
        ]);
        $this->fakeSheet($this->row('À évaluer', 'Note du registre'));

        app(RegistrySync::class)->sync();

        $this->assertSame(OpportunityStatus::Applied, $opportunity->fresh()->status);
        $this->assertSame('Note du registre', $opportunity->fresh()->notes);
    }

    public function test_sync_requires_the_sheets_permission(): void
    {
        $this->connect('openid email https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/drive.file');

        $this->assertStringContainsString('Reconnecte', RegistrySync::unavailableReason());
        app(RegistrySync::class)->syncIfStale();
        Http::assertNothingSent();
    }

    public function test_sync_is_skipped_when_recent(): void
    {
        $this->connect();
        GoogleConnection::current()->forceFill(['registry_synced_at' => now()->subMinute()])->save();

        app(RegistrySync::class)->syncIfStale();

        Http::assertNothingSent();
    }

    public function test_errors_are_recorded_and_do_not_break_pages(): void
    {
        $this->connect();
        Http::fake(['sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'Accès refusé']], 403)]);
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($owner)->get('/opportunites')->assertOk();

        $this->assertStringContainsString('Accès refusé', GoogleConnection::current()->registry_sync_error);
        $this->get('/compte-google')->assertOk()->assertSee('Accès refusé');
    }

    public function test_command_without_google_connection(): void
    {
        $this->artisan('registre:synchroniser')->assertSuccessful();
        Http::assertNothingSent();
    }
}
