<?php

namespace App\Filament\Pages;

use App\Models\GoogleConnection;
use App\Services\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Réglages de la plateforme. Toutes les valeurs sont chiffrées en base ; le code secret
 * Google n'est jamais renvoyé vers le navigateur.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Réglages';

    protected static ?string $title = 'Réglages';

    protected static ?string $slug = 'reglages';

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'google_client_id' => SettingsStore::get('google_client_id'),
            'google_client_secret' => null,
            'google_registry_sheet_id' => SettingsStore::get('google_registry_sheet_id'),
            'google_registry_range' => SettingsStore::get('google_registry_range'),
            'google_drive_folder' => SettingsStore::get('google_drive_folder'),
            'follow_up_days' => SettingsStore::get('follow_up_days'),
            'signature' => SettingsStore::get('signature'),
            'login_alerts' => (bool) SettingsStore::get('login_alerts'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $secretStored = filled(SettingsStore::get('google_client_secret'));

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('reglages')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Google')
                            ->icon('heroicon-o-link')
                            ->schema([
                                Section::make('Identifiants du client OAuth')
                                    ->description('Créés dans Google Cloud (guide pas à pas : docs/google-cloud.md, étapes 1 à 4).')
                                    ->schema([
                                        TextInput::make('redirect_uri')
                                            ->label('URI de redirection à copier dans Google Cloud')
                                            ->default(config('services.google.redirect'))
                                            ->formatStateUsing(fn () => config('services.google.redirect'))
                                            ->readOnly()
                                            ->dehydrated(false)
                                            ->copyable(copyMessage: 'Copié'),
                                        TextInput::make('google_client_id')
                                            ->label('ID client')
                                            ->placeholder('123456789-abc….apps.googleusercontent.com')
                                            ->regex('/^[0-9A-Za-z_-]+\.apps\.googleusercontent\.com$/')
                                            ->validationMessages(['regex' => 'L\'ID client se termine par .apps.googleusercontent.com.'])
                                            ->maxLength(255),
                                        TextInput::make('google_client_secret')
                                            ->label('Code secret du client')
                                            ->password()
                                            ->autocomplete('new-password')
                                            ->maxLength(255)
                                            ->placeholder($secretStored ? '•••••••• (enregistré : laisser vide pour le conserver)' : 'Coller le code secret ici')
                                            ->helperText('Chiffré dans la base de données et jamais réaffiché. Ne le communique à personne.'),
                                    ]),
                                Section::make('Registre et archives')
                                    ->schema([
                                        TextInput::make('google_registry_sheet_id')
                                            ->label('Registre Google Sheets')
                                            ->helperText('Colle l\'adresse complète de la feuille ou son identifiant.')
                                            ->maxLength(500),
                                        TextInput::make('google_registry_range')
                                            ->label('Onglet et colonnes lus')
                                            ->placeholder('Offres!A1:N1000')
                                            ->maxLength(100),
                                        TextInput::make('google_drive_folder')
                                            ->label('Dossier Drive des candidatures envoyées')
                                            ->placeholder('Candidatures')
                                            ->maxLength(100),
                                    ])
                                    ->columns(1),
                            ]),
                        Tab::make('Candidatures')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                TextInput::make('follow_up_days')
                                    ->label('Relance après (jours)')
                                    ->helperText('Délai entre l\'envoi d\'une candidature et la relance proposée.')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(60)
                                    ->required(),
                                Textarea::make('signature')
                                    ->label('Signature')
                                    ->helperText('Ajoutée au message de chaque nouvelle candidature.')
                                    ->rows(5)
                                    ->maxLength(2000),
                            ]),
                        Tab::make('Sécurité')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Toggle::make('login_alerts')
                                    ->label('M\'envoyer un e-mail à chaque connexion')
                                    ->helperText('Recommandé : tu es prévenu si quelqu\'un d\'autre se connecte.'),
                                Text::make(new HtmlString('Compte autorisé : <strong>'.e(config('dashboard.owner_email')).'</strong>. Mot de passe et double authentification se gèrent depuis <a class="underline" href="'.e(EditProfile::getUrl()).'">ton profil</a>.')),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Enregistrer')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $previousClientId = SettingsStore::get('google_client_id');

        $data['google_registry_sheet_id'] = SettingsStore::spreadsheetId($data['google_registry_sheet_id'] ?? null);

        if (blank($data['google_client_secret'] ?? null)) {
            unset($data['google_client_secret']); // conserver le secret déjà enregistré
        }

        SettingsStore::save($data);

        $notification = Notification::make()->title('Réglages enregistrés')->success();

        if (GoogleConnection::current() && $previousClientId !== SettingsStore::get('google_client_id')) {
            $notification->body('L\'ID client a changé : reconnecte ton compte Google depuis la page Compte Google.')->warning()->persistent();
        }

        $notification->send();

        $this->data['google_client_secret'] = null;
    }
}
