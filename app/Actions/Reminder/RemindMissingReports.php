<?php

namespace App\Actions\Reminder;

use App\Enums\Role;
use App\Models\ReminderLog;
use App\Models\Shift;
use App\Models\SupervisionReport;
use App\Support\Notifications\Notify;

/**
 * Pengingat "belum mengisi laporan ngawas": shift 3 hari terakhir (sampai kemarin) yang belum
 * punya laporan Diajukan/Diverifikasi dari asisten itu. Satu pesan per asisten per hari, maksimal
 * 3 kali per shift.
 */
class RemindMissingReports
{
    public const MAX_REPEATS = 3;

    public function __invoke(): int
    {
        $from = now()->subDays(3)->toDateString();
        $to = now()->subDay()->toDateString();

        $shifts = Shift::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereHas('user', fn ($q) => $q->where('role', Role::Asisten)->where('is_active', true))
            ->with(['user:id,name', 'labClass.course'])
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->groupBy('user_id');

        $sent = 0;

        foreach ($shifts as $userId => $items) {
            $missing = $items->filter(fn (Shift $shift) => ! $this->hasReport((int) $userId, $shift))
                ->filter(fn (Shift $shift) => ReminderLog::claim((int) $userId, 'missing_report', 'shift:'.$shift->id, self::MAX_REPEATS));

            if ($missing->isEmpty()) {
                continue;
            }

            $list = $missing->map(fn (Shift $s) => $s->date->translatedFormat('d M').' · '.$s->displayTitle())->implode('; ');
            Notify::send([(int) $userId], 'reminder', 'Laporan ngawas belum diisi', "Anda belum mengirim laporan untuk shift: {$list}. Laporan dapat diubah sampai H+1 pukul 23.59.", '/asisten/report');
            $sent++;
        }

        return $sent;
    }

    private function hasReport(int $userId, Shift $shift): bool
    {
        return SupervisionReport::query()
            ->whereDate('date', $shift->date->toDateString())
            ->whereIn('status', ['submitted', 'verified'])
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhereHas('assistants', fn ($a) => $a->whereKey($userId)))
            ->exists();
    }
}
