<?php

namespace App\Http\Controllers\Asisten;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateNgawasRecapWorkbook;
use App\Models\Export;
use App\Models\NgawasRecap;
use App\Models\Semester;
use App\Support\Reports\ExportPresenter;
use App\Support\Reports\NgawasRecap as RecapEngine;
use App\Support\Reports\NgawasRecapChecks;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asisten › Generate › Rekap Jam Ngawas (PRD Bagian 9, FR-RJN-05/07/13).
 * Asisten Umum hanya menerima baris miliknya; Sekretaris/Bendahara dan Admin
 * menerima seluruh asisten beserta pemeriksaan.
 */
class NgawasRecapController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $manager = $user->canManageNgawasRecap();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        $semester = $this->semesterFor($start);

        if ($semester === null) {
            return Inertia::render('asisten/ngawas-recap', [
                'filters' => ['month' => $month],
                'canManage' => $manager,
                'recap' => null, 'checks' => null, 'status' => null, 'signatures' => null, 'exports' => [],
            ]);
        }

        $recap = RecapEngine::build($semester, $month, $manager ? null : $user->id);
        $state = NgawasRecap::where('semester_id', $semester->id)->where('month', $month)->first();

        // Pemeriksaan memuat data asisten lain (laporan belum diverifikasi, dll.): khusus pengelola.
        $checks = $manager ? NgawasRecapChecks::run($semester, $month, $recap) : null;

        return Inertia::render('asisten/ngawas-recap', [
            'filters' => ['month' => $month],
            'canManage' => $manager,
            'recap' => $recap,
            'checks' => $checks,
            'status' => ['value' => $state->status->value ?? 'draft', 'revision' => $state->revision ?? 0],
            'signatures' => [
                'signed' => count(array_filter($recap['assistants'], fn (array $a) => $a['hasSignature'])),
                'total' => count($recap['assistants']),
            ],
            // Riwayat ekspor Excel milik pengelola (progres dipantau lewat polling di halaman).
            'exports' => $manager
                ? Export::where('user_id', $user->id)->where('type', 'ngawas')->with('file')->latest()->limit(5)->get()->map(fn (Export $e) => ExportPresenter::row($e))->values()
                : [],
        ]);
    }

    /** FR-RJN-11: ekspor Excel format kampus (Draft untuk pengecekan) lewat antrean. */
    public function export(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap(), 403);

        $validated = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $semester = $this->semesterFor(CarbonImmutable::createFromFormat('Y-m', $validated['month'])->startOfMonth());

        if ($semester === null) {
            throw ValidationException::withMessages(['month' => 'Bulan ini tidak termasuk semester mana pun.']);
        }

        $export = Export::create([
            'user_id' => $request->user()->id,
            'type' => 'ngawas',
            'params_json' => ['month' => $validated['month'], 'mode' => 'draft', 'semester_id' => $semester->id],
        ]);

        GenerateNgawasRecapWorkbook::dispatch($export);
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Rekap Excel (Draft) sedang diproses. Anda akan mendapat notifikasi saat selesai.']);

        return back();
    }

    /** Semester yang mencakup bulan itu (utamakan yang aktif); jika tidak ada, semester aktif saat ini. */
    private function semesterFor(CarbonImmutable $start): ?Semester
    {
        return Semester::query()
            ->whereDate('start_date', '<=', $start->endOfMonth()->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderByRaw("status = 'active' desc")->orderByDesc('start_date')
            ->first() ?? Semester::current();
    }
}
