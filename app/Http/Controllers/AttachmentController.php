<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationAttachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'une pièce jointe de candidature. Route réservée au propriétaire connecté.
 */
class AttachmentController
{
    public function __invoke(ApplicationAttachment $attachment): StreamedResponse
    {
        abort_unless(Application::attachmentDisk()->exists($attachment->path), 404);

        return Application::attachmentDisk()->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
