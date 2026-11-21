<?php

namespace Tests\Feature\Settings;

use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Files\SignatureImage;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** US-RJN-02: tanda tangan asisten (unggah, kanvas, pemrosesan gambar, hak akses). */
class SignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
    }

    private function canvas(int $w, int $h, bool $transparent = false): GdImage
    {
        $image = imagecreatetruecolor($w, $h);

        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        }

        return $image;
    }

    private function stroke(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $r = 0, int $g = 0, int $b = 0): void
    {
        imagealphablending($image, true);
        imagefilledrectangle($image, $x1, $y1, $x2, $y2, (int) imagecolorallocate($image, $r, $g, $b));
    }

    private function encode(GdImage $image, string $type): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sigtest');
        $type === 'jpg' ? imagejpeg($image, $path, 95) : imagepng($image, $path);

        return $path;
    }

    private function upload(GdImage $image, string $type = 'png', string $name = 'ttd'): UploadedFile
    {
        return new UploadedFile($this->encode($image, $type), "{$name}.{$type}", $type === 'jpg' ? 'image/jpeg' : 'image/png', null, true);
    }

    private function dataUrl(GdImage $image): string
    {
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->encode($image, 'png')));
    }

    private function load(string $path): GdImage
    {
        $image = imagecreatefrompng($path);
        $this->assertInstanceOf(GdImage::class, $image);
        imagealphablending($image, false);

        return $image;
    }

    private function alphaAt(GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    // --- Pemrosesan gambar -------------------------------------------------

    public function test_a_white_background_jpg_becomes_a_trimmed_transparent_png()
    {
        $image = $this->canvas(300, 150);
        $this->stroke($image, 100, 60, 160, 80);

        $png = $this->load((new SignatureImage)->fromUpload($this->upload($image, 'jpg')));

        $this->assertEqualsWithDelta(69, imagesx($png), 6); // 61 px goresan + 2 × 4 px bantalan
        $this->assertEqualsWithDelta(29, imagesy($png), 6);
        $this->assertSame(127, $this->alphaAt($png, 0, 0)); // sudut transparan
        $this->assertSame(0, $this->alphaAt($png, (int) (imagesx($png) / 2), (int) (imagesy($png) / 2))); // tengah = tinta pekat
    }

    public function test_a_transparent_png_keeps_its_ink_colour()
    {
        $image = $this->canvas(200, 100, true);
        $this->stroke($image, 50, 40, 120, 60, 0, 0, 200);

        $png = $this->load((new SignatureImage)->fromUpload($this->upload($image)));
        $centre = imagecolorat($png, (int) (imagesx($png) / 2), (int) (imagesy($png) / 2));

        $this->assertSame(200, $centre & 0xFF);
        $this->assertSame(0, ($centre >> 16) & 0xFF);
        $this->assertEqualsWithDelta(79, imagesx($png), 4);
    }

    public function test_wide_signatures_are_scaled_down_to_600px()
    {
        $image = $this->canvas(1500, 400);
        $this->stroke($image, 10, 100, 1490, 300);

        $png = $this->load((new SignatureImage)->fromUpload($this->upload($image)));

        $this->assertSame(600, imagesx($png));
    }

    public function test_small_signatures_are_not_upscaled()
    {
        $image = $this->canvas(100, 60);
        $this->stroke($image, 20, 20, 60, 40);

        $this->assertLessThan(100, imagesx($this->load((new SignatureImage)->fromUpload($this->upload($image)))));
    }

    public function test_a_blank_image_is_rejected()
    {
        $this->expectException(ValidationException::class);

        (new SignatureImage)->fromUpload($this->upload($this->canvas(200, 100)));
    }

    public function test_canvas_data_urls_are_processed_and_garbage_is_rejected()
    {
        $image = $this->canvas(400, 200, true);
        $this->stroke($image, 100, 80, 300, 120);

        $png = $this->load((new SignatureImage)->fromDataUrl($this->dataUrl($image)));
        $this->assertEqualsWithDelta(209, imagesx($png), 4);

        foreach (['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', 'data:image/png;base64,!!!', 'bukan-data-url'] as $bad) {
            try {
                (new SignatureImage)->fromDataUrl($bad);
                $this->fail('Seharusnya ditolak: '.$bad);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('signature_data', $e->errors());
            }
        }
    }

    public function test_non_images_and_oversized_files_are_rejected()
    {
        $fake = UploadedFile::fake()->createWithContent('ttd.png', 'ini bukan gambar');
        $big = UploadedFile::fake()->image('ttd.png', 100, 100)->size(1500);

        foreach ([$fake, $big] as $file) {
            try {
                (new SignatureImage)->fromUpload($file);
                $this->fail('Seharusnya ditolak');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('signature', $e->errors());
            }
        }
    }

    // --- Alur HTTP ---------------------------------------------------------

    private function drawn(): GdImage
    {
        $image = $this->canvas(300, 150);
        $this->stroke($image, 80, 50, 220, 90);

        return $image;
    }

    public function test_an_assistant_uploads_replaces_and_removes_their_signature()
    {
        $user = User::factory()->asisten()->create();
        $this->actingAs($user);

        $this->post(route('signature.update'), ['signature' => $this->upload($this->drawn(), 'jpg')])->assertSessionHasNoErrors();

        $first = $user->fresh()->signature;
        $this->assertSame('signature', $first->purpose);
        $this->assertSame('image/png', $first->mime);
        $this->assertSame($user->id, $first->owner_id);
        Storage::disk('lab')->assertExists($first->path);
        $this->assertTrue(AuditLog::where('action', 'signature.created')->where('auditable_id', $user->id)->exists());

        $this->post(route('signature.update'), ['signature_data' => $this->dataUrl($this->drawn())])->assertSessionHasNoErrors();

        $second = $user->fresh()->signature;
        $this->assertNotSame($first->id, $second->id);
        Storage::disk('lab')->assertMissing($first->path);
        $this->assertNull(StoredFile::find($first->id));
        $this->assertTrue(AuditLog::where('action', 'signature.replaced')->exists());

        $this->delete(route('signature.destroy'))->assertRedirect();

        $this->assertNull($user->fresh()->signature_file_id);
        Storage::disk('lab')->assertMissing($second->path);
        $this->assertTrue(AuditLog::where('action', 'signature.deleted')->exists());
    }

    public function test_the_secretary_treasurer_can_also_sign_as_an_assistant()
    {
        $user = User::factory()->asisten(Position::SekretarisBendahara)->create();

        $this->actingAs($user)->post(route('signature.update'), ['signature' => $this->upload($this->drawn())])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->signature_file_id);
    }

    public function test_something_is_required_and_bad_input_is_reported_on_the_right_field()
    {
        $this->actingAs(User::factory()->asisten()->create());

        $this->post(route('signature.update'), [])->assertSessionHasErrors('signature');
        $this->post(route('signature.update'), ['signature_data' => 'data:image/png;base64,AAAA'])->assertSessionHasErrors('signature_data');
        $this->post(route('signature.update'), ['signature' => UploadedFile::fake()->createWithContent('x.png', 'bukan gambar')])->assertSessionHasErrors('signature');
    }

    public function test_only_assistants_manage_signatures()
    {
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('signature.update'), ['signature' => $this->upload($this->drawn())])->assertForbidden();
            $this->delete(route('signature.destroy'))->assertForbidden();
        }
    }

    public function test_signed_urls_serve_the_image_to_the_owner_managers_and_admin_only()
    {
        $owner = User::factory()->asisten()->create();
        $this->actingAs($owner)->post(route('signature.update'), ['signature' => $this->upload($this->drawn())]);
        $url = fn () => URL::temporarySignedRoute('signatures.show', now()->addMinutes(15), ['user' => $owner->id]);

        $this->actingAs($owner)->get($url())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->asisten(Position::SekretarisBendahara)->create())->get($url())->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get($url())->assertOk();

        $this->actingAs(User::factory()->asisten()->create())->get($url())->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url())->assertForbidden();
        $this->actingAs($owner)->get(route('signatures.show', $owner))->assertForbidden(); // tanpa signature
    }

    public function test_a_user_without_a_signature_gets_404()
    {
        $owner = User::factory()->asisten()->create();

        $this->actingAs($owner)->get(URL::temporarySignedRoute('signatures.show', now()->addMinutes(15), ['user' => $owner->id]))->assertNotFound();
    }

    public function test_the_profile_page_exposes_the_signature_card_state()
    {
        $owner = User::factory()->asisten()->create();

        $this->actingAs($owner)->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('canSign', true)->where('signatureUrl', null));

        $this->post(route('signature.update'), ['signature' => $this->upload($this->drawn())]);

        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('canSign', true)->has('signatureUrl'));
        $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('canSign', false));
    }
}
