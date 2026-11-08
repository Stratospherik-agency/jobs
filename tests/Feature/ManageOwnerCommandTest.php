<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManageOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $strong = 'Tr3s-Long-Mot-de-Passe!';

    public function test_it_creates_the_owner_account(): void
    {
        $this->artisan('dashboard:owner')
            ->expectsQuestion('Mot de passe (14 caractères minimum, majuscules, minuscules, chiffres et symboles)', $this->strong)
            ->expectsQuestion('Confirmer le mot de passe', $this->strong)
            ->expectsQuestion('Nom affiché', 'Pierre')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($this->strong, $user->password));
    }

    public function test_it_refuses_mismatched_passwords(): void
    {
        $this->artisan('dashboard:owner')
            ->expectsQuestion('Mot de passe (14 caractères minimum, majuscules, minuscules, chiffres et symboles)', $this->strong)
            ->expectsQuestion('Confirmer le mot de passe', 'autre-chose')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_resets_two_factor(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->artisan('dashboard:owner', ['--reset-2fa' => true])->assertSuccessful();

        $this->assertNull($owner->fresh()->getAppAuthenticationSecret());
    }

    public function test_it_fails_without_owner_email(): void
    {
        config(['dashboard.owner_email' => null]);

        $this->artisan('dashboard:owner')->assertFailed();
    }
}
