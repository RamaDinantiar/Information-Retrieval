<?php

namespace App\Actions\Reminder;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Models\LabClass;
use App\Models\ReminderLog;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use App\Support\Notifications\Notify;

/**
 * Ringkasan harian "belum menilai" untuk asisten pengampu: pengumpulan terbaru yang sudah lebih
 * dari 1 hari belum bernilai (semua jenis tugas, kelas semester berjalan).
 */
class RemindUngradedSubmissions
{
    public function __invoke(): int
    {
        $semester = Semester::current();

        if ($semester === null) {
            return 0;
        }

        $sent = 0;

        User::query()->where('role', Role::Asisten)->where('is_active', true)->get()->each(function (User $user) use ($semester, &$sent) {
            $pending = Submission::where('is_current', true)
                ->where('submitted_at', '<=', now()->subDay())
                ->whereDoesntHave('grade', fn ($g) => $g->whereNotNull('score'))
                ->whereHas('target', fn ($q) => $q
                    ->whereIn('class_id', LabClass::forSemester($semester)->where('approval_status', ApprovalStatus::Approved)->taughtBy($user)->select('id'))
                    ->whereHas('task', fn ($t) => $t->where('status', 'published')))
                ->count();

            if ($pending === 0 || ! ReminderLog::claim($user->id, 'ungraded', 'summary')) {
                return;
            }

            Notify::send([$user->id], 'reminder', 'Pengumpulan belum dinilai', "{$pending} pengumpulan di kelas Anda belum dinilai lebih dari 1 hari.", '/asisten/grade');
            $sent++;
        });

        return $sent;
    }
}
