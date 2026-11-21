<?php

namespace App\Actions\NgawasRecap;

use App\Events\NgawasRecapFinalized;
use App\Jobs\GenerateNgawasRecapWorkbook;
use App\Models\AuditLog;
use App\Models\NgawasRecap;
use App\Models\Semester;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Reports\NgawasApprovers;
use App\Support\Reports\NgawasRecap as RecapEngine;
use App\Support\Reports\NgawasRecapChecks;
use App\Support\Reports\NgawasRecapWorkbook;
use App\Support\Reports\NgawasSignatureFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * FR-RJN-08: Draft → Final. Data rekap dan tanda tangan di-snapshot, file Excel Final
 * dibuat saat itu juga dan disimpan permanen (tidak ikut `exports:prune`).
 */
class FinalizeRecap
{
    public function __construct(private readonly FileStore $files) {}

    public function handle(Semester $semester, string $month, User $actor): NgawasRecap
    {
        $existing = NgawasRecap::where('semester_id', $semester->id)->where('month', $month)->first();

        if ($existing?->isFinal()) {
            throw ValidationException::withMessages(['status' => 'Rekap bulan ini sudah Final. Buka Kembali dulu bila perlu direvisi.']);
        }

        $recap = RecapEngine::build($semester, $month);
        $checks = NgawasRecapChecks::run($semester, $month, $recap);

        if ($checks['blocking']) {
            throw ValidationException::withMessages(['status' => 'Rekap belum bisa ditetapkan Final: selesaikan temuan berlevel Blokir terlebih dahulu.']);
        }

        $revision = $existing->revision ?? 0;
        $approvers = NgawasApprovers::all();
        $temp = [];

        try {
            $approverInputs = array_map(fn (array $a) => [
                'position' => $a['position'], 'name' => $a['name'],
                'signaturePath' => NgawasSignatureFiles::materialize($a['signature_file_id'], $temp),
            ], $approvers);

            $signatures = [];
            foreach ($recap['assistants'] as $assistant) {
                $path = NgawasSignatureFiles::materialize(User::whereKey($assistant['userId'])->value('signature_file_id'), $temp);

                if ($path !== null) {
                    $signatures[$assistant['userId']] = $path;
                }
            }

            try {
                $built = (new NgawasRecapWorkbook)->build($recap, NgawasRecapWorkbook::FINAL, $approverInputs, $signatures);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['status' => 'Berkas Final gagal dibuat: '.$e->getMessage()]);
            }

            $temp[] = $built;

            return DB::transaction(function () use ($semester, $month, $actor, $recap, $approvers, $revision, $built) {
                $state = NgawasRecap::where('semester_id', $semester->id)->where('month', $month)->lockForUpdate()->first()
                    ?? NgawasRecap::create(['semester_id' => $semester->id, 'month' => $month]);

                if ($state->isFinal()) {
                    throw ValidationException::withMessages(['status' => 'Rekap bulan ini sudah ditetapkan Final oleh pengguna lain.']);
                }

                $name = NgawasRecapWorkbook::filename($recap['monthLabel']);
                $name = $revision > 0 ? preg_replace('/\.xlsx$/', "_rev{$revision}.xlsx", $name) : $name;
                $file = $this->files->storeGenerated($built, (string) $name, GenerateNgawasRecapWorkbook::MIME, 'ngawas_recap', $state);

                $snapshot = [
                    'recap' => $recap,
                    'signatures' => collect($recap['assistants'])->mapWithKeys(fn (array $a) => [
                        $a['userId'] => NgawasSignatureFiles::snapshot(User::whereKey($a['userId'])->value('signature_file_id')),
                    ])->filter()->all(),
                    'approvers' => array_map(fn (array $a) => [
                        'position' => $a['position'], 'name' => $a['name'],
                        'signatureFileId' => NgawasSignatureFiles::snapshot($a['signature_file_id']),
                    ], $approvers),
                ];

                $state->update([
                    'status' => 'final', 'finalized_by' => $actor->id, 'finalized_at' => now(),
                    'snapshot_json' => $snapshot, 'file_id' => $file->id,
                ]);

                AuditLog::record('ngawas_recap.finalized', $state, ['status' => 'draft', 'revision' => $revision], [
                    'status' => 'final', 'revision' => $revision, 'month' => $month, 'total_hours' => $recap['totals']['hours'],
                ]);

                NgawasRecapFinalized::dispatch($state, array_column($recap['assistants'], 'userId'));

                return $state->refresh();
            });
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }
    }
}
