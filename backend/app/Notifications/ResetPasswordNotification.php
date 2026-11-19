<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email tautan buat atur ulang password. Tautan berlaku selama 60 menitt. */
class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Atur ulang password Kartu Anggota')
            ->greeting("Halo {$notifiable->name},")
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Kartu Anggota Anda.')
            ->action('Buat password baru', $url)
            ->line("Tautan ini berlaku $minutes menit dan hanya bisa dipakai sekali.")
            ->line('Bila Anda tidak memintanya, abaikan email ini — password Anda tidak berubah.')
            ->salutation('Salam, '.config('attendance.instance_name', 'Kartu Anggota'));
    }
}
