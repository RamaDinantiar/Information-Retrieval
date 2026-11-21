<?php

namespace App\Support\Notifications;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LabMailNotification;
use App\Notifications\LabNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Pintasan mengirim notifikasi ke sekumpulan pengguna aktif: lonceng (langsung) dan salinan
 * email kampus (antrean) untuk semua peran, selama saklar email di Pengaturan Sistem menyala.
 */
class Notify
{
    /**
     * @param  iterable<User>|User|array<int, int>  $users
     */
    public static function send(iterable|User $users, string $kind, string $title, string $body, ?string $url = null, bool $mail = true): void
    {
        $recipients = self::resolve($users);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new LabNotification($kind, $title, $body, $url));

            if ($mail && Setting::value('email_notifications')) {
                self::mail($recipients, $kind, $title, $body, $url);
            }
        }
    }

    /**
     * Email dikirim bertahap (20 penerima per 30 detik) agar tidak melewati batas kirim SMTP.
     *
     * @param  Collection<int, User>  $recipients
     */
    private static function mail(Collection $recipients, string $kind, string $title, string $body, ?string $url): void
    {
        $deliverable = $recipients->filter(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL) !== false)->values();

        foreach ($deliverable->chunk(20) as $index => $chunk) {
            $mail = new LabMailNotification($kind, $title, $body, $url);

            if ($index > 0) {
                $mail->delay(now()->addSeconds($index * 30));
            }

            Notification::send($chunk, $mail);
        }
    }

    public static function admins(string $kind, string $title, string $body, ?string $url = null): void
    {
        self::send(User::where('role', Role::Admin)->where('is_active', true)->get(), $kind, $title, $body, $url);
    }

    /**
     * @param  iterable<User>|User|array<int, int>  $users
     * @return Collection<int, User>
     */
    private static function resolve(iterable|User $users): Collection
    {
        if ($users instanceof User) {
            return collect([$users]);
        }

        $items = collect($users);

        return $items->every(fn ($item) => is_int($item))
            ? User::whereKey($items->unique()->all())->where('is_active', true)->get()
            : $items->filter(fn ($u) => $u instanceof User && $u->is_active)->values();
    }
}
