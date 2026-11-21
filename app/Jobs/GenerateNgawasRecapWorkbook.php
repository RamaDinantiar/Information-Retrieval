<?php

namespace App\Jobs;

use App\Events\ExportFinished;
use App\Models\Export;
use App\Models\Semester;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Reports\NgawasApprovers;
use App\Support\Reports\NgawasRecap;
use App\Support\Reports\NgawasRecapWorkbook;
use App\Support\Reports\NgawasSignatureFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * FR-RJN-11: ekspor Excel format kampus (PhpSpreadsheet + template). Memakai pola
 * tabel `exports` + event ExportFinished. Mode `draft` untuk pengecekan (tanpa
 * tanda tangan pengesah, bertanda DRAFT); mode `final` untuk rekap yang ditetapkan Final.
 */
class GenerateNgawasRecapWorkbook implements ShouldQueue
{
    use Queueable;

    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public int $timeout = 300;

    public function __construct(public Export $export) {}

    public function handle(FileStore $files): void
    {
        $export = $this->export;
        $export->update(['status' => 'processing', 'progress' => 5]);

        $month = (string) $export->params_json['month'];
        $mode = ($export->params_json['mode'] ?? 'draft') === 'final' ? NgawasRecapWorkbook::FINAL : NgawasRecapWorkbook::DRAFT;
        $semester = Semester::query()->find((int) ($export->params_json['semester_id'] ?? 0));

        $temp = [];

        try {
            if ($semester === null) {
                throw new RuntimeException('Semester untuk rekap ini tidak ditemukan.');
            }

            $recap = NgawasRecap::build($semester, $month);
            $export->update(['progress' => 30]);

            $approvers = [];
            foreach (NgawasApprovers::all() as $approver) {
                $approvers[] = [
                    'position' => $approver['position'],
                    'name' => $approver['name'],
                    'signaturePath' => $mode === NgawasRecapWorkbook::FINAL ? NgawasSignatureFiles::materialize($approver['signature_file_id'], $temp) : null,
                ];
            }

            $signatures = [];
            foreach ($recap['assistants'] as $assistant) {
                $path = NgawasSignatureFiles::materialize($this->signatureIdOf($assistant['userId']), $temp);

                if ($path !== null) {
                    $signatures[$assistant['userId']] = $path;
                }
            }

            $export->update(['progress' => 50]);
            $built = (new NgawasRecapWorkbook)->build($recap, $mode, $approvers, $signatures);
            $temp[] = $built;

            $file = $files->storeGenerated($built, NgawasRecapWorkbook::filename($recap['monthLabel']), self::MIME, 'export', $export);
            $export->update(['status' => 'done', 'progress' => 100, 'file_id' => $file->id, 'expires_at' => now()->addDay()]);
        } catch (RuntimeException $e) {
            // Pesan sudah dalam Bahasa Indonesia dan menjelaskan penyebabnya (template, verifikasi angka, dll.).
            $export->update(['status' => 'failed', 'progress' => 100, 'error' => $e->getMessage()]);
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }

        ExportFinished::dispatch($export);
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update(['status' => 'failed', 'error' => 'Gagal membuat rekap Excel. Coba lagi atau hubungi Admin.']);
    }

    private function signatureIdOf(int $userId): ?int
    {
        return User::whereKey($userId)->value('signature_file_id');
    }
}
