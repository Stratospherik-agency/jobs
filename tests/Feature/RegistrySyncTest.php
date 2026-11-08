<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Models\GoogleConnection;
use App\Models\Opportunity;
use App\Models\Prospect;
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

    /** Onglet « Spontanées » : null = absent du classeur. */
    private ?array $prospectSheet = null;

    private function fakeSheet(array ...$rows): void
    {
        $this->sheet = [self::HEADER, ...$rows];

        if (! $this->faked) {
            $this->faked = true;
            Http::fake(['sheets.googleapis.com/*' => function ($request) {
                if (str_contains(rawurldecode($request->url()), 'Spontanées')) {
                    return $this->prospectSheet === null
                        ? Http::response(['error' => ['message' => 'Unable to parse range: Spontanées!A1:AA1000']], 400)
                        : Http::response(['values' => $this->prospectSheet]);
                }

                return Http::response(['values' => $this->sheet]);
            }]);
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

    private const PROSPECT_HEADER = ["Date d'ajout", 'Entreprise', 'Famille', 'Secteur', 'Localité', 'Site web', 'Employés', 'Signal', 'Date du signal', 'Source du signal', 'Raisons', "Pistes d'apport", 'Contact', 'Fonction', 'E-mail', 'LinkedIn', 'Canal', 'Note (1-3)', 'Langue', 'Statut', "Date d'envoi", 'Relance prévue', 'Date de réponse', 'Type de réponse', 'Recontact', 'Documents', 'Notes'];

    public function test_spontaneous_tab_creates_targets_and_skips_web_agencies(): void
    {
        $this->connect();
        $this->prospectSheet = [
            self::PROSPECT_HEADER,
            ['2026-11-08', 'Bobst', 'Grande', 'Industrie', 'Mex', 'bobst.com', '6000', 'Nouvelle usine', '2026-10-20', 'https://www.24heures.ch/x', 'Croissance internationale', 'Structurer la communication digitale', '', '', '', '', 'Portail carrière', '3', 'FR', 'À qualifier'],
            ['2026-11-08', 'Pixel Studio', 'Petite', 'Agence web', 'Lausanne'],
        ];
        $this->fakeSheet($this->row());

        $result = app(RegistrySync::class)->sync();

        $this->assertSame(1, $result['prospects']['created']);
        $this->assertStringContainsString('agence web exclue', $result['prospects']['errors'][0]);
        $bobst = Prospect::query()->firstWhere('company', 'Bobst');
        $this->assertSame('grande', $bobst->family);
        $this->assertSame('portail', $bobst->channel);
        $this->assertSame('https://bobst.com', $bobst->website);
        $this->assertStringContainsString('Candidatures spontanées : 1 ajoutée(s)', GoogleConnection::current()->registry_sync_summary);
    }

    public function test_missing_spontaneous_tab_is_not_an_error(): void
    {
        $this->connect();
        $this->fakeSheet($this->row());

        $result = app(RegistrySync::class)->sync();

        $this->assertNull($result['prospects']);
        $this->assertNull(GoogleConnection::current()->registry_sync_error);
    }
}
