<?php

namespace App\Actions\Task;

use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\Submission;
use App\Models\TaskTarget;
use App\Models\User;
use App\Support\Files\FileStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * US-P02: kumpulkan tugas. Validasi format sesuai pengaturan tugas, satu pengumpulan aktif per
 * praktikan (ganti jawaban = batalkan dulu), label terlambat, nomor tanda terima.
 * Gagal di tengah jalan → tidak ada submission/ file setengah jadi.
 */
class SubmitTask
{
    private const ALREADY_SUBMITTED = 'Anda sudah mengumpulkan. Batalkan pengumpulan (Unsend) dulu jika ingin mengubah jawaban.';

    public function __construct(private readonly FileStore $files) {}

    /**
     * @param  list<UploadedFile>  $uploads
     * @param  list<int>  $keepFileIds  berkas dari draf (hasil Unsend) yang ikut dikirim
     */
    public function __invoke(User $user, TaskTarget $target, array $uploads, ?string $text, array $keepFileIds = []): Submission
    {
        $task = $target->task;
        $now = now();
        $text = $text !== null ? trim($text) : null;

        if ($target->state($now) !== 'open') {
            $this->fail('submission', match (true) {
                $target->state($now) !== 'closed' => 'Tugas belum dibuka.',
                $target->closed_at !== null => 'Pengumpulan sudah dihentikan oleh asisten.',
                default => 'Tenggat sudah lewat; tugas ini tidak menerima pengumpulan terlambat.',
            });
        }

        // Sudah mengumpulkan → tidak bisa mengumpulkan lagi, kecuali dibatalkan
        // dulu (UnsubmitTask). Mencegah jawaban tertimpa tanpa sengaja.
        if (Submission::where('task_target_id', $target->id)->where('user_id', $user->id)->where('is_current', true)->exists()) {
            $this->fail('submission', self::ALREADY_SUBMITTED);
        }

        // Seperti Teams: setelah Unsend, berkas lama tetap menjadi draf dan boleh dipakai lagi.
        $reuse = $this->draftFor($user, $target)?->files->whereIn('id', $keepFileIds)->values() ?? new Collection;
        $total = count($uploads) + $reuse->count();

        if ($task->allowed_formats !== [] && $total === 0 && $task->text_answer !== 'required') {
            $this->fail('file', 'Unggah file jawaban ('.strtoupper(implode('/', $task->allowed_formats)).').');
        }

        $max = (int) config('lab.files.submission_count');
        if ($total > $max) {
            $this->fail('file', "Maksimal {$max} berkas per pengumpulan.");
        }

        // ZIP berdiri sendiri (dibuka di Code Viewer); PDF boleh beberapa.
        $zips = collect($uploads)->filter(fn (UploadedFile $f) => strtolower($f->getClientOriginalExtension()) === 'zip')->count()
            + $reuse->where('mime', '!=', 'application/pdf')->count();
        if ($zips > 0 && $total > 1) {
            $this->fail('file', 'File ZIP harus dikumpulkan sendiri. Hapus berkas lain atau kumpulkan PDF saja.');
        }

        if ($task->text_answer === 'required' && ($text === null || $text === '')) {
            $this->fail('text_answer', 'Jawaban teks wajib diisi.');
        }

        if ($task->text_answer === 'none') {
            $text = null;
        }

        if ($total === 0 && ($text === null || $text === '')) {
            $this->fail('file', 'Isi jawaban teks atau unggah file.');
        }

        $stored = [];

        try {
            return DB::transaction(function () use ($user, $target, $uploads, $text, $now, $reuse, &$stored) {
                // Kunci baris target: dua permintaan bersamaan (klik ganda) berjalan berurutan, dan yang
                // kedua melihat pengumpulan pertama alih-alih gagal di unique index versi.
                TaskTarget::whereKey($target->id)->lockForUpdate()->first();

                if (Submission::where('task_target_id', $target->id)->where('user_id', $user->id)->where('is_current', true)->exists()) {
                    $this->fail('submission', self::ALREADY_SUBMITTED);
                }

                $lateMinutes = $target->isPastDue($now) ? max(1, (int) floor($target->due_at->diffInMinutes($now))) : 0;

                // Nomor versi terus naik, termasuk pengumpulan yang pernah dibatalkan.
                $version = (int) Submission::withTrashed()->where('task_target_id', $target->id)->where('user_id', $user->id)->max('version') + 1;

                $submission = Submission::create([
                    'task_target_id' => $target->id,
                    'user_id' => $user->id,
                    'text_answer' => $text,
                    'submitted_at' => $now,
                    'is_late' => $lateMinutes > 0,
                    'late_minutes' => $lateMinutes,
                    'version' => $version,
                    'is_current' => true,
                ]);

                // FR-PT-05: nomor tanda terima.
                $submission->forceFill(['receipt_number' => sprintf('IRL-%s-%06d', $now->format('ymd'), $submission->id)])->save();

                $reuse->each(fn (StoredFile $file) => $file->forceFill(['owner_type' => $submission->getMorphClass(), 'owner_id' => $submission->id])->save());

                foreach ($uploads as $upload) {
                    $stored[] = $this->files->store($upload, $target->task->allowed_formats, 'submission', $submission, $user, 'file');
                }

                AuditLog::record('submission.created', $submission, null, ['version' => $submission->version, 'late' => $submission->is_late]);

                return $submission;
            });
        } catch (Throwable $exception) {
            // Transaksi dibatalkan → hapus file yang sudah sempat tersimpan.
            foreach ($stored as $file) {
                $file->delete();
            }

            throw $exception;
        }
    }

    /**
     * Pengumpulan terakhir yang dibatalkan (Unsend) — sumber draf berkas/jawaban.
     */
    public function draftFor(User $user, TaskTarget $target): ?Submission
    {
        if (Submission::where('task_target_id', $target->id)->where('user_id', $user->id)->where('is_current', true)->exists()) {
            return null;
        }

        return Submission::onlyTrashed()->with('files')->where('task_target_id', $target->id)->where('user_id', $user->id)
            ->orderByDesc('version')->first();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
