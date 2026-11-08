<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLoginAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $ip,
        public string $userAgent,
        public CarbonInterface $at,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nouvelle connexion au tableau de bord des candidatures')
            ->greeting('Bonjour,')
            ->line('Une connexion à ton tableau de bord vient d\'avoir lieu.')
            ->line('Date : '.$this->at->timezone('Europe/Zurich')->format('d.m.Y H:i'))
            ->line('Adresse IP : '.$this->ip)
            ->line('Navigateur : '.$this->userAgent)
            ->line('Si ce n\'est pas toi, change immédiatement ton mot de passe et réinitialise la double authentification depuis le serveur (php artisan dashboard:owner).')
            ->salutation(' ');
    }
}
