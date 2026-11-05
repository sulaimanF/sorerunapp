<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyMembershipEmail extends Notification
{
    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verifikasi email sorerunapp')->greeting('Halo!')
            ->line('Verifikasi alamat email untuk melanjutkan aktivasi keanggotaan Sore Running Club.')
            ->action('Verifikasi email', $this->url)
            ->line('Buka tautan ini lalu konfirmasi Verifikasi Email. Setelah berhasil, Anda dapat login. Tautan berlaku selama 60 menit.')
            ->line('Jika Anda tidak membuat akun ini, abaikan email ini.');
    }
}
