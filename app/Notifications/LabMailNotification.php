<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Salinan email dari notifikasi lonceng (semua peran). Selalu lewat antrean dan
 * dicoba ulang bila SMTP kampus gagal, supaya aksi pengguna tidak menunggu atau error.
 */
class LabMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title)->greeting("Halo, {$notifiable->name}")->line($this->body);

        return $this->url ? $message->action('Buka IR Lab', url($this->url)) : $message;
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Email notifikasi gagal dikirim: '.$this->title, ['kind' => $this->kind, 'error' => $exception?->getMessage()]);
    }
}
