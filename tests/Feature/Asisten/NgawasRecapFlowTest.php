<?php

namespace Tests\Feature\Asisten;

use App\Enums\Position;
use App\Events\NgawasRecapFinalized;
use App\Events\NgawasRecapReopened;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\NgawasRecap;
use App\Models\StoredFile;
use App\Models\SupervisionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\SeedsNgawasGoldenFixture;
use Tests\TestCase;

/** FR-RJN-08/09/15: draft → final → buka kembali → final revisi 1, beserta hak akses. */
class NgawasRecapFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsNgawasGoldenFixture;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->seedFromFixture();
        $this->semester->update(['sk_number' => 'SK/123/2026']);
        $this->manager = User::factory()->asisten(Position::SekretarisBendahara)->create();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    private function finalize(?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('asisten.ngawas-recap.finalize'), ['month' => '2026-04']);
    }

    private function reopen(string $note = 'Revisi Kak Naomi: SKS DW (B) tanggal 6', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('asisten.ngawas-recap.reopen'), ['month' => '2026-04', 'note' => $note]);
    }

    public function test_finalizing_snapshots_the_recap_and_stores_a_permanent_file()
    {
        Event::fake([NgawasRecapFinalized::class]);

        $this->finalize()->assertSessionHasNoErrors();

        $recap = NgawasRecap::sole();
        $this->assertTrue($recap->isFinal());
        $this->assertSame(0, $recap->revision);
        $this->assertSame($this->manager->id, $recap->finalized_by);
        $this->assertSame(495, $recap->snapshot_json['recap']['totals']['hours']);
        $this->assertSame('Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx', $recap->file->original_name);
        $this->assertSame('ngawas_recap', $recap->file->purpose);
        Storage::disk('lab')->assertExists($recap->file->path);

        $sheet = IOFactory::load($recap->file->absolutePath());
        $this->assertSame(495, (int) $sheet->getSheet(0)->getCell('AJ76')->getCalculatedValue());
        $this->assertStringNotContainsString('DRAFT', $sheet->getSheet(0)->getHeaderFooter()->getOddHeader());
        $sheet->disconnectWorksheets();

        Event::assertDispatched(NgawasRecapFinalized::class, fn ($e) => count($e->userIds) > 0);
        $audit = AuditLog::where('action', 'ngawas_recap.finalized')->sole();
        $this->assertSame($this->manager->id, $audit->user_id);
    }

    public function test_the_final_file_is_not_an_export_so_it_survives_pruning()
    {
        $this->finalize();

        $this->assertSame(0, Export::count());
        $this->artisan('exports:prune')->assertSuccessful();
        Storage::disk('lab')->assertExists(NgawasRecap::sole()->file->path);
    }

    public function test_blocking_checks_prevent_finalizing()
    {
        $this->semester->update(['sk_number' => null]);

        $this->finalize()->assertSessionHasErrors('status');

        $this->assertSame(0, NgawasRecap::count());
        $this->assertSame(0, StoredFile::where('purpose', 'ngawas_recap')->count());
    }

    public function test_finalizing_twice_is_rejected()
    {
        $this->finalize()->assertSessionHasNoErrors();
        $this->finalize()->assertSessionHasErrors('status');

        $this->assertSame(1, StoredFile::where('purpose', 'ngawas_recap')->count());
    }

    public function test_reopening_requires_a_note_and_keeps_the_old_file_as_a_revision()
    {
        $this->finalize();
        $first = NgawasRecap::sole()->file;

        $this->reopen('abc')->assertSessionHasErrors('note');
        $this->assertTrue(NgawasRecap::sole()->isFinal());

        Event::fake([NgawasRecapReopened::class]);
        $this->reopen()->assertSessionHasNoErrors();

        $recap = NgawasRecap::sole();
        $this->assertFalse($recap->isFinal());
        $this->assertSame(1, $recap->revision);
        $this->assertNull($recap->file_id);

        $revision = $recap->revisions()->sole();
        $this->assertSame(0, $revision->revision);
        $this->assertSame($first->id, $revision->file_id);
        $this->assertSame('Revisi Kak Naomi: SKS DW (B) tanggal 6', $revision->note);
        Storage::disk('lab')->assertExists($first->path);

        Event::assertDispatched(NgawasRecapReopened::class, fn ($e) => $e->note === 'Revisi Kak Naomi: SKS DW (B) tanggal 6' && count($e->userIds) > 0);
        $this->assertSame(1, AuditLog::where('action', 'ngawas_recap.reopened')->count());
    }

    public function test_the_full_cycle_ends_in_final_revision_one_with_both_files_kept()
    {
        $this->finalize();
        $this->reopen();
        $this->finalize()->assertSessionHasNoErrors();

        $recap = NgawasRecap::sole();
        $this->assertTrue($recap->isFinal());
        $this->assertSame(1, $recap->revision);
        $this->assertSame('Rekap_Ngawas_LAB_IR_APRIL_2026_rev1.xlsx', $recap->file->original_name);
        $this->assertSame(1, $recap->revisions()->count());
        $this->assertSame(2, StoredFile::where('purpose', 'ngawas_recap')->count());
    }

    public function test_reopening_a_draft_recap_is_rejected()
    {
        $this->reopen()->assertSessionHasErrors('status');
    }

    public function test_signatures_are_snapshotted_so_later_changes_do_not_affect_the_final_recap()
    {
        $user = User::whereKey($this->manager->id)->first();
        Storage::disk('lab')->put('signature/x.png', file_get_contents(base_path('tests/Fixtures/../../public/favicon.ico')) ?: 'x');
        $original = StoredFile::create(['owner_type' => null, 'owner_id' => null, 'purpose' => 'signature', 'disk' => 'lab', 'path' => 'signature/x.png', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => 'a', 'uploaded_by' => $user->id]);
        $assistantId = SupervisionReport::where('status', 'verified')->value('user_id');
        User::whereKey($assistantId)->update(['signature_file_id' => $original->id]);

        $this->finalize()->assertSessionHasNoErrors();

        $copyId = NgawasRecap::sole()->snapshot_json['signatures'][$assistantId] ?? null;
        $this->assertNotNull($copyId);
        $this->assertNotSame($original->id, $copyId);
        $this->assertSame('ngawas_signature', StoredFile::find($copyId)->purpose);

        $original->delete();
        Storage::disk('lab')->assertExists(StoredFile::find($copyId)->path);
    }

    public function test_only_the_managers_may_finalize_or_reopen()
    {
        $regular = User::factory()->asisten()->create();

        $this->finalize($regular)->assertForbidden();
        $this->finalize();
        $this->reopen('Catatan cukup panjang', $regular)->assertForbidden();
        $this->assertTrue(NgawasRecap::sole()->isFinal());

        $this->finalize(User::factory()->create())->assertForbidden();
    }

    public function test_admin_may_finalize()
    {
        $this->finalize(User::factory()->admin()->create())->assertSessionHasNoErrors();
        $this->assertTrue(NgawasRecap::sole()->isFinal());
    }

    public function test_notifications_reach_assistants_on_final_and_reopen()
    {
        $this->finalize();
        $assistant = User::whereKey(NgawasRecap::sole()->snapshot_json['recap']['assistants'][0]['userId'])->first();
        $this->assertSame('Rekap Jam Ngawas Final', $assistant->notifications()->first()->data['title']);

        $this->reopen();
        $data = $assistant->notifications->first(fn ($n) => $n->data['title'] === 'Rekap Jam Ngawas direvisi')?->data;
        $this->assertNotNull($data);
        $this->assertStringContainsString('Revisi Kak Naomi', $data['body']);
        $this->assertStringContainsString('month=2026-04', $data['url']);
    }

    public function test_the_page_exposes_status_files_and_revision_history_to_the_manager_only()
    {
        $this->finalize();
        $this->reopen();
        $this->finalize();

        $this->actingAs($this->manager)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', ['value' => 'final', 'revision' => 1])
                ->where('final.changedSinceFinal', false)
                ->where('final.file.name', 'Rekap_Ngawas_LAB_IR_APRIL_2026_rev1.xlsx')
                ->has('revisions', 1, fn (Assert $r) => $r->where('revision', 0)->where('by', $this->manager->name)->has('file.url')->etc()));
    }

    public function test_an_assistant_sees_the_final_status_but_cannot_download_the_file_or_history()
    {
        $this->finalize();
        $old = NgawasRecap::sole()->file;
        $this->reopen();
        $this->finalize();
        $assistant = User::whereKey(NgawasRecap::sole()->snapshot_json['recap']['assistants'][0]['userId'])->first();

        $this->actingAs($assistant)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', ['value' => 'final', 'revision' => 1])
                ->where('final.file', null)
                ->where('final.changedSinceFinal', false)
                ->where('revisions', []));

        // File Final (aktif maupun revisi lama) memuat data seluruh asisten: hanya pengelola yang boleh mengunduh.
        $this->get(NgawasRecap::sole()->file->url())->assertForbidden();
        $this->get($old->url())->assertForbidden();
        $this->actingAs($this->manager)->get(NgawasRecap::sole()->file->url())->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get($old->url())->assertOk();
    }

    public function test_an_assistant_page_exposes_only_their_own_reports_and_hours()
    {
        $this->finalize();
        $recap = NgawasRecap::sole()->snapshot_json['recap'];
        $mine = User::whereKey($recap['assistants'][0]['userId'])->first();
        $others = array_map(fn ($a) => $a['name'], array_slice($recap['assistants'], 1));

        $this->actingAs($mine)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(function (Assert $page) use ($mine, $others) {
                $props = $page->toArray()['props'];
                $this->assertCount(1, $props['recap']['assistants']);
                $this->assertNull($props['checks']);

                $reports = (array) $props['recap']['reports'];
                $this->assertNotEmpty($reports);
                $this->assertLessThan(count(NgawasRecap::sole()->snapshot_json['recap']['reports']), count($reports));

                // Nama asisten lain hanya boleh muncul bila satu tim jaga dengan pemilik halaman.
                foreach ($reports as $report) {
                    $this->assertContains($mine->name, $report['team']);
                }

                $this->assertStringNotContainsString('"checks":{', json_encode($props));
                $this->assertGreaterThan(0, count($others));
            });
    }

    public function test_data_changes_after_final_are_flagged()
    {
        $this->finalize();
        $this->semester->update(['name' => $this->semester->name]);
        SupervisionReport::query()->first()->update(['status' => 'draft']);

        $this->actingAs($this->manager)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page->where('final.changedSinceFinal', true));
    }
}
