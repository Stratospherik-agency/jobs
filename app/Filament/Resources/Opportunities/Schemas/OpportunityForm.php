<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Support\Catalog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Offre')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('company')->label('Entreprise')->required()->maxLength(255),
                        TextInput::make('title')->label('Poste')->required()->maxLength(255),
                        TextInput::make('location')->label('Lieu')->maxLength(255),
                        TextInput::make('rate')->label('Taux / contrat')->placeholder('100%, CDI, 50-70%…')->maxLength(255),
                        Select::make('source')
                            ->label('Source')
                            ->options(fn () => self::sources())
                            ->searchable()
                            ->createOptionForm([TextInput::make('source')->label('Nouvelle source')->required()])
                            ->createOptionUsing(fn (array $data) => $data['source']),
                        DatePicker::make('published_at')->label('Publiée le')->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('url')
                            ->label('Lien de l\'offre')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        TextInput::make('contact_email')
                            ->label('Adresse de candidature')
                            ->email()
                            ->helperText('Si l\'annonce demande un envoi par e-mail : la candidature préparée d\'office l\'utilisera.')
                            ->columnSpanFull(),
                        Textarea::make('notes')->label('Notes')->rows(4)->columnSpanFull(),
                    ]),
                Section::make('Caractéristiques')
                    ->description('Servent aux statistiques. Remplies à l\'ajout de l\'offre, modifiables ici.')
                    ->columnSpan(2)
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        TextInput::make('sector')->label('Secteur')->datalist(Catalog::SECTORS)->maxLength(100),
                        Select::make('company_size')->label('Taille d\'entreprise')->options(Catalog::COMPANY_SIZES)->native(false),
                        TextInput::make('job_family')->label('Métier')->datalist(Catalog::JOB_FAMILIES)->maxLength(100),
                        Select::make('seniority')->label('Niveau de poste')->options(Catalog::SENIORITY)->native(false),
                        Select::make('contract_type')->label('Contrat')->options(Catalog::CONTRACTS)->native(false),
                        Select::make('remote')->label('Télétravail')->options(Catalog::REMOTE)->native(false),
                        TextInput::make('rate_min')->label('Taux min. (%)')->integer()->minValue(10)->maxValue(100)
                            ->helperText('Déduit du champ « Taux / contrat ».'),
                        TextInput::make('rate_max')->label('Taux max. (%)')->integer()->minValue(10)->maxValue(100),
                        Select::make('language')->label('Langue de l\'annonce')->options(Catalog::LANGUAGES)->native(false),
                        TextInput::make('salary_min')->label('Salaire min. (CHF/an)')->integer()->minValue(0),
                        TextInput::make('salary_max')->label('Salaire max. (CHF/an)')->integer()->minValue(0),
                        TextInput::make('company_address')->label('Adresse de l\'entreprise')
                            ->helperText('Pour le justificatif ORP.')->maxLength(255),
                    ]),
                Section::make('Documents de candidature')
                    ->description('Remplis automatiquement depuis le registre quand Claude prépare la candidature.')
                    ->columnSpan(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('documents_url')->label('Dossier des documents (lien Google Drive)')->url()->maxLength(500),
                        Textarea::make('cover_letter_text')->label('Lettre de motivation (texte)')->rows(10),
                        TextInput::make('message_subject')->label('Objet')->maxLength(255),
                        Textarea::make('message_body')->label('Message d\'accompagnement')->rows(6),
                    ]),
                Section::make('ATS')
                    ->description('Système de tri des candidatures. Sert à voir s\'il pénalise tes envois.')
                    ->columnSpan(2)
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        Select::make('ats')->label('Passe par un ATS')->options(Catalog::ATS)->native(false),
                        TextInput::make('ats_system')->label('Système ATS')->datalist(Catalog::ATS_SYSTEMS)->maxLength(100)
                            ->helperText('Deviné depuis le lien de l\'annonce quand c\'est possible.'),
                        TextInput::make('ats_score')->label('Score ATS (%)')->integer()->minValue(0)->maxValue(100)
                            ->helperText('Part des mots-clés de l\'annonce présents dans le CV envoyé.'),
                        Textarea::make('ats_missing_keywords')->label('Mots-clés manquants')->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Suivi')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('status')
                            ->label('Statut')
                            ->options(OpportunityStatus::class)
                            ->default(OpportunityStatus::ToEvaluate)
                            ->required()
                            ->native(false),
                        ToggleButtons::make('relevance')
                            ->label('Pertinence')
                            ->options([1 => '1', 2 => '2', 3 => '3'])
                            ->colors([1 => 'gray', 2 => 'info', 3 => 'success'])
                            ->inline(),
                        DatePicker::make('applied_at')
                            ->label('Date de candidature')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->helperText('Remplie automatiquement au passage à « Candidature envoyée ».'),
                        DatePicker::make('follow_up_at')
                            ->label('Relance prévue')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Textarea::make('feedback')->label('Retour de l\'entreprise')->rows(3),
                        DatePicker::make('followed_up_at')->label('Relancé le')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('first_response_at')->label('Première réponse le')->native(false)->displayFormat('d.m.Y'),
                        Select::make('response_type')->label('Type de réponse')->options(Catalog::RESPONSE_TYPES)->native(false),
                        Select::make('refusal_reason')->label('Motif de refus')->options(Catalog::REFUSAL_REASONS)->native(false)
                            ->visible(fn ($get) => $get('response_type') === 'refus')->live(),
                        DatePicker::make('interview_at')->label('Premier entretien le')->native(false)->displayFormat('d.m.Y'),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function sources(): array
    {
        $defaults = array_values(Catalog::SOURCES);
        $used = Opportunity::query()->whereNotNull('source')->distinct()->pluck('source')->all();
        $all = array_values(array_unique(array_merge($defaults, $used)));

        return array_combine($all, $all);
    }
}
