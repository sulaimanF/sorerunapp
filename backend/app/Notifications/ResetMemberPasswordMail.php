<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class ResetMemberPasswordMail extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $url, private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Reset password sorerunapp')->greeting('Halo!')
            ->line('Kami menerima permintaan untuk mengganti password akun sorerunapp Anda.')
            ->action('Reset Password', $this->url)
            ->line('Tautan berlaku '.$this->minutes.' menit sejak permintaan dan hanya dapat digunakan satu kali.')
            ->line('Setelah password diganti, semua sesi akun ini diakhiri. Silakan masuk kembali.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak berubah.');
    }
}
