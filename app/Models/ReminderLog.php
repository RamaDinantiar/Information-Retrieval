<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan pengingat yang sudah dikirim (lihat command `reminders:send`).
 * `claim()` mengembalikan true hanya bila pengingat itu boleh dikirim hari ini.
 */
class ReminderLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_on' => 'date'];
    }

    /**
     * @param  int|null  $max  batas pengulangan per objek (null = tanpa batas, tetap sekali per hari)
     */
    public static function claim(int $userId, string $kind, string $subject, ?int $max = null): bool
    {
        $today = now()->toDateString();

        if (self::where(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject])->whereDate('sent_on', $today)->exists()) {
            return false;
        }

        if ($max !== null && self::where(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject])->count() >= $max) {
            return false;
        }

        self::create(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'sent_on' => $today]);

        return true;
    }
}
