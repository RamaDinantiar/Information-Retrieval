<?php

namespace App\Http\Controllers\Asisten;

use App\Http\Controllers\Controller;
use App\Models\NgawasRecap;
use App\Models\Semester;
use App\Support\Reports\NgawasRecap as RecapEngine;
use App\Support\Reports\NgawasRecapChecks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
                'recap' => null, 'checks' => null, 'status' => null, 'signatures' => null,
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
        ]);
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
