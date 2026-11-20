<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Reports\NgawasApprovers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin › Pengaturan Sistem (FR-ADM-10): durasi idle, batas ukuran file,
 * Kop laporan mengawas ada di template Word
 * (resources/templates/laporan-mengawas.docx).
 */
class SystemSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/system-settings', [
            'settings' => Arr::except(Setting::current(), [NgawasApprovers::KEY]),
            'approvers' => collect(NgawasApprovers::all())->map(fn (array $a, int $i) => [
                'index' => $i,
                'position' => $a['position'],
                'name' => $a['name'],
                'signatureUrl' => $a['signature_file_id'] ? route('admin.ngawas-approvers.signature', ['index' => $i, 'v' => $a['signature_file_id']]) : null,
            ])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'idle_minutes' => ['required', 'integer', 'between:5,240'],
            'limit_pdf_mb' => ['required', 'integer', 'between:1,50'],
            'limit_zip_mb' => ['required', 'integer', 'between:1,100'],
            'limit_image_mb' => ['required', 'integer', 'between:1,20'],
            'registration_open' => ['boolean'],
            'registration_domains' => ['nullable', 'string', 'max:255', 'regex:/^\s*@?[a-z0-9.-]+\.[a-z]{2,}(\s*,\s*@?[a-z0-9.-]+\.[a-z]{2,})*\s*$/i'],
        ], ['registration_domains.regex' => 'Format domain tidak valid, mis. itpln.ac.id (pisahkan dengan koma).'], [
            'idle_minutes' => 'durasi idle', 'limit_pdf_mb' => 'batas PDF', 'limit_zip_mb' => 'batas ZIP', 'limit_image_mb' => 'batas gambar',
            'registration_domains' => 'domain email',
        ]);
        $validated['registration_open'] = $request->has('registration_open') ? $request->boolean('registration_open') : (bool) Setting::value('registration_open');
        $validated['registration_domains'] = collect(explode(',', $validated['registration_domains'] ?? (string) Setting::value('registration_domains')))
            ->map(fn ($d) => strtolower(trim(ltrim(trim($d), '@'))))->filter()->unique()->join(', ');

        $before = Setting::current();
        Setting::put($validated, $request->user()->id);
        AuditLog::record('settings.updated', null, array_intersect_key($before, $validated), $validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengaturan sistem disimpan.']);

        return back();
    }
}
