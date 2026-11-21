<?php

namespace Tests\Feature\Asisten;

use App\Enums\Position;
use App\Models\LabClass;
use App\Models\NgawasRecap;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Audit 03 P1-8: batas bulan/semester pada Rekap Jam Ngawas dan penjaga jumlah query.
 */
class NgawasBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));

        $this->semester = Semester::factory()->active()->create(['name' => 'Genap 2025/2026', 'start_date' => '2026-02-16', 'end_date' => '2026-06-19']);
        $this->manager = User::factory()->asisten(Position::SekretarisBendahara)->create();
    }

    private function page(string $month)
    {
        return $this->actingAs($this->manager)->get(route('asisten.ngawas-recap.index', ['month' => $month]))->assertOk();
    }

    public function test_is_final_for_matches_the_whole_month_and_only_that_month(): void
    {
        NgawasRecap::create(['semester_id' => $this->semester->id, 'month' => '2026-04', 'status' => 'final']);
        $other = Semester::factory()->archived()->create();

        $this->assertTrue(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-04-01 00:00:00')));
        $this->assertTrue(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-04-30 23:59:59')));
        $this->assertFalse(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-03-31 23:59:59')));
        $this->assertFalse(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-05-01 00:00:00')));
        $this->assertFalse(NgawasRecap::isFinalFor($other->id, CarbonImmutable::parse('2026-04-15')), 'semester lain tidak ikut terkunci');
        $this->assertFalse(NgawasRecap::isFinalFor(null, CarbonImmutable::parse('2026-04-15')));
    }

    public function test_only_final_status_locks_the_month(): void
    {
        NgawasRecap::create(['semester_id' => $this->semester->id, 'month' => '2026-04', 'status' => 'draft']);

        $this->assertFalse(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-04-15')));

        NgawasRecap::where('month', '2026-04')->update(['status' => 'final']);
        $this->assertTrue(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-04-15')));

        NgawasRecap::where('month', '2026-04')->update(['status' => 'draft']);
        $this->assertFalse(NgawasRecap::isFinalFor($this->semester->id, CarbonImmutable::parse('2026-04-15')), 'buka kembali melepas kunci');
    }

    public function test_the_first_and_last_months_of_a_semester_have_a_recap_but_the_months_around_it_do_not(): void
    {
        // Semester 16 Feb – 19 Jun: Feb (mulai di tengah bulan) dan Jun (berakhir di tengah bulan) tetap termasuk.
        foreach (['2026-02', '2026-03', '2026-06'] as $month) {
            $this->page($month)->assertInertia(fn (Assert $page) => $page->where('filters.month', $month)->whereNot('recap', null));
        }

        foreach (['2026-01', '2026-07'] as $month) {
            $this->page($month)->assertInertia(fn (Assert $page) => $page->where('filters.month', $month)->where('recap', null));
        }
    }

    public function test_a_month_shared_by_two_semesters_prefers_the_active_one(): void
    {
        // Semester lama berakhir 20 Feb; Februari dimiliki keduanya (16–20 Feb). Yang aktif menang.
        Semester::factory()->archived()->create(['name' => 'Ganjil 2025/2026', 'start_date' => '2025-08-25', 'end_date' => '2026-02-20']);

        $this->page('2026-02')->assertInertia(fn (Assert $page) => $page->where('recap.semester.id', $this->semester->id));
    }

    public function test_the_month_validation_rejects_impossible_months(): void
    {
        $this->actingAs($this->manager);

        foreach (['2026-13', '2026-00', '2026-1', '26-04', '2026/04', '2026-04-01'] as $month) {
            $this->post(route('asisten.ngawas-recap.finalize'), ['month' => $month])->assertSessionHasErrors('month');
        }

        $this->assertSame(0, NgawasRecap::count());
    }

    public function test_the_recap_page_does_not_issue_more_queries_for_more_classes(): void
    {
        $queries = function (int $classes): int {
            $semester = Semester::factory()->active()->create(['start_date' => '2026-02-16', 'end_date' => '2026-06-19']);
            $assistants = User::factory()->asisten()->count(3)->create();

            for ($i = 0; $i < $classes; $i++) {
                $class = LabClass::factory()->create(['semester_id' => $semester->id, 'day_of_week' => 1 + $i % 5]);
                $class->assistants()->attach($assistants->pluck('id'));
                foreach (['2026-04-06', '2026-04-13'] as $date) {
                    $report = SupervisionReport::factory()->create([
                        'semester_id' => $semester->id, 'user_id' => $assistants[0]->id, 'date' => $date, 'status' => 'verified', 'class_id' => $class->id,
                    ]);
                    $report->assistants()->sync($assistants->mapWithKeys(fn (User $u, $n) => [$u->id => ['position' => $n + 1]])->all());
                }
            }

            DB::enableQueryLog();
            $this->actingAs($this->manager)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $count;
        };

        // Aktifkan satu semester pada satu waktu supaya halaman memilih semester ini (yang aktif menang).
        Semester::query()->update(['status' => 'archived']);
        $small = $queries(2);
        Semester::query()->update(['status' => 'archived']);
        $large = $queries(12);

        $this->assertLessThanOrEqual($small + 5, $large, "jumlah query tumbuh dari {$small} menjadi {$large} saat kelas 6× lebih banyak (kemungkinan N+1)");
    }
}
