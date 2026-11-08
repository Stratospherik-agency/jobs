<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar généré localement (initiale sur fond uni), pour ne rien envoyer
 * à un service tiers comme ui-avatars.com.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) filament()->getNameForDefaultAvatar($record);
        $initial = htmlspecialchars(mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?', ENT_QUOTES | ENT_XML1);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#0A5BD8"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="system-ui, sans-serif" font-size="30" fill="#ffffff">'.$initial.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
