<?php

namespace App\Actions\Reminder;

use App\Enums\Position;
use App\Models\ReminderLog;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Notifications\Notify;

/**
 * Ringkasan harian untuk Sekretaris/Bendahara: laporan ngawas berstatus Diajukan lebih dari 1 hari
 * yang belum diverifikasi (laporan milik sendiri tidak dihitung).
 */
class RemindPendingVerification
{
    public function __invoke(): int
    {
        $sent = 0;

        User::query()->where('position', Position::SekretarisBendahara)->where('is_active', true)->get()->each(function (User $user) use (&$sent) {
            $pending = SupervisionReport::where('status', 'submitted')->where('user_id', '!=', $user->id)->where('updated_at', '<=', now()->subDay())->count();

            if ($pending === 0 || ! ReminderLog::claim($user->id, 'pending_verification', 'summary')) {
                return;
            }

            Notify::send([$user->id], 'reminder', 'Laporan ngawas menunggu verifikasi', "{$pending} laporan ngawas sudah menunggu verifikasi lebih dari 1 hari.", '/asisten/report?tab=verify');
            $sent++;
        });

        return $sent;
    }
}
