<?php

namespace Tests\Feature\Asisten;

use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Reports\HoursRecap;
use App\Support\Reports\NgawasRecap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Audit 01 T1/R1/R2: bulan yang diminta tidak boleh bergeser ke bulan lain saat hari ini
 * tanggal 29–31, dan input bulan di luar 01–12 atau di luar semester ditolak dengan wajar.
 */
class MonthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::factory()->active()->create([
            'start_date' => '2026-01-05', 'end_date' => '2026-06-30', 'sk_number' => '01/SK/2026',
        ]);
    }

    public function test_recap_engine_uses_requested_month_even_on_day_31(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00'));

        $recap = NgawasRecap::build($this->semester, '2026-02');

        $this->assertSame('2026-02', $recap['month']);
        $this->assertSame(28, $recap['daysInMonth']);
        $this->assertSame('2026-02-01', $recap['columns'][0]['date']);
        $this->assertSame('2026-02-28', $recap['columns'][27]['date']);
    }

    public function test_hours_recap_uses_requested_month_even_on_day_31(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-31 10:00'));

        $user = User::factory()->asisten()->create();
        $report = SupervisionReport::factory()->create([
            'semester_id' => $this->semester->id, 'user_id' => $user->id, 'date' => '2026-06-15', 'status' => 'verified',
        ]);
        $report->assistants()->sync([$user->id => ['position' => 1]]);

        $rows = HoursRecap::forMonth('2026-06', null);

        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows[0]['userId']);
    }

    public function test_recap_page_shows_requested_month_on_day_31(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00'));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/asisten/ngawas-recap?month=2026-02')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.month', '2026-02')
                ->where('recap.month', '2026-02')
                ->where('recap.daysInMonth', 28));
    }

    public function test_report_and_generate_pages_reject_invalid_month_without_error(): void
    {
        $asisten = User::factory()->asisten()->create();

        foreach (['/asisten/report?month=2026-13', '/asisten/generate?month=2026-00'] as $url) {
            $this->actingAs($asisten)->get($url)->assertOk();
        }
    }

    public function test_finalize_rejects_month_outside_any_semester(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/asisten/ngawas-recap/finalize', ['month' => '2030-01'])
            ->assertSessionHasErrors('month');
    }
}
