<?php

namespace Tests\Feature\Admin;

use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Reports\NgawasTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** FR-RJN-12: Admin mengganti template; yang tidak valid ditolak dan template lama tetap dipakai. */
class NgawasTemplateUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->admin = User::factory()->admin()->create();
    }

    private function valid(string $name = 'template-baru.xlsx'): UploadedFile
    {
        return new UploadedFile(NgawasTemplate::path(), $name, null, null, true);
    }

    private function upload(UploadedFile $file, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('admin.ngawas-template.store'), ['template' => $file]);
    }

    public function test_the_bundled_template_is_active_by_default()
    {
        $this->assertSame(NgawasTemplate::path(), NgawasTemplate::activePath());

        $this->actingAs($this->admin)->get(route('admin.system-settings.index'))
            ->assertInertia(fn (Assert $p) => $p->where('template.custom', false)->where('template.name', 'Template bawaan (Mei 2026)'));
    }

    public function test_a_valid_template_replaces_the_active_one()
    {
        $this->upload($this->valid())->assertSessionHasNoErrors();

        $file = StoredFile::where('purpose', 'ngawas_template')->sole();
        $this->assertSame($file->absolutePath(), NgawasTemplate::activePath());
        $this->assertSame('template-baru.xlsx', $file->original_name);
        $this->assertSame($this->admin->id, $file->uploaded_by);
        $this->assertSame(1, AuditLog::where('action', 'ngawas.template.uploaded')->count());

        $this->actingAs($this->admin)->get(route('admin.system-settings.index'))
            ->assertInertia(fn (Assert $p) => $p->where('template.custom', true)->where('template.name', 'template-baru.xlsx'));
    }

    public function test_replacing_again_removes_the_previous_upload()
    {
        $this->upload($this->valid('a.xlsx'));
        $first = StoredFile::where('purpose', 'ngawas_template')->sole();

        $this->upload($this->valid('b.xlsx'))->assertSessionHasNoErrors();

        $this->assertSame(1, StoredFile::where('purpose', 'ngawas_template')->count());
        $this->assertNull(StoredFile::find($first->id));
        Storage::disk('lab')->assertMissing($first->path);
    }

    public function test_an_invalid_structure_is_rejected_and_the_old_template_stays()
    {
        $this->upload($this->valid('lama.xlsx'));
        $active = NgawasTemplate::activePath();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new Xlsx(new Spreadsheet))->save($path);

        $this->upload(new UploadedFile($path, 'salah.xlsx', null, null, true))->assertSessionHasErrors('template');

        $this->assertSame($active, NgawasTemplate::activePath());
        $this->assertSame(1, StoredFile::where('purpose', 'ngawas_template')->count());
        $this->assertStringContainsString('template lama tetap dipakai', session('errors')->first('template'));
        @unlink($path);
    }

    public function test_non_xlsx_and_oversized_files_are_rejected()
    {
        $this->upload(UploadedFile::fake()->createWithContent('catatan.xlsx', 'bukan zip'))->assertSessionHasErrors('template');
        $this->upload(UploadedFile::fake()->create('template.pdf', 10, 'application/pdf'))->assertSessionHasErrors('template');
        $this->upload(UploadedFile::fake()->create('besar.xlsx', 3000))->assertSessionHasErrors('template');

        $this->assertSame(0, StoredFile::where('purpose', 'ngawas_template')->count());
    }

    public function test_restoring_goes_back_to_the_bundled_template()
    {
        $this->upload($this->valid());

        $this->actingAs($this->admin)->delete(route('admin.ngawas-template.destroy'))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(NgawasTemplate::path(), NgawasTemplate::activePath());
        $this->assertSame(0, StoredFile::where('purpose', 'ngawas_template')->count());
        $this->assertSame(1, AuditLog::where('action', 'ngawas.template.restored')->count());
    }

    public function test_a_missing_uploaded_file_falls_back_to_the_bundled_template()
    {
        $this->upload($this->valid());
        Storage::disk('lab')->delete(StoredFile::where('purpose', 'ngawas_template')->sole()->path);

        $this->assertSame(NgawasTemplate::path(), NgawasTemplate::activePath());
    }

    public function test_only_admin_may_change_the_template()
    {
        $manager = User::factory()->asisten(Position::SekretarisBendahara)->create();

        $this->upload($this->valid(), $manager)->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.ngawas-template.destroy'))->assertForbidden();
        $this->post(route('admin.ngawas-template.store'), [])->assertForbidden();
    }
}
