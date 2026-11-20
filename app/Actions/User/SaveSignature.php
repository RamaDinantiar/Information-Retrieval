<?php

namespace App\Actions\User;

use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Files\SignatureImage;
use Illuminate\Http\UploadedFile;

/**
 * US-RJN-02: simpan, ganti, atau hapus tanda tangan asisten. Berkas lama
 * dihapus; rekap yang sudah Final memakai salinan sendiri (snapshot).
 * Setiap perubahan dicatat di AuditLog (tanpa isi gambar).
 */
class SaveSignature
{
    public function __construct(private FileStore $files, private SignatureImage $images) {}

    /**
     * @param  UploadedFile|string  $input  unggahan gambar atau dataURL kanvas
     */
    public function store(User $user, UploadedFile|string $input): void
    {
        $tmp = $input instanceof UploadedFile
            ? $this->images->fromUpload($input)
            : $this->images->fromDataUrl($input);

        try {
            $file = $this->files->storeGenerated($tmp, 'tanda-tangan.png', 'image/png', 'signature', $user);
        } finally {
            @unlink($tmp);
        }

        $old = $this->current($user);
        $before = ['signature_file_id' => $user->signature_file_id];
        $user->forceFill(['signature_file_id' => $file->id])->save();
        $user->unsetRelation('signature');
        $old?->delete();

        AuditLog::record($old ? 'signature.replaced' : 'signature.created', $user, $before, ['signature_file_id' => $file->id]);
    }

    public function remove(User $user): void
    {
        $old = $this->current($user);

        if ($old === null) {
            return;
        }

        $user->forceFill(['signature_file_id' => null])->save();
        $user->unsetRelation('signature');
        $old->delete();

        AuditLog::record('signature.deleted', $user, ['signature_file_id' => $old->id], ['signature_file_id' => null]);
    }

    /** Dibaca dari ID (bukan relasi) agar tidak terpengaruh relasi yang sudah di-cache pada instance user. */
    private function current(User $user): ?StoredFile
    {
        return $user->signature_file_id === null ? null : StoredFile::find($user->signature_file_id);
    }
}
