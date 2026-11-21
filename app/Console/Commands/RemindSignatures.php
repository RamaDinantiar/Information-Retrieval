<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Notifications\Notify;
use Illuminate\Console\Command;

/**
 * FR-RJN-15(c): mengingatkan asisten aktif yang belum mengunggah tanda tangan
 * (dipasang otomatis di sheet absensi Rekap Jam Ngawas). Dijadwalkan mingguan;
 * berhenti dengan sendirinya setelah tanda tangan diunggah. Lonceng + email.
 */
class RemindSignatures extends Command
{
    protected $signature = 'signatures:remind {--dry-run : Hanya tampilkan penerima, tidak mengirim}';

    protected $description = 'Ingatkan asisten aktif yang belum mengunggah tanda tangan untuk rekap jam ngawas';

    public function handle(): int
    {
        $users = User::query()->where('role', Role::Asisten)->where('is_active', true)->whereNull('signature_file_id')->get();

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Nama'], $users->map(fn (User $u) => [$u->id, $u->name])->all());

            return self::SUCCESS;
        }

        Notify::send($users, 'signature', 'Tanda tangan belum diunggah',
            'Tanda tangan Anda dipasang otomatis di Rekap Jam Ngawas. Unggah di Pengaturan › Profil agar kolom tanda tangan Anda tidak kosong.',
            '/settings/profile');

        $this->info("Pengingat dikirim ke {$users->count()} asisten.");

        return self::SUCCESS;
    }
}
