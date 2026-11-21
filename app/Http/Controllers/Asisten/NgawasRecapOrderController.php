<?php

namespace App\Http\Controllers\Asisten;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NgawasRecapOrder;
use App\Models\Semester;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Pengaturan Urutan Rekap (FR-RJN-05): urutan asisten dan urutan baris kelas tiap asisten.
 * Berlaku per semester untuk semua bulan; rekap yang sudah Final memakai snapshot-nya sendiri.
 */
class NgawasRecapOrderController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap(), 403);

        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'assistants' => ['required', 'array', 'min:1', 'max:200'],
            'assistants.*.userId' => ['required', 'integer', 'distinct'],
            'assistants.*.classes' => ['present', 'array', 'max:60'],
            'assistants.*.classes.*' => ['string', 'regex:/^[a-z]\d+$/'],
        ]);

        $semester = $this->semester($data['month']);
        $known = User::whereIn('id', array_column($data['assistants'], 'userId'))->pluck('id')->all();

        if (count($known) !== count($data['assistants'])) {
            throw ValidationException::withMessages(['assistants' => 'Ada asisten yang tidak dikenal.']);
        }

        $before = NgawasRecapOrder::where('semester_id', $semester->id)->orderBy('position')->pluck('position', 'user_id')->all();

        DB::transaction(function () use ($data, $semester) {
            foreach (array_values($data['assistants']) as $position => $assistant) {
                $classes = array_values(array_unique($assistant['classes']));
                $existing = NgawasRecapOrder::where('semester_id', $semester->id)->where('user_id', $assistant['userId'])->first();
                // Kunci kelas dari bulan lain yang tidak tampil di dialog ini tetap dipertahankan (di belakang).
                $kept = array_values(array_diff($existing->class_order_json ?? [], $classes));

                NgawasRecapOrder::updateOrCreate(
                    ['semester_id' => $semester->id, 'user_id' => $assistant['userId']],
                    ['position' => $position, 'class_order_json' => [...$classes, ...$kept]],
                );
            }
        });

        AuditLog::record('ngawas_recap.order.updated', $semester, ['positions' => $before], ['assistants' => array_column($data['assistants'], 'userId')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Urutan rekap disimpan.']);

        return back();
    }

    /** Kembali ke urutan otomatis (nama asisten, lalu hari dan jam kelas). */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap(), 403);

        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $semester = $this->semester($data['month']);

        $count = NgawasRecapOrder::where('semester_id', $semester->id)->delete();
        AuditLog::record('ngawas_recap.order.reset', $semester, ['rows' => $count], null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Urutan rekap dikembalikan ke otomatis.']);

        return back();
    }

    private function semester(string $month): Semester
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        $semester = Semester::query()
            ->whereDate('start_date', '<=', $start->endOfMonth()->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderByRaw("status = 'active' desc")->orderByDesc('start_date')
            ->first() ?? Semester::current();

        return $semester ?? throw ValidationException::withMessages(['month' => 'Bulan ini tidak termasuk semester mana pun.']);
    }
}
