<?php

namespace App\Http\Controllers\Settings;

use App\Actions\User\SaveSignature;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tanda tangan asisten untuk rekap ngawas (US-RJN-02): diunggah atau digambar
 * oleh pemilik (peran Asisten), disimpan sebagai PNG transparan di disk privat.
 * Dapat dilihat pemilik, Sekretaris/Bendahara, dan Admin lewat signed URL.
 */
class SignatureController extends Controller
{
    public function show(Request $request, User $user): BinaryFileResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->id === $user->id || $viewer->canManageNgawasRecap(), 403);

        $file = $user->signature;
        abort_unless($file && $file->scan_status === 'clean', 404);

        return response()->file($file->absolutePath(), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function update(Request $request, SaveSignature $save): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === Role::Asisten, 403);

        $request->validate([
            'signature' => ['nullable', 'file', 'required_without:signature_data'],
            'signature_data' => ['nullable', 'string', 'required_without:signature', 'max:2000000'],
        ], ['signature.required_without' => 'Unggah gambar atau gambar tanda tangan Anda terlebih dahulu.']);

        $upload = $request->file('signature');
        $save->store($user, $upload instanceof UploadedFile ? $upload : $request->string('signature_data')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tanda tangan disimpan.']);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, SaveSignature $save): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Asisten, 403);

        $save->remove($request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tanda tangan dihapus.']);

        return to_route('profile.edit');
    }
}
