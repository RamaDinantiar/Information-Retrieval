<?php

namespace App\Actions\Task;

use App\Models\AuditLog;
use App\Models\Submission;
use App\Models\TaskTarget;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Batalkan pengumpulan (unsend) agar praktikan bisa mengumpulkan ulang.
 * Hanya selama tugas masih menerima pengumpulan dan belum ada nilai.
 */
class UnsubmitTask
{
    public function __invoke(User $user, TaskTarget $target): void
    {
        $submission = Submission::where('task_target_id', $target->id)->where('user_id', $user->id)->where('is_current', true)->first();

        if (! $submission) {
            $this->fail('Anda belum mengumpulkan tugas ini.');
        }

        if ($target->state() !== 'open') {
            $this->fail('Tugas sudah ditutup; pengumpulan tidak dapat dibatalkan.');
        }

        if ($submission->grade()->exists()) {
            $this->fail('Pengumpulan sudah dinilai asisten dan tidak dapat dibatalkan.');
        }

        $submission->forceFill(['is_current' => false])->save();
        $submission->delete();

        AuditLog::record('submission.withdrawn', $submission, null, ['version' => $submission->version]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['submission' => $message]);
    }
}
