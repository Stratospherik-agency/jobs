<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RegistryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    public function test_importing_the_registry_creates_opportunities_and_skips_duplicates(): void
    {
        $importer = app(RegistryImporter::class);

        $first = $importer->importFile(base_path('tests/fixtures/registre.csv'));

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertCount(2, $first['errors']); // poste manquant + statut inconnu

        $nestle = Opportunity::query()->where('company', 'Nestlé')->firstOrFail();
        $this->assertSame(OpportunityStatus::Applied, $nestle->status);
        $this->assertSame('2026-11-01', $nestle->applied_at->toDateString());
        $this->assertSame('2026-11-11', $nestle->follow_up_at->toDateString());
        $this->assertSame(3, $nestle->relevance);

        $second = $importer->importFile(base_path('tests/fixtures/registre.csv'));
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['skipped']);
        $this->assertSame(3, Opportunity::query()->count());
    }

    public function test_marking_as_applied_fills_dates_and_logs_history(): void
    {
        $opportunity = Opportunity::factory()->create();

        $opportunity->update(['status' => OpportunityStatus::Applied]);

        $this->assertTrue($opportunity->applied_at->isToday());
        $this->assertSame(today()->addDays(10)->toDateString(), $opportunity->follow_up_at->toDateString());
        $this->assertSame(OpportunityStatus::Applied, $opportunity->events()->first()->to_status);
        $this->assertSame(OpportunityStatus::ToEvaluate, $opportunity->events()->first()->from_status);
    }

    public function test_closing_a_file_clears_the_follow_up(): void
    {
        $opportunity = Opportunity::factory()->create(['status' => OpportunityStatus::Applied]);

        $opportunity->update(['status' => OpportunityStatus::Rejected]);

        $this->assertNull($opportunity->fresh()->follow_up_at);
    }

    public function test_follow_up_due_scope(): void
    {
        $due = Opportunity::factory()->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(12)]);
        Opportunity::factory()->create(['status' => OpportunityStatus::Applied]); // relance dans 10 jours
        Opportunity::factory()->create(['status' => OpportunityStatus::ToEvaluate]);

        $this->assertEquals([$due->id], Opportunity::query()->followUpDue()->pluck('id')->all());
    }

    public function test_status_labels_round_trip(): void
    {
        foreach (OpportunityStatus::cases() as $status) {
            $this->assertSame($status, OpportunityStatus::fromLabel($status->getLabel()));
        }
    }

    public function test_pages_render_for_the_owner(): void
    {
        $this->actingAs($this->owner());
        $opportunity = Opportunity::factory()->create(['company' => 'Neural Concept']);

        $this->get('/')->assertOk()->assertSee('Tableau de bord');
        $this->get(OpportunityResource::getUrl('index'))->assertOk()->assertSee('Neural Concept');
        $this->get(OpportunityResource::getUrl('view', ['record' => $opportunity]))->assertOk()->assertSee('Neural Concept');
        $this->get(OpportunityResource::getUrl('edit', ['record' => $opportunity]))->assertOk();
        $this->get(OpportunityResource::getUrl('create'))->assertOk();
    }

    public function test_import_command(): void
    {
        $this->artisan('registre:importer', ['fichier' => base_path('tests/fixtures/registre.csv')])
            ->expectsOutputToContain('3 offre(s) importée(s)')
            ->assertSuccessful();
    }

    public function test_a_failing_registry_row_does_not_block_the_others(): void
    {
        Opportunity::creating(function (Opportunity $opportunity) {
            if ($opportunity->company === 'Ligne en panne') {
                throw new \RuntimeException('colonne trop longue');
            }
        });

        $result = app(RegistryImporter::class)->sync([
            ['Entreprise', 'Poste', 'Lien'],
            ['Ligne en panne', 'Poste A', 'https://example.test/a'],
            ['Farner Consulting AG', 'Consultant·e en communication 100%', 'https://ch.indeed.com/viewjob?jk=19f817366b57ef5d'],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['Ligne 2 : colonne trop longue'], $result['errors']);
        $this->assertDatabaseHas('opportunities', ['company' => 'Farner Consulting AG']);
        $this->assertDatabaseMissing('opportunities', ['company' => 'Ligne en panne']);
    }
}
