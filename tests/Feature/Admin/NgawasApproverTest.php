<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Reports\NgawasApprovers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** US-RJN-05.3: dua pengesah rekap ngawas (jabatan, nama, tanda tangan; tanpa NIP). */
class NgawasApproverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->actingAs(User::factory()->admin()->create());
    }

    private function signature(string $name = 'ttd.png'): UploadedFile
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 40, 30, 150, 60, (int) imagecolorallocate($image, 0, 0, 0));
        $path = tempnam(sys_get_temp_dir(), 'appr');
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function payload(array $overrides = []): array
    {
        return ['position' => 'Kepala Laboratorium', 'name' => 'Dr. Dine Anggraini', ...$overrides];
    }

    public function test_the_page_lists_two_approvers_with_default_positions()
    {
        $this->get(route('admin.system-settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('approvers', 2)
            ->where('approvers.0.position', 'Dekan Fakultas Telematika Energi IT PLN')
            ->where('approvers.1.position', 'Kepala Laboratorium Information Retrieval IT PLN')
            ->where('approvers.0.name', '')
            ->where('approvers.0.signatureUrl', null)
            ->missing('settings.ngawas_recap.approvers'));
    }

    public function test_admin_saves_position_and_name_without_nip_and_it_is_audited()
    {
        $this->put(route('admin.ngawas-approvers.update', 0), $this->payload())->assertSessionHasNoErrors();
        $this->put(route('admin.ngawas-approvers.update', 1), ['position' => 'Dekan', 'name' => 'Prof. Budi Santoso'])->assertSessionHasNoErrors();

        $approvers = NgawasApprovers::all();
        $this->assertSame('Dr. Dine Anggraini', $approvers[0]['name']);
        $this->assertSame('Prof. Budi Santoso', $approvers[1]['name']);
        $this->assertArrayNotHasKey('nip', $approvers[0]);
        $this->assertSame(2, AuditLog::where('action', 'ngawas.approver.updated')->count());
        $this->assertSame('Dr. Dine Anggraini', AuditLog::where('action', 'ngawas.approver.updated')->first()->after_json['name']);
    }

    public function test_uploading_replacing_and_removing_a_signature_manages_the_stored_file()
    {
        $this->put(route('admin.ngawas-approvers.update', 0), [...$this->payload(), 'signature' => $this->signature()])->assertSessionHasNoErrors();

        $first = StoredFile::findOrFail(NgawasApprovers::all()[0]['signature_file_id']);
        $this->assertSame('approver_signature', $first->purpose);
        $this->assertNull($first->owner_id);
        Storage::disk('lab')->assertExists($first->path);

        $this->put(route('admin.ngawas-approvers.update', 0), [...$this->payload(), 'signature' => $this->signature()]);
        $second = NgawasApprovers::all()[0]['signature_file_id'];
        $this->assertNotSame($first->id, $second);
        Storage::disk('lab')->assertMissing($first->path);

        // Menyimpan nama saja tidak menghapus tanda tangan.
        $this->put(route('admin.ngawas-approvers.update', 0), $this->payload(['name' => 'Nama Baru']));
        $this->assertSame($second, NgawasApprovers::all()[0]['signature_file_id']);

        $this->put(route('admin.ngawas-approvers.update', 0), [...$this->payload(), 'remove_signature' => true]);
        $this->assertNull(NgawasApprovers::all()[0]['signature_file_id']);
        $this->assertNull(StoredFile::find($second));
    }

    public function test_the_signature_image_is_served_to_admin_only()
    {
        $this->put(route('admin.ngawas-approvers.update', 1), [...$this->payload(['position' => 'Dekan']), 'signature' => $this->signature()]);

        $this->get(route('admin.ngawas-approvers.signature', 1))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('admin.ngawas-approvers.signature', 0))->assertNotFound(); // belum ada
        $this->get('/admin/ngawas-approvers/2/signature')->assertNotFound();

        $this->actingAs(User::factory()->asisten()->create())->get(route('admin.ngawas-approvers.signature', 1))->assertForbidden();
    }

    public function test_the_page_exposes_the_signature_url_after_upload()
    {
        $this->put(route('admin.ngawas-approvers.update', 0), [...$this->payload(), 'signature' => $this->signature()]);

        $this->get(route('admin.system-settings.index'))->assertInertia(fn (Assert $page) => $page->has('approvers.0.signatureUrl'));
    }

    public function test_validation_and_access_control()
    {
        $this->put(route('admin.ngawas-approvers.update', 0), $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->put(route('admin.ngawas-approvers.update', 0), $this->payload(['position' => '']))->assertSessionHasErrors('position');
        $this->put(route('admin.ngawas-approvers.update', 0), [...$this->payload(), 'signature' => UploadedFile::fake()->createWithContent('x.png', 'bukan gambar')])->assertSessionHasErrors('signature');
        $this->put('/admin/ngawas-approvers/2', $this->payload())->assertNotFound();

        foreach ([User::factory()->asisten()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->put(route('admin.ngawas-approvers.update', 0), $this->payload())->assertForbidden();
        }
    }

    public function test_saving_system_settings_does_not_wipe_the_approvers()
    {
        $this->put(route('admin.ngawas-approvers.update', 0), $this->payload());

        $this->put(route('admin.system-settings.update'), [
            'idle_minutes' => 30, 'limit_pdf_mb' => 10, 'limit_zip_mb' => 20, 'limit_image_mb' => 5,
            'registration_open' => true, 'registration_domains' => 'itpln.ac.id',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Dr. Dine Anggraini', NgawasApprovers::all()[0]['name']);
    }
}
