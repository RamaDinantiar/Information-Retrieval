<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\StoredFile;
use App\Support\Files\FileStore;
use App\Support\Reports\NgawasTemplate;
use App\Support\Reports\NgawasTemplateValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use ZipArchive;

/**
 * Admin › Pengaturan Sistem › Template Rekap Ngawas (FR-RJN-12): ganti template .xlsx kampus.
 * Berkas divalidasi struktur minimalnya; bila gagal, template yang sedang dipakai tidak berubah.
 */
class NgawasTemplateController extends Controller
{
    /** Batas ukuran unggahan (KB) dan total ukuran isi ZIP saat diekstrak (cegah zip bomb). */
    private const MAX_KB = 2048;

    private const MAX_UNCOMPRESSED = 40 * 1024 * 1024;

    public function store(Request $request, FileStore $files): RedirectResponse
    {
        $request->validate(['template' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KB]], [
            'template.required' => 'Pilih file template .xlsx.',
            'template.extensions' => 'Template harus berformat .xlsx.',
            'template.max' => 'Ukuran template maksimal 2 MB.',
        ], ['template' => 'template']);

        $upload = $request->file('template');
        $path = $upload->getRealPath();

        $problems = $this->guardArchive($path) ?? NgawasTemplateValidator::validate($path);

        if ($problems !== []) {
            throw ValidationException::withMessages(['template' => 'Template ditolak, template lama tetap dipakai. '.implode(' ', $problems)]);
        }

        $before = NgawasTemplate::uploadedId();
        $stored = $files->storeGenerated($path, $upload->getClientOriginalName(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ngawas_template', null);
        $stored->update(['uploaded_by' => $request->user()->id]);

        Setting::put([NgawasTemplate::KEY => $stored->id], $request->user()->id);
        $this->discard($before);

        AuditLog::record('ngawas.template.uploaded', $stored, ['file_id' => $before], ['file_id' => $stored->id, 'name' => $stored->original_name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template rekap diganti. Ekspor berikutnya memakai template ini.']);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $before = NgawasTemplate::uploadedId();

        if ($before !== null) {
            Setting::reset(NgawasTemplate::KEY);
            $this->discard($before);
            AuditLog::record('ngawas.template.restored', null, ['file_id' => $before], ['file_id' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template bawaan dipulihkan.']);

        return back();
    }

    /** Tolak arsip dengan terlalu banyak data setelah diekstrak sebelum PhpSpreadsheet memuatnya. */
    /**
     * @return list<string>|null
     */
    private function guardArchive(string $path): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return ['Berkas bukan .xlsx yang valid.'];
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        $zip->close();

        return $total > self::MAX_UNCOMPRESSED ? ['Isi berkas terlalu besar setelah diekstrak.'] : null;
    }

    private function discard(?int $fileId): void
    {
        $old = $fileId === null ? null : StoredFile::find($fileId);
        $old?->delete();
    }
}
