<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationAttachment;
use Illuminate\Http\Response;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces jointes de candidature : téléchargement, affichage d'un PDF et visionneuse PDF.js.
 * Routes réservées au propriétaire connecté.
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

    /**
     * Le PDF lui-même, lu par la visionneuse. Le nom est gardé tel quel s'il est enregistré depuis la visionneuse.
     */
    public function inline(ApplicationAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->isPdf() && Application::attachmentDisk()->exists($attachment->path), 404);

        return Application::attachmentDisk()->response($attachment->path, $attachment->name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $attachment->name, self::asciiName($attachment->name)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Visionneuse PDF.js hébergée sur le site : zoom, pages, miniatures, recherche, plein écran,
     * impression et téléchargement, sans quitter le tableau de bord.
     */
    public function viewer(ApplicationAttachment $attachment): Response
    {
        abort_unless($attachment->isPdf(), 404);

        $html = (string) file_get_contents(resource_path('pdfjs/viewer.html'));
        $html = str_replace('<head>', '<head>'."\n    ".'<base href="'.e(asset('pdfjs/web').'/').'" />', $html);
        $html = preg_replace('#<title>.*?</title>#s', '<title>'.e($attachment->name).'</title>', $html);
        // Réglages (français, pas de scripts PDF) chargés avant la visionneuse ; la page garde sa propre CSP stricte.
        $html = str_replace('<script src="viewer.mjs" type="module"></script>', '<script src="jobs-config.js"></script>'."\n".'  <script src="viewer.mjs" type="module"></script>', $html);

        // Page autonome : pas de scripts Livewire ajoutés automatiquement par le panneau.
        SupportAutoInjectedAssets::$forceAssetInjection = false;
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function viewerUrl(ApplicationAttachment $attachment): string
    {
        return route('filament.admin.attachments.view', $attachment).'?file='.rawurlencode(route('filament.admin.attachments.inline', $attachment, false));
    }

    private static function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));

        return str_replace(['/', '\\', '%'], '_', $ascii ?: 'document.pdf');
    }
}
