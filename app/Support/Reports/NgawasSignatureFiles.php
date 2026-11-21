<?php

namespace App\Support\Reports;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Berkas tanda tangan untuk ekspor rekap: salin ke file sementara dan salin permanen (snapshot Final). */
class NgawasSignatureFiles
{
    /**
     * Salin berkas dari disk privat ke file sementara agar bisa dibaca PhpSpreadsheet.
     *
     * @param  list<string>  $temp  daftar file sementara yang dibersihkan pemanggil
     */
    public static function materialize(?int $fileId, array &$temp): ?string
    {
        $file = $fileId === null ? null : StoredFile::find($fileId);

        if ($file === null || ! Storage::disk($file->disk)->exists($file->path)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'sig').'.png';
        file_put_contents($path, Storage::disk($file->disk)->get($file->path));
        $temp[] = $path;

        return $path;
    }

    /** Salinan permanen (FR-RJN-08): tanda tangan yang diganti kemudian tidak memengaruhi rekap Final. */
    public static function snapshot(?int $fileId): ?int
    {
        $source = $fileId === null ? null : StoredFile::find($fileId);

        if ($source === null || ! Storage::disk($source->disk)->exists($source->path)) {
            return null;
        }

        $path = 'ngawas_signature/'.now()->format('Y/m').'/'.Str::uuid().'.'.pathinfo($source->path, PATHINFO_EXTENSION);
        Storage::disk($source->disk)->copy($source->path, $path);

        return StoredFile::create([
            // Tanpa pemilik: hanya Admin yang dapat membukanya, tidak pernah terlihat asisten lain.
            'owner_type' => null, 'owner_id' => null,
            'purpose' => 'ngawas_signature', 'disk' => $source->disk, 'path' => $path,
            'original_name' => $source->original_name, 'mime' => $source->mime, 'size' => $source->size,
            'sha256' => $source->sha256, 'uploaded_by' => null,
        ])->id;
    }
}
