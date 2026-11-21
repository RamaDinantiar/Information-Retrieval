<?php

namespace App\Actions\User;

use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Notifications\Notify;
use Illuminate\Support\Collection;

/**
 * Jabatan Sekretaris/Bendahara hanya dipegang satu akun. Saat Admin menunjuk akun baru, pemegang
 * lama (aktif maupun tidak) otomatis menjadi Asisten Umum; keduanya mendapat notifikasi dan
 * perubahannya tercatat di audit log.
 */
class TransferSecretaryPosition
{
    /**
     * Pemegang jabatan selain `$except` (akun yang sedang diubah/dibuat).
     *
     * @return Collection<int, User>
     */
    public static function otherHolders(?User $except = null): Collection
    {
        return User::query()->where('position', Position::SekretarisBendahara)
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->orderBy('name')->get();
    }

    /** Dipanggil sesudah `$new` disimpan dengan jabatan Sekretaris/Bendahara. */
    public function __invoke(User $new): int
    {
        $previous = self::otherHolders($new);

        foreach ($previous as $old) {
            $old->forceFill(['position' => Position::None])->save();

            AuditLog::record('user.role_changed', $old,
                ['position' => Position::SekretarisBendahara->value],
                ['position' => Position::None->value, 'reason' => "Jabatan dipindahkan ke {$new->name}"]);

            Notify::send([$old->id], 'approval', 'Jabatan Sekretaris/Bendahara dialihkan', "Jabatan Sekretaris/Bendahara kini dipegang {$new->name}. Anda kembali menjadi Asisten Umum.", '/asisten');
        }

        if ($previous->isNotEmpty()) {
            $names = $previous->pluck('name')->join(', ');
            Notify::send([$new->id], 'approval', 'Anda menjadi Sekretaris/Bendahara', "Anda ditunjuk sebagai Sekretaris/Bendahara menggantikan {$names}.", '/asisten');
        }

        return $previous->count();
    }
}
