<?php

namespace Tests\Feature\Asisten;

use App\Jobs\GenerateMonthlyReport;
use App\Models\Export;
use App\Models\Semester;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 03 P2-2/P2-3: kegagalan job ekspor, akses ke ekspor orang lain, dan pemangkasan 7 hari.
 */
class ExportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function export(User $user, array $attributes = []): Export
    {
        return Export::create(['user_id' => $user->id, 'type' => 'zip', 'params_json' => ['month' => '2026-10', 'scope' => 'mine'], ...$attributes]);
    }

    public function test_a_failing_job_marks_the_export_failed_without_a_file_or_the_raw_exception(): void
    {
        $export = $this->export(User::factory()->asisten()->create());

        (new GenerateMonthlyReport($export))->failed(new Exception('SQLSTATE rahasia internal'));

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertNull($export->file_id);
        $this->assertStringNotContainsString('SQLSTATE', (string) $export->error);
        $this->assertSame('Gagal membuat rekap. Coba lagi atau hubungi Admin.', $export->error);
    }

    public function test_another_assistant_cannot_see_an_export_and_a_guest_is_sent_to_login(): void
    {
        $owner = User::factory()->asisten()->create();
        $export = $this->export($owner);

        $this->actingAs($owner)->getJson(route('asisten.exports.show', $export))->assertOk();
        $this->actingAs(User::factory()->asisten()->create())->getJson(route('asisten.exports.show', $export))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson(route('asisten.exports.show', $export))->assertForbidden();
    }

    public function test_pruning_removes_exports_older_than_seven_days_and_keeps_newer_ones(): void
    {
        $user = User::factory()->asisten()->create();
        $old = $this->export($user);
        $edge = $this->export($user);
        $recent = $this->export($user);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $edge->forceFill(['created_at' => now()->subDays(7)->addMinute()])->save();

        $this->artisan('exports:prune')->assertSuccessful();

        $this->assertNull(Export::find($old->id));
        $this->assertNotNull(Export::find($edge->id));
        $this->assertNotNull(Export::find($recent->id));
    }

    public function test_a_month_outside_any_semester_still_validates_but_yields_an_empty_export(): void
    {
        Semester::factory()->active()->create(['start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $export = $this->export(User::factory()->asisten()->create(), ['type' => 'excel', 'params_json' => ['month' => '2020-01', 'scope' => 'mine']]);

        app()->call([new GenerateMonthlyReport($export), 'handle']);

        $this->assertSame('empty', $export->fresh()->status);
    }
}
