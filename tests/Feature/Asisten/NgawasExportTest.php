<?php

namespace Tests\Feature\Asisten;

use App\Enums\Position;
use App\Events\ExportFinished;
use App\Jobs\GenerateNgawasRecapWorkbook;
use App\Models\Export;
use App\Models\Semester;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Reports\NgawasApprovers;
use App\Support\Reports\NgawasRecap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsNgawasRecaps;
use Tests\Concerns\SeedsNgawasGoldenFixture;
use Tests\TestCase;
use ZipArchive;

/** FR-RJN-11: ekspor Excel rekap ngawas lewat antrean (controller → job → berkas → notifikasi). */
class NgawasExportTest extends TestCase
{
    use BuildsNgawasRecaps;
    use RefreshDatabase;
    use SeedsNgawasGoldenFixture;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->seedFromFixture();
        $this->manager = User::factory()->asisten(Position::SekretarisBendahara)->create();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    private function request(string $month = '2026-04', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('asisten.ngawas-recap.export'), ['month' => $month]);
    }

    private function openExport(Export $export)
    {
        return IOFactory::load($export->file->absolutePath());
    }

    public function test_the_manager_gets_a_draft_workbook_and_a_notification_end_to_end()
    {
        $this->request()->assertSessionHasNoErrors();

        $export = Export::sole();
        $this->assertSame('ngawas', $export->type);
        $this->assertSame('done', $export->status);
        $this->assertSame(['month' => '2026-04', 'mode' => 'draft', 'semester_id' => $this->semester->id], $export->params_json);
        $this->assertSame('Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx', $export->file->original_name);
        $this->assertSame(GenerateNgawasRecapWorkbook::MIME, $export->file->mime);
        Storage::disk('lab')->assertExists($export->file->path);
        $this->assertTrue($export->expires_at->isFuture());

        $spreadsheet = $this->openExport($export);
        $this->assertSame('Format Aslab ', $spreadsheet->getSheet(0)->getTitle());
        // 60 baris data: 59 dari fixture + 1 baris "-" untuk Sekretaris/Bendahara (asisten aktif tanpa kelas); TOTAL di baris 76.
        $this->assertSame('-', $spreadsheet->getSheet(0)->getCell('D75')->getValue());
        $this->assertSame(495, (int) $spreadsheet->getSheet(0)->getCell('AJ76')->getCalculatedValue());
        $this->assertSame(247, (int) $spreadsheet->getSheet(0)->getCell('AI76')->getCalculatedValue());
        $this->assertStringContainsString('DRAFT', $spreadsheet->getSheet(0)->getHeaderFooter()->getOddHeader());
        $spreadsheet->disconnectWorksheets();

        $notification = $this->manager->notifications()->latest()->first()->data;
        $this->assertSame('Rekap Jam Ngawas selesai', $notification['title']);
        $this->assertStringContainsString('2026-04', $notification['body']);
        $this->assertSame('/asisten/ngawas-recap?month=2026-04', $notification['url']);
    }

    public function test_the_request_is_queued_with_the_semester_and_draft_mode()
    {
        Queue::fake();

        $this->request()->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateNgawasRecapWorkbook::class, fn (GenerateNgawasRecapWorkbook $job) => $job->export->params_json['month'] === '2026-04' && $job->export->params_json['mode'] === 'draft');
        $this->assertSame('queued', Export::sole()->status);
    }

    public function test_the_finished_event_is_dispatched()
    {
        Event::fake([ExportFinished::class]);

        $this->request();

        Event::assertDispatched(ExportFinished::class, fn (ExportFinished $e) => $e->export->type === 'ngawas' && $e->export->status === 'done');
    }

    public function test_only_the_secretary_treasurer_and_admin_can_export()
    {
        Queue::fake();

        $this->request('2026-04', $this->users[0])->assertForbidden();
        $this->request('2026-04', User::factory()->create())->assertForbidden();
        $this->assertSame(0, Export::count());

        $this->request('2026-04', User::factory()->admin()->create())->assertSessionHasNoErrors();
        $this->assertSame(1, Export::count());

        auth()->logout();
        $this->post(route('asisten.ngawas-recap.export'), ['month' => '2026-04'])->assertRedirect(route('login'));
    }

    public function test_the_month_is_validated()
    {
        Queue::fake();

        $this->request('april')->assertSessionHasErrors('month');
        $this->request('2026-13')->assertSessionHasErrors('month');
        $this->actingAs($this->manager)->post(route('asisten.ngawas-recap.export'), [])->assertSessionHasErrors('month');

        Semester::query()->update(['status' => 'archived']);
        $this->request('2030-01')->assertSessionHasErrors('month');

        $this->assertSame(0, Export::count());
    }

    public function test_a_failed_build_reports_the_reason_to_the_user()
    {
        // Bulan tanpa laporan dan tanpa asisten aktif → rekap kosong ditolak builder.
        User::where('role', 'asisten')->update(['is_active' => false]); // termasuk Sekretaris/Bendahara

        // Admin tidak ikut baris rekap (bukan asisten), jadi rekap bulan ini benar-benar kosong.
        $admin = User::factory()->admin()->create();
        $this->request('2026-03', $admin)->assertSessionHasNoErrors();

        $export = Export::sole();
        $this->assertSame('failed', $export->status);
        $this->assertSame('Tidak ada asisten pada rekap ini.', $export->error);
        $this->assertNull($export->file_id);

        $body = $admin->notifications()->latest()->first()->data['body'];
        $this->assertStringContainsString('Tidak ada asisten pada rekap ini.', $body);
    }

    public function test_a_final_workbook_carries_approver_names_signatures_and_assistant_signatures()
    {
        $files = app(FileStore::class);
        $png = $this->signaturePng();

        foreach ([0, 1] as $i) {
            $file = $files->storeGenerated($png, 'ttd.png', 'image/png', 'approver_signature', null);
            NgawasApprovers::save($i, ['position' => ['Dekan Fakultas', 'Kepala Lab'][$i], 'name' => ['Dr. Dekan', 'Dr. Kalab'][$i], 'signature_file_id' => $file->id], null);
        }

        $signature = $files->storeGenerated($png, 'ttd.png', 'image/png', 'signature', $this->users[0]);
        $this->users[0]->forceFill(['signature_file_id' => $signature->id])->save();

        $export = Export::create(['user_id' => $this->manager->id, 'type' => 'ngawas', 'params_json' => ['month' => '2026-04', 'mode' => 'final', 'semester_id' => $this->semester->id]]);
        (new GenerateNgawasRecapWorkbook($export))->handle($files);

        $export->refresh();
        $this->assertSame('done', $export->status, (string) $export->error);

        $spreadsheet = $this->openExport($export);
        $names = fn (int $sheet) => collect($spreadsheet->getSheet($sheet)->getDrawingCollection())->map(fn ($d) => $d->getName());

        $this->assertTrue($names(0)->contains('Tanda tangan pengesah 1'));
        $this->assertTrue($names(0)->contains('Tanda tangan pengesah 2'));
        $this->assertTrue($names(1)->contains('Tanda tangan pengesah 2'));
        $this->assertSame('', $spreadsheet->getSheet(0)->getHeaderFooter()->getOddHeader());

        // Hanya asisten 1 yang punya tanda tangan: jumlah gambar = jumlah sel hadirnya.
        $attended = array_sum(array_map(fn ($row) => count(array_filter($row['cells'], fn ($c) => $c['counts'])), NgawasRecap::build($this->semester, '2026-04', $this->users[0]->id)['assistants'][0]['rows']));
        $this->assertSame($attended, $names(1)->filter(fn ($n) => $n === 'Tanda tangan')->count());
        $spreadsheet->disconnectWorksheets();

        $zip = new ZipArchive;
        $zip->open($export->file->absolutePath());
        $drawing = (string) $zip->getFromName('xl/drawings/drawing1.xml');
        $zip->close();
        $this->assertStringContainsString('Dr. Dekan', $drawing);
        $this->assertStringContainsString('Dr. Kalab', $drawing);
        $this->assertStringContainsString('Kepala Lab', $drawing);
    }

    public function test_the_export_status_endpoint_serves_the_owner_only()
    {
        $this->request();
        $export = Export::sole();

        $this->actingAs($this->manager)->getJson(route('asisten.exports.show', $export))
            ->assertOk()->assertJsonPath('type', 'ngawas')->assertJsonPath('mode', 'draft')->assertJsonPath('status', 'done')
            ->assertJsonPath('file.name', 'Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx');

        $this->actingAs(User::factory()->asisten(Position::SekretarisBendahara)->create())->getJson(route('asisten.exports.show', $export))->assertForbidden();
    }

    public function test_the_recap_page_lists_recent_exports_for_the_manager_only_and_generate_hides_them()
    {
        $this->request();

        $this->actingAs($this->manager)->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page->has('exports', 1)->where('exports.0.status', 'done')->where('exports.0.mode', 'draft')->has('exports.0.file.url'));

        $this->actingAs($this->users[0])->get(route('asisten.ngawas-recap.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page->where('exports', []));

        $this->actingAs($this->manager)->get(route('asisten.generate.index', ['month' => '2026-04']))
            ->assertInertia(fn (Assert $page) => $page->has('exports', 0));
    }
}
