<?php

namespace App\Actions\NgawasRecap;

use App\Events\NgawasRecapReopened;
use App\Models\AuditLog;
use App\Models\NgawasRecap;
use App\Models\NgawasRecapRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-RJN-09: Final → Draft dengan revisi + 1. Catatan wajib (≥ 5 karakter); berkas Final
 * lama tetap tersimpan sebagai riwayat revisi dan dapat diunduh.
 */
class ReopenRecap
{
    public function handle(NgawasRecap $recap, string $note, User $actor): NgawasRecap
    {
        $note = trim($note);

        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Catatan revisi minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($recap, $note, $actor) {
            $state = NgawasRecap::whereKey($recap->id)->lockForUpdate()->firstOrFail();

            if (! $state->isFinal()) {
                throw ValidationException::withMessages(['status' => 'Rekap ini belum Final.']);
            }

            $userIds = array_map('intval', array_column($state->snapshot_json['recap']['assistants'] ?? [], 'userId'));

            NgawasRecapRevision::create([
                'ngawas_recap_id' => $state->id, 'revision' => $state->revision,
                'file_id' => $state->file_id, 'note' => $note, 'created_by' => $actor->id,
            ]);

            $state->update([
                'status' => 'draft', 'revision' => $state->revision + 1,
                'reopened_note' => $note, 'file_id' => null,
            ]);

            AuditLog::record('ngawas_recap.reopened', $state, ['status' => 'final', 'revision' => $state->revision - 1], [
                'status' => 'draft', 'revision' => $state->revision, 'month' => $state->month, 'note' => $note,
            ]);

            NgawasRecapReopened::dispatch($state, $note, $userIds);

            return $state;
        });
    }
}
