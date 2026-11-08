<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $owner;
    }

    public function test_login_page_shows_the_logo_and_loads_the_local_theme(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('class="jobs-brand"', false)
            ->assertSee('pierre-ollivier', false)
            ->assertSee('/theme/jobs.css?v=', false)
            ->assertSee('/theme/jobs.js?v=', false)
            ->assertSee('/theme/favicon.svg', false)
            ->assertDontSee('fonts.googleapis.com', false)
            ->assertDontSee('fonts.bunny.net', false)
            // La barre d'onglets mobile n'apparaît qu'une fois connecté.
            ->assertDontSee('jobs-tabbar', false);
    }

    public function test_dashboard_has_readability_panel_grouped_navigation_and_mobile_tabs(): void
    {
        $this->actingAs($this->owner());

        $this->get('/')
            ->assertOk()
            ->assertSee('x-data="jobsLisibilite"', false)
            ->assertSee('Réglages de lisibilité')
            ->assertSee('class="jobs-tabbar"', false)
            ->assertSeeInOrder(['Tableau de bord', 'Candidatures', 'Opportunités', 'Analyse', 'Statistiques', 'Compte', 'Réglages']);
    }

    public function test_theme_files_exist(): void
    {
        foreach (['jobs.css', 'jobs.js', 'favicon.svg', 'apple-touch-icon.png', 'fonts/inter-variable.woff2', 'fonts/atkinson-hyperlegible-latin-400-normal.woff2'] as $file) {
            $this->assertFileExists(public_path('theme/'.$file));
        }
    }
}
