<?php

namespace App\Http\Controllers\Asisten;

use App\Actions\NgawasRecap\FinalizeRecap;
use App\Actions\NgawasRecap\ReopenRecap;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateNgawasRecapWorkbook;
use App\Models\Export;
use App\Models\NgawasRecap;
use App\Models\NgawasRecapRevision;
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
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();

        $semester = $this->semesterFor($start);

        if ($semester === null) {
            return Inertia::render('asisten/ngawas-recap', [
                'filters' => ['month' => $month],
                'canManage' => $manager,
                'recap' => null, 'checks' => null, 'status' => null, 'final' => null, 'revisions' => [], 'signatures' => null, 'exports' => [],
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
            'final' => $state?->isFinal() ? $this->finalInfo($state, $manager, $manager ? $recap : null) : null,
            'revisions' => $manager && $state !== null ? $this->revisions($state) : [],
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
        $semester = $this->semesterFor(CarbonImmutable::createFromFormat('!Y-m', $validated['month'])->startOfMonth());

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

    /** FR-RJN-08: Tetapkan Final (snapshot + berkas Final permanen). */
    public function finalize(Request $request, FinalizeRecap $action): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap(), 403);

        $semester = $this->semesterForMonth($request);
        $recap = $action->handle($semester, $request->string('month')->toString(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rekap {$recap->month} ditetapkan Final."]);

        return back();
    }

    /** FR-RJN-09: Buka Kembali dengan catatan revisi. */
    public function reopen(Request $request, ReopenRecap $action): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap(), 403);

        $validated = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']], [
            'note.required' => 'Catatan revisi wajib diisi.', 'note.min' => 'Catatan revisi minimal 5 karakter.',
        ]);
        $semester = $this->semesterForMonth($request);
        $state = NgawasRecap::where('semester_id', $semester->id)->where('month', $request->string('month')->toString())->first();

        if ($state === null) {
            throw ValidationException::withMessages(['status' => 'Rekap ini belum Final.']);
        }

        $action->handle($state, $validated['note'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Rekap {$state->month} dibuka kembali sebagai revisi {$state->revision}."]);

        return back();
    }

    private function semesterForMonth(Request $request): Semester
    {
        $validated = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $semester = $this->semesterFor(CarbonImmutable::createFromFormat('!Y-m', $validated['month'])->startOfMonth());

        return $semester ?? throw ValidationException::withMessages(['month' => 'Bulan ini tidak termasuk semester mana pun.']);
    }

    /**
     * @param  array<string, mixed>|null  $live  rekap terkini (pengelola) untuk mendeteksi perubahan data sejak Final
     * @return array<string, mixed>
     */
    private function finalInfo(NgawasRecap $state, bool $manager, ?array $live): array
    {
        $state->loadMissing(['file', 'finalizer:id,name']);

        return [
            'finalizedAt' => $state->finalized_at?->toIso8601String(),
            'finalizedBy' => $state->finalizer->name ?? null,
            'file' => $manager && $state->file ? ['name' => $state->file->original_name, 'size' => $state->file->size, 'url' => $state->file->url()] : null,
            'changedSinceFinal' => $manager && $live !== null && md5((string) json_encode($live)) !== md5((string) json_encode($state->snapshot_json['recap'] ?? null)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revisions(NgawasRecap $state): array
    {
        $rows = $state->revisions()->with(['file', 'creator:id,name'])->get()->reverse()->map(fn (NgawasRecapRevision $r) => [
            'revision' => $r->revision, 'note' => $r->note, 'by' => $r->creator->name ?? null,
            'at' => $r->created_at->toIso8601String(),
            'file' => $r->file ? ['name' => $r->file->original_name, 'size' => $r->file->size, 'url' => $r->file->url()] : null,
        ])->all();

        return array_values($rows);
    }

    /** Semester yang mencakup bulan itu (utamakan yang aktif); null bila bulan di luar semua semester. */
    private function semesterFor(CarbonImmutable $start): ?Semester
    {
        return Semester::query()
            ->whereDate('start_date', '<=', $start->endOfMonth()->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderByRaw("status = 'active' desc")->orderByDesc('start_date')
            ->first();
    }
}
