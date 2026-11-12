<?php

namespace App\Filament\Pages;

use App\Models\AutomationRun;
use App\Models\GoogleConnection;
use App\Services\Automation\AutoPreparer;
use App\Services\Automation\FollowUps;
use App\Services\Automation\HealthCheck;
use App\Services\Automation\NotificationCenter;
use App\Services\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Réglages de la plateforme. Toutes les valeurs sont chiffrées en base ; le code secret
 * Google n'est jamais renvoyé vers le navigateur.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Réglages';

    protected static string|UnitEnum|null $navigationGroup = 'Compte';

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
            // Infomaniak par défaut, tant que rien n'est enregistré ici.
            'mail_host' => SettingsStore::isStored('mail_host') ? SettingsStore::get('mail_host') : 'mail.infomaniak.com',
            'mail_port' => SettingsStore::isStored('mail_port') ? SettingsStore::get('mail_port') : 465,
            'mail_scheme' => SettingsStore::isStored('mail_scheme') ? SettingsStore::get('mail_scheme') : 'smtps',
            'mail_username' => SettingsStore::get('mail_username'),
            'mail_password' => null,
            'mail_from_address' => SettingsStore::get('mail_from_address'),
            'mail_from_name' => SettingsStore::isStored('mail_from_name') ? SettingsStore::get('mail_from_name') : 'Tableau de bord jobs',
            'notification_email' => SettingsStore::get('notification_email') ?: config('dashboard.owner_email'),
            'reminder_days' => SettingsStore::get('reminder_days'),
            'follow_up_drafts' => (bool) SettingsStore::get('follow_up_drafts'),
            'follow_up_template' => SettingsStore::get('follow_up_template') ?: FollowUps::DEFAULT_TEMPLATE,
            'auto_prepare' => (bool) SettingsStore::get('auto_prepare'),
            'auto_prepare_min_score' => SettingsStore::get('auto_prepare_min_score'),
            'auto_prepare_top_rated' => (bool) SettingsStore::get('auto_prepare_top_rated'),
            'response_auto_status' => (bool) SettingsStore::get('response_auto_status'),
            'gmail_label' => SettingsStore::get('gmail_label'),
            'proof_bank' => SettingsStore::get('proof_bank'),
            'notifications' => collect(NotificationCenter::types())->mapWithKeys(fn ($d, $type) => [
                $type => Arr::only(NotificationCenter::preferences($type), ['mail', 'app', 'ios', 'every_days', 'time', 'weekday']),
            ])->all(),
        ]);
    }

    /**
     * Une section par type de notification : canaux activables et, selon le type, heure,
     * intervalle en jours et jour de la semaine.
     *
     * @return list<Section>
     */
    private function notificationSections(): array
    {
        $sections = [];

        foreach (NotificationCenter::types() as $type => $definition) {
            $path = 'notifications.'.$type.'.';
            $fields = [
                Toggle::make($path.'mail')->label('Par e-mail')->inline(false),
                Toggle::make($path.'app')->label('Dans la web app')->inline(false)->helperText('Cloche en haut à droite.'),
                Toggle::make($path.'ios')->label('App iOS')->inline(false)->disabled()->dehydrated()
                    ->helperText('Bientôt : l\'app iPhone reprendra ces réglages.'),
            ];

            if (isset($definition['every_days'])) {
                $fields[] = TextInput::make($path.'every_days')
                    ->label('Tous les … jours')
                    ->integer()->minValue(1)->maxValue(60)->required()
                    ->suffix('jour(s)');
            }
            if (isset($definition['time'])) {
                $fields[] = TimePicker::make($path.'time')
                    ->label($type === 'relance' ? 'Heure de préparation des relances' : 'Heure d\'envoi')
                    ->seconds(false)->format('H:i')->required();
            }
            if (isset($definition['weekday'])) {
                $fields[] = Select::make($path.'weekday')
                    ->label('Jour')
                    ->options(NotificationCenter::WEEKDAYS)
                    ->required()
                    ->helperText('Utilisé quand l\'intervalle est un multiple de 7 jours.');
            }
            if ($type === 'relance') {
                $fields[] = TextInput::make('follow_up_days')
                    ->label('Première relance après')
                    ->integer()->minValue(1)->maxValue(60)->required()->suffix('jours');
                $fields[] = TextInput::make('reminder_days')
                    ->label('Rappel « toujours sans réponse » après')
                    ->integer()->minValue(1)->maxValue(90)->required()->suffix('jours');
            }

            $sections[] = Section::make($definition['label'])
                ->description($definition['description'])
                ->icon($definition['icon'])
                ->columns(['default' => 1, 'sm' => 3])
                ->compact()
                ->schema($fields);
        }

        return $sections;
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
                                    ->description('Créés dans Google Cloud. Le guide pas à pas explique où les trouver.')
                                    ->headerActions([$this->guideAction()])
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
                                            ->placeholder('Offres!A1:Z1000')
                                            ->maxLength(100),
                                        TextInput::make('google_drive_folder')
                                            ->label('Dossier Drive des candidatures envoyées')
                                            ->placeholder('Candidatures')
                                            ->maxLength(100),
                                    ])
                                    ->columns(1),
                            ]),
                        Tab::make('Notifications')
                            ->icon('heroicon-o-bell')
                            ->schema([
                                Text::make('Choisis pour chaque type comment être prévenu, à quelle heure et tous les combien de jours. Rien n\'est jamais envoyé à un recruteur sans ta validation.'),
                                ...$this->notificationSections(),
                            ]),
                        Tab::make('Automatisations')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Section::make('Candidature préparée d\'office')
                                    ->description('Quand une offre très pertinente a ses documents (lettre, message, PDF), la candidature est remplie et attend ta validation.')
                                    ->columns(['default' => 1, 'sm' => 3])
                                    ->schema([
                                        Toggle::make('auto_prepare')->label('Activer')->inline(false),
                                        TextInput::make('auto_prepare_min_score')
                                            ->label('À partir du score')
                                            ->integer()->minValue(0)->maxValue(100)->required()->suffix('/ 100'),
                                        Toggle::make('auto_prepare_top_rated')->label('Aussi les offres notées 3/3')->inline(false),
                                    ]),
                                Section::make('Relances')
                                    ->description('Le brouillon est créé dans Gmail, dans le fil de la candidature. Tu le relis et l\'envoies toi-même.')
                                    ->schema([
                                        Toggle::make('follow_up_drafts')->label('Créer le brouillon de relance dans Gmail'),
                                        Textarea::make('follow_up_template')
                                            ->label('Modèle de relance')
                                            ->helperText('Repères remplacés automatiquement : {poste}, {entreprise}, {date_envoi}, {signature}.')
                                            ->rows(9)
                                            ->maxLength(4000),
                                    ]),
                                Section::make('Suivi des réponses et veille')
                                    ->description('Seul le libellé Gmail indiqué est lu, plus les fils des candidatures envoyées depuis le tableau de bord.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('gmail_label')->label('Libellé Gmail lu')->required()->maxLength(100),
                                        Toggle::make('response_auto_status')
                                            ->label('Mettre à jour le statut tout seul')
                                            ->helperText('Refus ou invitation à un entretien détectés : le statut change, et l\'historique le note.')
                                            ->inline(false),
                                    ]),
                                Section::make('Banque de preuves')
                                    ->description('Utilisée par les fiches de préparation d\'entretien. Colle le tableau de ta banque de preuves (| # | Preuve FR | Preuve EN | Mots-clés | Force |) ou une liste « - preuve (mots-clés : …) ».')
                                    ->collapsible()
                                    ->schema([
                                        Textarea::make('proof_bank')->hiddenLabel()->rows(12)->maxLength(30000),
                                    ]),
                                Section::make('Dernières exécutions')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([Text::make(fn () => new HtmlString(self::runsHtml()))]),
                            ]),
                        Tab::make('Candidatures')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                Textarea::make('signature')
                                    ->label('Signature')
                                    ->helperText('Ajoutée au message de chaque nouvelle candidature.')
                                    ->rows(5)
                                    ->maxLength(2000),
                            ]),
                        Tab::make('E-mails')
                            ->icon('heroicon-o-at-symbol')
                            ->schema([
                                Text::make('Les résumés, alertes et relances te sont envoyés depuis une boîte e-mail Infomaniak. Indique ici son adresse et son mot de passe, clique sur « Tester la configuration e-mail » (plus bas), puis sur « Enregistrer ».'),
                                Section::make('Boîte d\'envoi')
                                    ->description('Une adresse existante chez Infomaniak, par exemple une adresse de ton domaine. Le mot de passe est celui de cette boîte e-mail.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('mail_username')
                                            ->label('Adresse de la boîte d\'envoi')
                                            ->email()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, $set, $get) => blank($get('mail_from_address')) ? $set('mail_from_address', $state) : null),
                                        TextInput::make('mail_password')
                                            ->label('Mot de passe de cette boîte')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->maxLength(255)
                                            ->placeholder(fn () => filled(SettingsStore::get('mail_password')) ? '•••••••• (enregistré : laisser vide pour le conserver)' : 'Coller le mot de passe ici')
                                            ->helperText('Chiffré dans la base de données et jamais réaffiché.'),
                                        TextInput::make('mail_from_address')
                                            ->label('Adresse d\'expédition')
                                            ->email()
                                            ->maxLength(255)
                                            ->helperText('En général la même que la boîte d\'envoi.'),
                                        TextInput::make('mail_from_name')->label('Nom de l\'expéditeur')->maxLength(100),
                                    ]),
                                Section::make('Destinataire')
                                    ->schema([
                                        TextInput::make('notification_email')
                                            ->label('Recevoir les e-mails du tableau de bord à')
                                            ->email()
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText('Ton adresse personnelle, par exemple ton Gmail. Ne change pas l\'adresse de connexion.'),
                                    ]),
                                Section::make('Serveur (réglages avancés)')
                                    ->description('Déjà remplis pour Infomaniak : n\'y touche que si ta boîte est ailleurs.')
                                    ->collapsible()
                                    ->collapsed()
                                    ->columns(['default' => 1, 'sm' => 3])
                                    ->schema([
                                        TextInput::make('mail_host')->label('Serveur SMTP')->required()->maxLength(255),
                                        TextInput::make('mail_port')->label('Port')->integer()->minValue(1)->maxValue(65535)->required(),
                                        Select::make('mail_scheme')->label('Sécurité')->options(['smtps' => 'SSL (port 465)', 'smtp' => 'STARTTLS (port 587)'])->required(),
                                    ]),
                                Section::make('Tester')
                                    ->description('Envoie un e-mail de test avec les réglages ci-dessus, même avant de les enregistrer. En cas d\'échec, l\'erreur exacte du serveur s\'affiche.')
                                    ->schema([
                                        Actions::make([
                                            Action::make('test_mail_settings')
                                                ->label('Tester la configuration e-mail')
                                                ->icon('heroicon-o-paper-airplane')
                                                ->action(function () {
                                                    $result = self::testMailSettings($this->data ?? []);
                                                    Notification::make()
                                                        ->title($result['ok'] ? 'E-mail de test envoyé' : 'Échec de l\'envoi')
                                                        ->body($result['message'])
                                                        ->{$result['ok'] ? 'success' : 'danger'}()
                                                        ->persistent()
                                                        ->send();
                                                }),
                                        ])->key('mail_test_actions'),
                                        Text::make(fn () => new HtmlString(self::mailStatusHtml())),
                                    ]),
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_notification')
                ->label('Tester les notifications')
                ->icon('heroicon-o-bell-alert')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Un message de test part vers ton adresse e-mail et dans la web app, pour vérifier que tout arrive bien.')
                ->action(function () {
                    $channels = NotificationCenter::test();
                    Notification::make()
                        ->title(in_array('e-mail', $channels, true) ? 'Test envoyé' : 'E-mail non envoyé')
                        ->body((in_array('e-mail', $channels, true) ? 'Envoyé : '.implode(' et ', $channels).'. Vérifie ta boîte mail (et le dossier Spam) et la cloche.' : 'L\'e-mail n\'est pas parti : '.(AutomationRun::query()->where('task', 'mail_erreur')->value('summary') ?? 'erreur inconnue').'. Vérifie l\'adresse et le mot de passe dans Réglages > E-mails.'))
                        ->{in_array('e-mail', $channels, true) ? 'success' : 'danger'}()
                        ->persistent()
                        ->send();
                }),
            $this->guideAction()->name('guide_header'),
        ];
    }

    /**
     * Envoie un e-mail de test avec les réglages du formulaire (enregistrés ou non).
     * Mot de passe laissé vide : celui déjà enregistré est utilisé.
     *
     * @param  array<string, mixed>  $state
     * @return array{ok: bool, message: string}
     */
    public static function testMailSettings(array $state, ?string $transport = null): array
    {
        $username = trim((string) ($state['mail_username'] ?? ''));
        $password = filled($state['mail_password'] ?? null) ? (string) $state['mail_password'] : (string) SettingsStore::get('mail_password');
        $from = trim((string) ($state['mail_from_address'] ?? '')) ?: $username;
        $to = trim((string) ($state['notification_email'] ?? ''));
        $host = trim((string) ($state['mail_host'] ?? '')) ?: 'mail.infomaniak.com';

        $missing = array_keys(array_filter([
            'l\'adresse de la boîte d\'envoi' => $username === '',
            'son mot de passe' => $password === '',
            'l\'adresse du destinataire' => $to === '',
        ]));
        if ($missing) {
            return ['ok' => false, 'message' => 'Il manque '.implode(', ', $missing).'.'];
        }
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Une des adresses e-mail n\'est pas valide.'];
        }

        try {
            Mail::build([
                'transport' => $transport ?? 'smtp',
                'scheme' => ($state['mail_scheme'] ?? 'smtps') === 'smtp' ? 'smtp' : 'smtps',
                'host' => $host,
                'port' => (int) ($state['mail_port'] ?? 465) ?: 465,
                'username' => $username,
                'password' => $password,
                'timeout' => 20,
            ])->raw(
                "Bonjour,\n\nCet e-mail confirme que la configuration e-mail du tableau de bord fonctionne.\n\nServeur : {$host}\nBoîte d'envoi : {$username}\n\nTon tableau de bord jobs",
                fn ($message) => $message->from($from, trim((string) ($state['mail_from_name'] ?? '')) ?: null)->to($to)->subject('Test de la configuration e-mail'),
            );
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 400);
            $hint = match (true) {
                (bool) preg_match('/\b535\b|authenticat|credentials/i', $error) => 'L\'adresse ou le mot de passe de la boîte d\'envoi est refusé par le serveur : vérifie-les (le mot de passe est celui de la boîte e-mail, pas celui du Manager Infomaniak).',
                (bool) preg_match('/timed out|refused|resolve|getaddrinfo|unreachable/i', $error) => 'Le serveur d\'e-mail ne répond pas : vérifie le serveur, le port et la sécurité dans « Serveur (réglages avancés) ».',
                (bool) preg_match('/sender|from address|\b553\b|\b550\b/i', $error) => 'L\'adresse d\'expédition est refusée : utilise la même que la boîte d\'envoi.',
                default => 'Vérifie les réglages ci-dessus.',
            };

            return ['ok' => false, 'message' => $hint.' Erreur du serveur : '.$error];
        }

        $saved = SettingsStore::get('mail_username') === $username
            && SettingsStore::get('mail_password') === $password
            && SettingsStore::get('mail_host') === $host;
        if ($saved) {
            AutomationRun::mark('mail_ok', 'Test de la configuration e-mail');
        }

        return ['ok' => true, 'message' => "Un e-mail de test est parti vers {$to}. Vérifie ta boîte (et le dossier Spam)."
            .($saved ? '' : ' Clique maintenant sur « Enregistrer » pour garder ces réglages.')];
    }

    /**
     * Dernier envoi réussi ou dernière erreur du serveur d'e-mail.
     */
    public static function mailStatusHtml(): string
    {
        $check = HealthCheck::checks()['mail'];
        $color = $check['ok'] ? '#0d9488' : '#e11d48';

        return '<span style="color:'.$color.'"><strong>'.($check['ok'] ? 'OK' : 'Problème').'</strong></span> – '.e($check['detail']);
    }

    /**
     * Tableau des dernières exécutions des automatisations.
     */
    public static function runsHtml(): string
    {
        $labels = [
            'scores' => 'Scores de pertinence', 'preparation' => 'Préparation d\'office', 'relances' => 'Relances',
            'reponses' => 'Suivi des réponses', 'veille' => 'Veille', 'resume_matin' => 'Résumé du matin', 'bilan' => 'Bilan hebdomadaire', 'sante' => 'Contrôle de l\'état du système',
        ];
        $runs = AutomationRun::query()->get()->keyBy('task');
        $rows = '';

        foreach ($labels as $task => $label) {
            $run = $runs[$task] ?? null;
            $error = $runs[$task.'_erreur'] ?? null;
            $rows .= '<tr><td style="padding:.25rem .75rem .25rem 0"><strong>'.e($label).'</strong></td>'
                .'<td style="padding:.25rem .75rem">'.e($run?->ran_at?->format('d.m.Y H:i') ?? 'jamais').'</td>'
                .'<td style="padding:.25rem 0">'.e($run?->summary ?? '').(
                    $error && (! $run || $error->ran_at->gt($run->ran_at)) ? ' <span style="color:#e11d48">Erreur : '.e($error->summary).'</span>' : ''
                ).'</td></tr>';
        }

        return '<table style="font-size:.875rem">'.$rows.'</table>'
            .'<p style="font-size:.8rem;opacity:.7;margin-top:.5rem">Les automatisations tournent toutes les 5 minutes grâce à la tâche planifiée (cron) du serveur.</p>';
    }

    /**
     * Guide de connexion aux services Google, affiché dans une fenêtre latérale.
     */
    public function guideAction(): Action
    {
        return Action::make('guide')
            ->label('Guide de connexion Google')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->modalHeading('Connecter Gmail, Drive et Sheets')
            ->modalDescription('À faire une seule fois, connecté à ton compte Google. Compte environ 15 minutes.')
            ->modalContent(fn () => new HtmlString('<div class="fi-prose">'.self::guideHtml().'</div>'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->slideOver()
            ->modalWidth('2xl');
    }

    /**
     * Contenu de docs/google-cloud.md, avec l'URI de redirection réelle de ce site.
     */
    public static function guideHtml(): string
    {
        $path = base_path('docs/google-cloud.md');
        $markdown = is_file($path) ? (string) file_get_contents($path) : 'Guide introuvable : docs/google-cloud.md.';

        $markdown = preg_replace('/^# .*\R+/u', '', $markdown, 1); // le titre est déjà dans la fenêtre
        $markdown = str_replace('https://jobs.pierreolliviertissier.ch/google/callback', (string) config('services.google.redirect'), $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // Liens externes dans un nouvel onglet, sans transmettre l'adresse du tableau de bord.
        return preg_replace('/<a href="(https?:[^"]+)">/', '<a href="$1" target="_blank" rel="noopener noreferrer">', $html);
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

        // Préférences de notification : seulement les clés connues, heures au format HH:MM.
        $data['notifications'] = collect($data['notifications'] ?? [])
            ->only(array_keys(NotificationCenter::types()))
            ->map(fn ($prefs) => array_filter([
                'mail' => (bool) ($prefs['mail'] ?? false),
                'app' => (bool) ($prefs['app'] ?? false),
                'ios' => (bool) ($prefs['ios'] ?? false),
                'every_days' => isset($prefs['every_days']) ? (int) $prefs['every_days'] : null,
                'time' => isset($prefs['time']) ? substr((string) $prefs['time'], 0, 5) : null,
                'weekday' => isset($prefs['weekday']) ? (int) $prefs['weekday'] : null,
            ], fn ($v) => $v !== null))
            ->all();

        if (trim((string) ($data['follow_up_template'] ?? '')) === trim(FollowUps::DEFAULT_TEMPLATE)) {
            $data['follow_up_template'] = null; // modèle par défaut : suit les améliorations futures
        }

        $previousScore = SettingsStore::get('auto_prepare_min_score');

        if (blank($data['mail_password'] ?? null)) {
            unset($data['mail_password']); // conserver le mot de passe déjà enregistré
        }

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
        $this->data['mail_password'] = null;

        if ($previousScore !== SettingsStore::get('auto_prepare_min_score')) {
            app(AutoPreparer::class)->run(); // nouveau seuil : préparer tout de suite les offres concernées
        }
    }
}
