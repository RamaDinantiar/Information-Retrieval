<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app (lonceng) — PRD 4.x "Notifikasi", selalu disimpan langsung.
 * Salinan email dikirim terpisah lewat antrean (LabMailNotification) agar halaman
 * tidak menunggu SMTP; reset password memakai notifikasi bawaan Fortify.
 */
class LabNotification extends Notification
{
    use Queueable;

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
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
