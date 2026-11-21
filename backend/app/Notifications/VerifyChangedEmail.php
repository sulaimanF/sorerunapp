<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class VerifyChangedEmail extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Konfirmasi perubahan email sorerunapp')->greeting('Halo!')
            ->line('Kami menerima permintaan untuk memakai alamat ini sebagai email akun sorerunapp Anda.')
            ->action('Konfirmasi Email Baru', $this->url)
            ->line('Tautan berlaku 60 menit sejak permintaan dan hanya dapat digunakan satu kali.')
            ->line('Masuk menggunakan alamat aktif lama jika sesi Anda berakhir, lalu buka ulang tautan ini.')
            ->line('Konfirmasi akan mengakhiri seluruh sesi. Setelah berhasil, masuk kembali menggunakan email baru.')
            ->line('Jika Anda tidak meminta perubahan ini, abaikan email ini. Email aktif tidak berubah.');
    }
}
