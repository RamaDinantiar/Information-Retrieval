<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\User;
use App\Support\Notifications\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * US-D04-1: email aktivasi berisi password sementara (akun baru / reset Admin).
 * Praktikan wajib mengganti password saat login pertama (FR-AUTH-05).
 *
 * Payload antrean dienkripsi (ShouldBeEncrypted) karena memuat password sementara; dicoba ulang
 * bila SMTP kampus menolak, dan Admin diberi tahu bila tetap gagal agar bisa mengatur ulang password.
 */
class AccountCredentialsNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly string $temporaryPassword,
        private readonly bool $isReset = false,
        private readonly ?string $accountName = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $login = $notifiable->nim ?? $notifiable->email;

        return (new MailMessage)
            ->subject($this->isReset ? 'Password sementara baru — Portal IR Lab' : 'Akun Portal IR Lab Anda')
            ->greeting("Halo, {$notifiable->name}!")
            ->line($this->isReset
                ? 'Admin lab mengatur ulang password akun Anda.'
                : 'Akun Anda di Portal Laboratorium Information Retrieval telah dibuat.')
            ->line("NIM/Email: **{$login}**")
            ->line("Password sementara: **{$this->temporaryPassword}**")
            ->action('Masuk ke Portal', route('login'))
            ->line('Demi keamanan, Anda wajib membuat password baru saat pertama kali masuk.');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Email password sementara gagal dikirim: '.($this->accountName ?? 'akun'), ['error' => $exception?->getMessage()]);

        // Hanya lonceng (tanpa email): email ke akun yang sama bisa gagal lagi.
        Notify::send(
            User::where('role', Role::Admin)->where('is_active', true)->get(),
            'account',
            'Email password sementara gagal terkirim',
            ($this->accountName ?? 'Seorang pengguna').' belum menerima password sementara. Atur ulang password dari Admin › Pengguna.',
            '/admin/users',
            mail: false,
        );
    }
}
