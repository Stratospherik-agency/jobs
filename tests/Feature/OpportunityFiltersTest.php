<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OpportunityFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($owner);
    }

    public function test_one_click_ignore_hides_the_offer_and_it_can_be_restored(): void
    {
        $offer = Opportunity::factory()->create(['status' => OpportunityStatus::ToEvaluate]);

        Livewire::test(ListOpportunities::class)
            ->callTableAction('ignore', $offer)
            ->assertCanNotSeeTableRecords([$offer]);

        $this->assertSame(OpportunityStatus::Discarded, $offer->fresh()->status);

        Livewire::test(ListOpportunities::class)
            ->filterTable('open', false)
            ->assertCanSeeTableRecords([$offer])
            ->callTableAction('restore', $offer);

        $this->assertSame(OpportunityStatus::ToEvaluate, $offer->fresh()->status);
    }

    public function test_sent_applications_cannot_be_ignored(): void
    {
        $sent = Opportunity::factory()->create(['status' => OpportunityStatus::Applied, 'applied_at' => today()]);

        Livewire::test(ViewOpportunity::class, ['record' => $sent->id])->assertActionHidden('ignore');
    }

    public function test_filters_by_zone_rate_platform_and_relevance(): void
    {
        $lausanne = Opportunity::factory()->create(['location' => 'Lausanne', 'rate' => '80-100 %', 'source' => 'Indeed', 'relevance' => 3]);
        $vevey = Opportunity::factory()->create(['location' => 'Vevey', 'rate' => '60 %', 'source' => 'jobup.ch', 'relevance' => 2]);
        $morges = Opportunity::factory()->create(['location' => 'Morges', 'rate' => '100%', 'source' => 'LinkedIn', 'relevance' => 1]);

        Livewire::test(ListOpportunities::class)
            ->filterTable('zone', ['Lavaux et Riviera'])
            ->assertCanSeeTableRecords([$vevey])
            ->assertCanNotSeeTableRecords([$lausanne, $morges]);

        Livewire::test(ListOpportunities::class)
            ->filterTable('rate', ['rate' => '80'])
            ->assertCanSeeTableRecords([$lausanne, $morges])
            ->assertCanNotSeeTableRecords([$vevey]);

        Livewire::test(ListOpportunities::class)
            ->filterTable('source', ['jobup'])
            ->assertCanSeeTableRecords([$vevey])
            ->assertCanNotSeeTableRecords([$lausanne, $morges]);

        Livewire::test(ListOpportunities::class)
            ->filterTable('relevance', [3, 2])
            ->assertCanSeeTableRecords([$lausanne, $vevey])
            ->assertCanNotSeeTableRecords([$morges]);
    }

    public function test_documents_can_be_added_without_an_email_and_survive_the_registry_sync(): void
    {
        Storage::fake('local');
        $offer = Opportunity::factory()->create();
        $cv = UploadedFile::fake()->createWithContent('Sonic-Suisse_CV_pierre-ollivier_tissier.pdf', "%PDF-1.4\n%%EOF");

        Livewire::test(ViewOpportunity::class, ['record' => $offer->id])
            ->callAction('add_documents', ['files' => [$cv]])
            ->assertHasNoActionErrors();

        $application = $offer->fresh()->application;
        $this->assertNull($application->to_email);
        $this->assertSame(['Sonic-Suisse_CV_pierre-ollivier_tissier.pdf'], $application->attachments->pluck('name')->all());
        $this->assertNull($application->attachments->first()->source_hash);

        // La synchro des liens Canva ne retire pas un document ajouté à la main.
        $offer->update(['documents_links' => 'Lettre.pdf : https://export-download.canva.com/x/lettre.pdf']);
        \Illuminate\Support\Facades\Http::fake(["*" => \Illuminate\Support\Facades\Http::response("", 404)]);
        app(\App\Services\DocumentFetcher::class)->fetch($offer->fresh());
        $this->assertCount(1, $application->fresh()->attachments);
    }
}
