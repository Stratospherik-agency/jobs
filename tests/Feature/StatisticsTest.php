<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Statistics\OrpReport;
use App\Services\Statistics\Statistics;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    public function test_statistics_pages_require_login(): void
    {
        foreach (['vue-d-ensemble', 'temps', 'sources', 'retours', 'explorateur', 'justificatif-orp', 'orp/2026-11/csv', 'orp/2026-11/imprimer'] as $path) {
            $this->get('/statistiques/'.$path)->assertRedirect('/login');
        }
    }

    public function test_statistics_pages_render_for_the_owner(): void
    {
        Opportunity::factory()->count(3)->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(5), 'source' => 'Indeed']);
        $this->actingAs($this->owner());

        foreach (['vue-d-ensemble', 'temps', 'sources', 'retours', 'explorateur', 'justificatif-orp'] as $path) {
            $this->get('/statistiques/'.$path)->assertOk();
        }
    }

    public function test_catalog_parses_rates_sources_and_contracts(): void
    {
        $this->assertSame([80, 100], Catalog::parseRate('80-100 %'));
        $this->assertSame([100, 100], Catalog::parseRate('100%'));
        $this->assertSame([null, 70], Catalog::parseRate('Temps partiel'));
        $this->assertSame('Google Careers', Catalog::normalizeSource('Google Careers'));
        $this->assertSame('Candidature spontanée', Catalog::normalizeSource('spontanée'));
        $this->assertSame('cdi', Catalog::contractFromText('100 %, CDI'));
    }

    public function test_status_changes_fill_the_follow_up_facts(): void
    {
        $o = Opportunity::factory()->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(10), 'rate' => '80-100 %, CDI']);
        $this->assertSame(80, $o->rate_min);
        $this->assertSame('cdi', $o->contract_type);

        $o->update(['status' => OpportunityStatus::Rejected]);
        $this->assertSame('refus', $o->response_type);
        $this->assertNotNull($o->first_response_at);
    }

    public function test_response_rate_counts_only_sent_applications(): void
    {
        Opportunity::factory()->create(['status' => OpportunityStatus::ToEvaluate, 'source' => 'Indeed']);
        Opportunity::factory()->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()->subDays(3), 'source' => 'Indeed']);
        Opportunity::factory()->create(['status' => OpportunityStatus::Interview, 'applied_at' => today()->subDays(4), 'source' => 'jobup']);

        $data = Statistics::dataset(['period' => '30']);
        $rate = Statistics::measure('response_rate', $data);

        $this->assertSame(50.0, $rate['value']);
        $this->assertSame(2, $rate['n']);

        $table = Statistics::crosstab($data, 'response_rate', 'source');
        $this->assertContains('Indeed', $table['labels']);
        $this->assertContains('jobup', $table['labels']);
    }

    public function test_orp_csv_lists_the_month_and_neutralises_formulas(): void
    {
        Opportunity::factory()->create(['company' => '=HYPERLINK("x")', 'title' => 'Responsable digital', 'status' => OpportunityStatus::Applied, 'applied_at' => '2026-10-12', 'source' => 'Indeed']);
        Opportunity::factory()->create(['company' => 'Autre mois', 'status' => OpportunityStatus::Applied, 'applied_at' => '2026-09-01']);

        $csv = OrpReport::csv(OrpReport::month('2026-10'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Responsable digital', $csv);
        $this->assertStringNotContainsString('Autre mois', $csv);

        $this->actingAs($this->owner());
        $this->get('/statistiques/orp/2026-10/csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/statistiques/orp/2026-10/imprimer')->assertOk()->assertSee('Responsable digital');
    }
}
