<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Support\Files\FileStore;
use App\Support\Files\SignatureImage;
use App\Support\Reports\NgawasApprovers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin › Pengaturan Sistem › Pengesah Rekap Ngawas (US-RJN-05.3): dua pengesah
 * (jabatan, nama, gambar tanda tangan). Rekap Final memakai salinan sendiri
 * (snapshot), jadi pergantian pengesah tidak mengubah rekap lama.
 */
class NgawasApproverController extends Controller
{
    public function signature(int $index): BinaryFileResponse
    {
        $this->guard($index);
        $fileId = NgawasApprovers::all()[$index]['signature_file_id'];
        $file = $fileId ? StoredFile::find($fileId) : null;
        abort_unless($file !== null && $file->scan_status === 'clean', 404);

        return response()->file($file->absolutePath(), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function update(Request $request, int $index, FileStore $files, SignatureImage $images): RedirectResponse
    {
        $this->guard($index);

        $validated = $request->validate([
            'position' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'signature' => ['nullable', 'file'],
            'remove_signature' => ['boolean'],
        ], [], ['position' => 'jabatan', 'name' => 'nama', 'signature' => 'gambar tanda tangan']);

        $before = NgawasApprovers::all()[$index];
        $fileId = $before['signature_file_id'];
        $old = $fileId ? StoredFile::find($fileId) : null;
        $newId = $fileId;

        if ($request->hasFile('signature')) {
            $tmp = $images->fromUpload($request->file('signature'));

            try {
                $newId = $files->storeGenerated($tmp, 'tanda-tangan-pengesah.png', 'image/png', 'approver_signature', null)->id;
            } finally {
                @unlink($tmp);
            }
        } elseif ($request->boolean('remove_signature')) {
            $newId = null;
        }

        $after = ['position' => trim($validated['position']), 'name' => trim($validated['name']), 'signature_file_id' => $newId];
        NgawasApprovers::save($index, $after, $request->user()->id);

        if ($old !== null && $newId !== $old->id) {
            $old->delete();
        }

        AuditLog::record('ngawas.approver.updated', null, ['index' => $index, ...$before], ['index' => $index, ...$after]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengesah '.($index + 1).' disimpan.']);

        return back();
    }

    private function guard(int $index): void
    {
        abort_unless($index >= 0 && $index < NgawasApprovers::COUNT, 404);
    }
}
