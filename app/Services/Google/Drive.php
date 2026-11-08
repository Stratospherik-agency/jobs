<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;

/**
 * Archivage des candidatures dans Google Drive (dossier « Candidatures », un sous-dossier par offre).
 * La portée drive.file limite l'accès aux seuls fichiers créés par le tableau de bord.
 */
class Drive
{
    public function __construct(private GoogleClient $google) {}

    /**
     * @return array{id: string, url: string}
     */
    public function folderForApplication(string $name): array
    {
        $root = $this->rootFolderId();

        return $this->createFolder($name, $root);
    }

    /**
     * @return array{id: string, url: string}
     */
    public function upload(string $folderId, string $name, string $mime, string $contents): array
    {
        $boundary = 'candidatures'.bin2hex(random_bytes(8));
        $metadata = json_encode(['name' => $name, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);

        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$contents}\r\n--{$boundary}--";

        $response = GoogleClient::check(
            $this->google->api()
                ->withBody($body, 'multipart/related; boundary='.$boundary)
                ->post(GoogleClient::DRIVE_UPLOAD.'/files?uploadType=multipart&fields=id,webViewLink'),
            'Envoi vers Google Drive impossible'
        );

        return ['id' => $response->json('id'), 'url' => $response->json('webViewLink')];
    }

    private function rootFolderId(): string
    {
        $connection = GoogleConnection::current();

        if ($connection?->drive_root_folder_id) {
            $exists = $this->google->api()->get(GoogleClient::DRIVE.'/files/'.$connection->drive_root_folder_id, ['fields' => 'id,trashed']);
            if ($exists->successful() && ! $exists->json('trashed')) {
                return $connection->drive_root_folder_id;
            }
        }

        $folder = $this->createFolder((string) config('services.google.drive_root_folder'));
        $connection?->update(['drive_root_folder_id' => $folder['id']]);

        return $folder['id'];
    }

    /**
     * @return array{id: string, url: string}
     */
    private function createFolder(string $name, ?string $parent = null): array
    {
        $response = GoogleClient::check(
            $this->google->api()->post(GoogleClient::DRIVE.'/files?fields=id,webViewLink', array_filter([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => $parent ? [$parent] : null,
            ])),
            'Création du dossier Drive impossible'
        );

        return ['id' => $response->json('id'), 'url' => $response->json('webViewLink')];
    }
}
