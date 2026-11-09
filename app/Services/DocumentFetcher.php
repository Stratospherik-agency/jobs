<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Opportunity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Récupère sur le serveur les PDF exportés depuis Canva (CV, lettre, dossier) dont les liens
 * temporaires sont notés dans le registre, et les range dans la candidature de l'offre.
 * Seul le domaine de téléchargement de Canva est accepté. Rien n'est envoyé.
 */
class DocumentFetcher
{
    public const ALLOWED_HOSTS = ['export-download.canva.com'];

    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @return list<array{name: string, url: string}>
     */
    public static function parse(?string $links): array
    {
        $files = [];

        foreach (preg_split('/\R/', (string) $links) as $line) {
            if (! preg_match('#^\s*(?:(.+?)\s*[:|]\s*)?(https://\S+)\s*$#u', $line, $m)) {
                continue;
            }

            $url = $m[2];
            $name = trim($m[1] ?? '') ?: basename((string) parse_url($url, PHP_URL_PATH)) ?: 'document.pdf';
            if (! str_ends_with(mb_strtolower($name), '.pdf')) {
                $name .= '.pdf';
            }

            $files[] = ['name' => $name, 'url' => $url];
        }

        return $files;
    }

    /**
     * Télécharge les PDF pas encore importés.
     *
     * @return array{imported: int, errors: list<string>}
     */
    public function fetch(Opportunity $opportunity): array
    {
        $result = ['imported' => 0, 'errors' => []];
        $files = self::parse($opportunity->documents_links);

        if (! $files) {
            return $result;
        }

        $application = $opportunity->application;

        if ($application?->isSent()) {
            return $result; // dossier figé une fois la candidature partie
        }

        foreach ($files as $file) {
            $hash = hash('sha256', $file['url']);

            if ($application && $application->attachments()->where('source_hash', $hash)->exists()) {
                continue;
            }

            // Un lien en échec n'est signalé qu'une fois par jour.
            if (Cache::has('document-fetch-failed:'.$hash)) {
                continue;
            }

            if (! in_array(mb_strtolower((string) parse_url($file['url'], PHP_URL_HOST)), self::ALLOWED_HOSTS, true)) {
                Cache::put('document-fetch-failed:'.$hash, true, now()->addDay());
                $result['errors'][] = "{$opportunity->company} : lien refusé pour {$file['name']} (seuls les exports Canva sont acceptés).";

                continue;
            }

            try {
                $response = Http::timeout(60)->withOptions(['allow_redirects' => false])->get($file['url']);
            } catch (Throwable $e) {
                Cache::put('document-fetch-failed:'.$hash, true, now()->addDay());
                $result['errors'][] = "{$opportunity->company} : téléchargement impossible de {$file['name']}.";

                continue;
            }

            $data = $response->body();

            if (! $response->successful() || ! str_starts_with($data, '%PDF') || strlen($data) > self::MAX_BYTES) {
                Cache::put('document-fetch-failed:'.$hash, true, now()->addDay());
                $result['errors'][] = "{$opportunity->company} : {$file['name']} illisible ou lien expiré, à réexporter depuis Canva.";

                continue;
            }

            $application ??= $opportunity->application()->create(['status' => ApplicationStatus::Draft]);

            // Un fichier du même nom remplace la version précédente.
            $application->attachments()->where('name', $file['name'])->get()->each->delete();

            $path = 'candidatures/'.$application->id.'/'.Str::random(12).'-'.Str::slug(pathinfo($file['name'], PATHINFO_FILENAME)).'.pdf';
            Application::attachmentDisk()->put($path, $data);
            $application->attachments()->create([
                'name' => $file['name'],
                'path' => $path,
                'mime' => 'application/pdf',
                'size' => strlen($data),
                'source_hash' => $hash,
            ]);
            $result['imported']++;
        }

        if ($result['imported']) {
            $opportunity->events()->create([
                'type' => 'note',
                'body' => $result['imported'].' document(s) PDF ajouté(s) depuis Canva',
                'happened_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Passe en revue toutes les offres qui ont des liens de documents.
     *
     * @return array{imported: int, errors: list<string>}
     */
    public function fetchAll(): array
    {
        $total = ['imported' => 0, 'errors' => []];

        Opportunity::query()->whereNotNull('documents_links')->with('application')->each(function (Opportunity $o) use (&$total) {
            $r = $this->fetch($o);
            $total['imported'] += $r['imported'];
            $total['errors'] = [...$total['errors'], ...$r['errors']];
        });

        return $total;
    }
}
