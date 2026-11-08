<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crée le compte propriétaire, ou réinitialise son mot de passe et sa double
 * authentification. Ne s'exécute qu'en ligne de commande (SSH) : il n'existe
 * aucune page publique d'inscription ou de mot de passe oublié.
 */
class ManageOwner extends Command
{
    protected $signature = 'dashboard:owner
        {--reset-2fa : Supprime la double authentification, à reconfigurer à la prochaine connexion}';

    protected $description = 'Crée le compte propriétaire du tableau de bord ou réinitialise son mot de passe';

    public function handle(): int
    {
        $email = config('dashboard.owner_email');

        if (blank($email)) {
            $this->error('DASHBOARD_OWNER_EMAIL n\'est pas défini dans le fichier .env.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($user && $this->option('reset-2fa')) {
            $user->saveAppAuthenticationSecret(null);
            $user->saveAppAuthenticationRecoveryCodes(null);
            $this->purgeSessions($user);
            $this->info('Double authentification supprimée. Elle sera demandée à la prochaine connexion.');

            return self::SUCCESS;
        }

        if ($user && ! confirm("Le compte {$email} existe. Changer son mot de passe ?", default: false)) {
            return self::SUCCESS;
        }

        $plain = password(
            label: 'Mot de passe (14 caractères minimum, majuscules, minuscules, chiffres et symboles)',
            required: true,
            validate: fn (string $value) => Validator::make(
                ['password' => $value],
                ['password' => [Password::defaults()]],
            )->errors()->first('password') ?: null,
        );

        $confirmation = password(label: 'Confirmer le mot de passe', required: true);

        if (! hash_equals($plain, $confirmation)) {
            $this->error('Les deux mots de passe ne correspondent pas.');

            return self::FAILURE;
        }

        if ($user) {
            $user->forceFill(['password' => $plain])->save();
            $this->purgeSessions($user);
            $this->info('Mot de passe changé. Toutes les sessions ouvertes ont été fermées.');

            return self::SUCCESS;
        }

        $name = text(label: 'Nom affiché', default: 'Pierre', required: true);

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plain,
        ]);

        $this->info("Compte {$email} créé. La double authentification sera configurée à la première connexion.");

        return self::SUCCESS;
    }

    protected function purgeSessions(User $user): void
    {
        $user->forceFill(['remember_token' => null])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
