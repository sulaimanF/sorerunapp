<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MembershipActivated extends Notification
{
    public function __construct(private readonly string $memberNumber) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Keanggotaan SRC aktif')->greeting('Selamat bergabung!')
            ->line('Keanggotaan Sore Running Club sudah aktif.')
            ->line('Nomor anggota: '.$this->memberNumber)
            ->line('Kartu dan QR keanggotaan tersedia melalui akun Anda.');
    }
}
