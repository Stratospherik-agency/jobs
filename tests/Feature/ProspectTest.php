<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Enums\ProspectStatus;
use App\Filament\Resources\Prospects\Pages\CreateProspect;
use App\Filament\Resources\Prospects\Pages\ViewProspect;
use App\Filament\Resources\Prospects\ProspectActions;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Statistics\OrpReport;
use App\Services\Statistics\ProspectStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProspectTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    public function test_pages_require_login(): void
    {
        $this->get('/candidatures-spontanees')->assertRedirect('/login');
        $this->get('/statistiques/spontanees')->assertRedirect('/login');
    }

    public function test_pages_render_for_the_owner(): void
    {
        $prospect = Prospect::factory()->create(['company' => 'Bobst', 'reasons' => 'Nouvelle usine à Mex']);
        Prospect::factory()->create(['status' => ProspectStatus::Sent, 'sent_at' => today()->subDays(3)]);
        $this->actingAs($this->owner());

        $this->get('/candidatures-spontanees')->assertOk()->assertSee('Bobst')->assertSee('Nouvelle usine à Mex');
        $this->get('/candidatures-spontanees/'.$prospect->id)->assertOk();
        $this->get('/statistiques/spontanees')->assertOk();
    }

    public function test_follow_up_and_recontact_dates_are_computed(): void
    {
        $prospect = Prospect::factory()->create();
        $prospect->update(['status' => ProspectStatus::Sent, 'sent_at' => '2026-11-02']); // lundi

        $this->assertSame('2026-11-16', $prospect->follow_up_at->toDateString()); // + 10 jours ouvrables

        $prospect->update(['status' => ProspectStatus::Rejected, 'response_at' => '2026-11-10']);
        $this->assertSame('refus', $prospect->response_type);
        $this->assertSame('2027-05-10', $prospect->recontact_at->toDateString());
        $this->assertNull($prospect->follow_up_at);
    }

    public function test_housekeeping_closes_silent_follow_ups_and_reopens_refusals(): void
    {
        $silent = Prospect::factory()->create(['status' => ProspectStatus::FollowedUp, 'sent_at' => today()->subDays(40), 'followed_up_at' => today()->subDays(22)]);
        $recent = Prospect::factory()->create(['status' => ProspectStatus::FollowedUp, 'sent_at' => today()->subDays(20), 'followed_up_at' => today()->subDays(5)]);
        $refused = Prospect::factory()->create(['status' => ProspectStatus::Rejected, 'response_at' => today()->subMonths(7), 'recontact_at' => today()->subMonth()]);

        Prospect::housekeeping();

        $this->assertSame(ProspectStatus::NoAnswer, $silent->fresh()->status);
        $this->assertSame('sans_reponse', $silent->fresh()->response_type);
        $this->assertSame(ProspectStatus::FollowedUp, $recent->fresh()->status);
        $this->assertSame(ProspectStatus::Recontact, $refused->fresh()->status);
    }

    public function test_one_click_select_then_prepare_creates_a_linked_opportunity(): void
    {
        $prospect = Prospect::factory()->create(['company' => 'Bobst', 'contact_email' => 'rh@bobst.com', 'family' => 'grande']);
        $this->actingAs($this->owner());

        Livewire::test(ViewProspect::class, ['record' => $prospect->id])->callAction('select');
        $this->assertSame(ProspectStatus::Selected, $prospect->fresh()->status);

        Livewire::test(ViewProspect::class, ['record' => $prospect->id])->callAction('prepare')->assertRedirect();

        $prospect->refresh();
        $opportunity = $prospect->opportunity;
        $this->assertSame(ProspectStatus::InPreparation, $prospect->status);
        $this->assertSame('Candidature spontanée', $opportunity->source);
        $this->assertSame('grande', $opportunity->company_size);
        $this->assertSame(OpportunityStatus::InPreparation, $opportunity->status);

        // Rien ne part : la candidature attend la validation sur la fiche de l'opportunité.
        $opportunity->application()->create(['to_email' => 'rh@bobst.com', 'subject' => 'Candidature spontanée', 'body' => 'Bonjour', 'status' => ApplicationStatus::ToValidate]);
        $this->assertSame(ProspectStatus::ToValidate, $prospect->fresh()->status);

        // L'envoi validé fait avancer la cible et compte dans le justificatif ORP.
        $opportunity->update(['status' => OpportunityStatus::Applied, 'applied_at' => today()]);
        $this->assertSame(ProspectStatus::Sent, $prospect->fresh()->status);
        $this->assertNotNull($prospect->fresh()->follow_up_at);
        $this->assertSame('Candidature spontanée', OrpReport::rows(today()->startOfMonth())->first()['kind']);

        $opportunity->update(['status' => OpportunityStatus::Interview]);
        $this->assertSame(ProspectStatus::Interview, $prospect->fresh()->status);
    }

    public function test_web_agencies_are_refused(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(CreateProspect::class)
            ->fillForm(['company' => 'Pixel, agence web', 'status' => ProspectStatus::ToQualify->value])
            ->call('create')
            ->assertHasFormErrors(['company']);

        $agency = Prospect::factory()->create(['company' => 'Studio X', 'sector' => 'Agence digitale']);
        $this->assertFalse(ProspectActions::select()->record($agency)->isVisible());
    }

    public function test_statistics_against_targets(): void
    {
        Prospect::factory()->create(['status' => ProspectStatus::ToQualify]);
        Prospect::factory()->create(['status' => ProspectStatus::Sent, 'sent_at' => today()->subDays(2), 'channel' => 'email']);
        Prospect::factory()->create(['status' => ProspectStatus::Interview, 'sent_at' => today()->subDays(12), 'response_at' => today()->subDays(2), 'channel' => 'portail']);

        $data = ProspectStatistics::dataset(['period' => '30']);

        $this->assertSame(50.0, ProspectStatistics::rate($data, 'response')['value']);
        $this->assertSame(10.0, ProspectStatistics::averageDelay($data));
        $this->assertSame(['Cibles repérées' => 3, 'Retenues' => 2, 'Envoyées' => 2, 'Réponses' => 1, 'Entretiens' => 1], ProspectStatistics::funnel($data));
        $this->assertSame(['Portail carrière', 'E-mail direct'], ProspectStatistics::byAttribute($data, 'channel')['labels']);
    }
}
