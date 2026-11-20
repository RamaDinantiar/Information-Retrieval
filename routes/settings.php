<?php

use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SignatureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('settings/avatar', [AvatarController::class, 'update'])->middleware('throttle:10,1')->name('avatar.update');
    Route::delete('settings/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');
    Route::get('avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show');

    // US-RJN-02: tanda tangan asisten untuk rekap ngawas.
    Route::post('settings/signature', [SignatureController::class, 'update'])->middleware('throttle:10,1')->name('signature.update');
    Route::delete('settings/signature', [SignatureController::class, 'destroy'])->name('signature.destroy');
    Route::get('signatures/{user}', [SignatureController::class, 'show'])->middleware('signed')->name('signatures.show');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
