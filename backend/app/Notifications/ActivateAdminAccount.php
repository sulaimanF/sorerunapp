<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class ActivateAdminAccount extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $url, private readonly bool $firstAccount = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->firstAccount ? 'Aktivasi Akun Operasional Pertama sorerunapp' : 'Undangan Admin sorerunapp')->greeting('Halo!')
            ->line($this->firstAccount ? 'Operator menerbitkan undangan aktivasi akun operasional pertama sorerunapp.' : 'Super Admin mengundang Anda sebagai Admin sorerunapp.')
            ->line('Konfirmasikan alamat email ini dan buat password Anda sendiri melalui tombol berikut.')
            ->action($this->firstAccount ? 'Aktifkan Akun Operasional' : 'Aktifkan Akun Admin', $this->url)
            ->line('Undangan berlaku 24 jam sejak diterbitkan dan hanya dapat digunakan sekali.')
            ->line('Aktivasi tidak mendaftarkan Anda sebagai anggota atau menerbitkan kartu QR.')
            ->when($this->firstAccount, fn (MailMessage $mail) => $mail->line('Aktivasi ini tidak memberikan role administratif. Operator menjalankan bootstrap terpisah setelah email dikonfirmasi.'))
            ->line('Jika Anda tidak mengenali undangan ini, abaikan email ini.');
    }
}
