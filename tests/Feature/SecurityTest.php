<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NewLoginAlert;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_txt_disallows_everything(): void
    {
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_login_page_sends_security_and_noindex_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("form-action 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_there_is_no_registration_or_password_reset_page(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/password-reset/request')->assertNotFound();
    }

    public function test_health_route_is_not_exposed(): void
    {
        $this->get('/up')->assertNotFound();
    }

    public function test_only_the_owner_can_access_the_panel(): void
    {
        $intruder = User::factory()->create(['email' => 'intrus@example.test']);

        $this->actingAs($intruder)->get('/')->assertForbidden();
    }

    public function test_owner_without_two_factor_is_sent_to_two_factor_setup(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);

        $response = $this->actingAs($owner)->get('/');

        $response->assertRedirect();
        $this->assertStringContainsString('multi-factor-authentication', $response->headers->get('Location'));
    }

    public function test_owner_email_is_matched_case_insensitively(): void
    {
        $owner = User::factory()->create(['email' => 'Owner@Example.test']);

        $this->assertTrue($owner->canAccessPanel(filament()->getDefaultPanel()));
    }

    public function test_two_factor_secret_is_encrypted_at_rest(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $raw = \DB::table('users')->where('id', $owner->id)->value('app_authentication_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $owner->fresh()->getAppAuthenticationSecret());
    }

    public function test_successful_login_sends_an_alert_to_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@example.test']);

        event(new Login('web', $owner, false));

        Notification::assertSentToTimes($owner, NewLoginAlert::class, 1);
    }
}
