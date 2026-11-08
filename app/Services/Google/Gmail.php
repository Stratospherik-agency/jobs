<?php

namespace App\Services\Google;

use Illuminate\Support\Carbon;

/**
 * Opérations Gmail utilisées par le tableau de bord.
 */
class Gmail
{
    public function __construct(private GoogleClient $google) {}

    /**
     * Envoie un message MIME déjà construit.
     *
     * @return array{id: string, threadId: string}
     */
    public function sendRaw(string $mime): array
    {
        $response = GoogleClient::check(
            $this->google->api()->post(GoogleClient::GMAIL.'/messages/send', ['raw' => self::b64url($mime)]),
            'Envoi Gmail impossible'
        );

        return ['id' => $response->json('id'), 'threadId' => $response->json('threadId')];
    }

    /**
     * Brouillons récents (pour les importer dans une candidature).
     *
     * @return list<array{id: string, to: string, subject: string, snippet: string}>
     */
    public function listDrafts(int $max = 20): array
    {
        $drafts = GoogleClient::check(
            $this->google->api()->get(GoogleClient::GMAIL.'/drafts', ['maxResults' => $max]),
            'Lecture des brouillons impossible'
        )->json('drafts') ?? [];

        $result = [];
        foreach ($drafts as $draft) {
            $message = GoogleClient::check(
                $this->google->api()->get(GoogleClient::GMAIL.'/drafts/'.$draft['id'], [
                    'format' => 'metadata',
                ]),
                'Lecture du brouillon impossible'
            )->json('message');

            $headers = $this->headers($message['payload']['headers'] ?? []);
            $result[] = [
                'id' => $draft['id'],
                'to' => $headers['to'] ?? '',
                'subject' => $headers['subject'] ?? '(sans objet)',
                'snippet' => html_entity_decode((string) ($message['snippet'] ?? ''), ENT_QUOTES),
            ];
        }

        return $result;
    }

    /**
     * Contenu complet d'un brouillon : destinataires, objet, texte et pièces jointes.
     *
     * @return array{to: string, cc: string, subject: string, body: string, attachments: list<array{name: string, mime: string, data: string}>}
     */
    public function getDraft(string $id): array
    {
        $message = GoogleClient::check(
            $this->google->api()->get(GoogleClient::GMAIL.'/drafts/'.$id, ['format' => 'full']),
            'Lecture du brouillon impossible'
        )->json('message');

        $headers = $this->headers($message['payload']['headers'] ?? []);
        $text = null;
        $html = null;
        $attachments = [];

        $this->walk($message['payload'], function (array $part) use ($message, &$text, &$html, &$attachments) {
            $mime = $part['mimeType'] ?? '';
            $filename = $part['filename'] ?? '';

            if ($filename !== '') {
                $data = $part['body']['data'] ?? null;
                if (! $data && isset($part['body']['attachmentId'])) {
                    $data = GoogleClient::check(
                        $this->google->api()->get(GoogleClient::GMAIL.'/messages/'.$message['id'].'/attachments/'.$part['body']['attachmentId']),
                        'Téléchargement d\'une pièce jointe impossible'
                    )->json('data');
                }
                $attachments[] = ['name' => $filename, 'mime' => $mime ?: 'application/octet-stream', 'data' => self::unb64url((string) $data)];

                return;
            }

            if ($mime === 'text/plain' && $text === null) {
                $text = self::unb64url((string) ($part['body']['data'] ?? ''));
            } elseif ($mime === 'text/html' && $html === null) {
                $html = self::unb64url((string) ($part['body']['data'] ?? ''));
            }
        });

        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $html)), ENT_QUOTES));
        }

        return [
            'to' => $headers['to'] ?? '',
            'cc' => $headers['cc'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'body' => (string) $text,
            'attachments' => $attachments,
        ];
    }

    public function deleteDraft(string $id): void
    {
        rescue(fn () => $this->google->api()->delete(GoogleClient::GMAIL.'/drafts/'.$id), report: false);
    }

    /**
     * Échanges liés à une candidature : le fil de l'envoi, plus les messages avec le domaine du destinataire.
     *
     * @return list<array{id: string, threadId: string, date: ?Carbon, from: string, to: string, subject: string, snippet: string, url: string}>
     */
    public function correspondence(?string $threadId, ?string $email, int $max = 15): array
    {
        $ids = [];

        if ($threadId) {
            $thread = $this->google->api()->get(GoogleClient::GMAIL.'/threads/'.$threadId, ['format' => 'minimal']);
            foreach ($thread->json('messages') ?? [] as $message) {
                $ids[$message['id']] = true;
            }
        }

        if ($query = self::queryFor($email)) {
            $found = $this->google->api()->get(GoogleClient::GMAIL.'/messages', ['q' => $query, 'maxResults' => $max]);
            foreach ($found->json('messages') ?? [] as $message) {
                $ids[$message['id']] = true;
            }
        }

        $messages = [];
        foreach (array_slice(array_keys($ids), 0, $max) as $id) {
            // Gmail attend « metadataHeaders » répété, sans crochets.
            $message = $this->google->api()->get(GoogleClient::GMAIL.'/messages/'.$id
                .'?format=metadata&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Subject&metadataHeaders=Date')->json();

            if (! isset($message['id'])) {
                continue;
            }

            $headers = $this->headers($message['payload']['headers'] ?? []);
            $messages[] = [
                'id' => $message['id'],
                'threadId' => $message['threadId'],
                'date' => isset($message['internalDate']) ? Carbon::createFromTimestampMs((int) $message['internalDate'])->timezone(config('app.timezone')) : null,
                'from' => $headers['from'] ?? '',
                'to' => $headers['to'] ?? '',
                'subject' => $headers['subject'] ?? '(sans objet)',
                'snippet' => html_entity_decode((string) ($message['snippet'] ?? ''), ENT_QUOTES),
                'url' => 'https://mail.google.com/mail/u/0/#all/'.$message['threadId'],
            ];
        }

        usort($messages, fn ($a, $b) => ($b['date']?->timestamp ?? 0) <=> ($a['date']?->timestamp ?? 0));

        return $messages;
    }

    /**
     * Recherche Gmail : adresse exacte, ou tout le domaine s'il n'est pas un webmail.
     */
    public static function queryFor(?string $email): ?string
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $domain = mb_strtolower(substr(strrchr($email, '@'), 1));
        $webmails = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'bluewin.ch', 'gmx.ch', 'gmx.net', 'protonmail.com', 'proton.me', 'sunrise.ch', 'hispeed.ch'];

        $target = in_array($domain, $webmails, true) ? $email : '@'.$domain;

        return "(from:{$target} OR to:{$target})";
    }

    /**
     * @param  list<array{name: string, value: string}>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $result[mb_strtolower($header['name'])] = $header['value'];
        }

        return $result;
    }

    private function walk(array $part, callable $callback): void
    {
        $callback($part);
        foreach ($part['parts'] ?? [] as $child) {
            $this->walk($child, $callback);
        }
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
