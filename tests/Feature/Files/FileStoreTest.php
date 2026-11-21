<?php

namespace Tests\Feature\Files;

use App\Models\StoredFile;
use App\Models\TaskTarget;
use App\Models\User;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Pdf;
use Tests\TestCase;

/**
 * PRD 8.1 — keamanan file unggahan.
 */
class FileStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
    }

    private function store(UploadedFile $file, array $kinds = ['pdf', 'zip']): StoredFile
    {
        return app(FileStore::class)->store($file, $kinds, 'test', null, null);
    }

    public function test_a_pdf_is_stored_privately_under_a_uuid_with_checksum()
    {
        $file = $this->store(Pdf::upload('Laporan 04.pdf'));

        $this->assertSame('Laporan 04.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime);
        $this->assertMatchesRegularExpression('#^test/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#', $file->path);
        $this->assertSame(hash('sha256', Pdf::CONTENT), $file->sha256);
        Storage::disk('lab')->assertExists($file->path);
    }

    public function test_the_real_content_type_is_checked_not_the_extension()
    {
        $this->expectException(ValidationException::class);
        $this->store(UploadedFile::fake()->createWithContent('virus.pdf', 'MZ this is not a pdf'));
    }

    public function test_size_limits_per_kind()
    {
        config(['lab.files.limits.pdf' => 1]);

        $this->expectException(ValidationException::class);
        $this->store(Pdf::upload(padKb: 2));
    }

    public function test_zip_with_path_traversal_is_rejected()
    {
        $this->expectException(ValidationException::class);
        $this->store(Pdf::zip(['../../etc/passwd' => 'x']));
    }

    public function test_zip_with_too_many_entries_is_rejected()
    {
        config(['lab.files.zip.max_entries' => 3]);

        $this->expectException(ValidationException::class);
        $this->store(Pdf::zip(['a.py' => '1', 'b.py' => '2', 'c.py' => '3', 'd.py' => '4']));
    }

    public function test_zip_bombs_are_rejected()
    {
        config(['lab.files.zip.max_uncompressed' => 1000]);

        $this->expectException(ValidationException::class);
        $this->store(Pdf::zip(['big.txt' => str_repeat('a', 5000)]));
    }

    public function test_a_valid_zip_is_accepted()
    {
        $file = $this->store(Pdf::zip(['src/main.py' => 'print("hi")', 'README.md' => '# Tugas']));

        $this->assertSame('application/zip', $file->mime);
    }

    public function test_downloads_require_a_signature_and_permission()
    {
        $target = TaskTarget::factory()->create();
        $file = app(FileStore::class)->store(Pdf::upload(), ['pdf'], 'task_attachment', $target->task, null);

        $member = User::factory()->create();
        $target->labClass->enrollments()->create(['user_id' => $member->id, 'joined_at' => now()]);
        $stranger = User::factory()->create();

        $this->actingAs($member)->get(route('files.show', $file))->assertForbidden(); // tanpa signature
        $this->actingAs($member)->get($file->url())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=file; filename*=utf-8\'\''.rawurlencode($file->original_name));
        $this->actingAs($stranger)->get($file->url())->assertForbidden();
    }

    public function test_attachments_of_unopened_tasks_are_hidden_from_students()
    {
        $target = TaskTarget::factory()->create(['open_at' => now()->addDay()]);
        $file = app(FileStore::class)->store(Pdf::upload(), ['pdf'], 'task_attachment', $target->task, null);
        $member = User::factory()->create();
        $target->labClass->enrollments()->create(['user_id' => $member->id, 'joined_at' => now()]);

        $this->actingAs($member)->get($file->url())->assertForbidden();

        $assistant = User::factory()->asisten()->create();
        $target->task->course->assistants()->attach($assistant);
        $this->actingAs($assistant)->get($file->url())->assertOk();
    }

    private function ownFile(User $user, string $kind = 'pdf'): StoredFile
    {
        $upload = $kind === 'pdf' ? Pdf::upload('Laporan.pdf') : Pdf::zip(['a.py' => '1'], 'tugas.zip');

        return app(FileStore::class)->store($upload, [$kind], 'test', null, $user);
    }

    public function test_a_file_that_is_not_scanned_clean_is_locked(): void
    {
        $user = User::factory()->create();
        $file = $this->ownFile($user);
        $file->forceFill(['scan_status' => 'pending'])->save();

        $this->actingAs($user)->get($file->url())->assertStatus(423);

        $file->forceFill(['scan_status' => 'clean'])->save();
        $this->actingAs($user)->get($file->url())->assertOk();
    }

    public function test_inline_viewing_is_only_for_pdfs(): void
    {
        $user = User::factory()->create();
        $pdf = $this->ownFile($user);
        $zip = $this->ownFile($user, 'zip');

        $this->actingAs($user)->get($pdf->url(inline: true))->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=file; filename*=utf-8\'\''.rawurlencode('Laporan.pdf'));

        // ?inline=1 pada ZIP tidak boleh menampilkan inline.
        $this->actingAs($user)->get($zip->url(inline: true))->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $this->actingAs($user)->get($zip->url(inline: true))->headers->get('Content-Disposition'));
    }

    public function test_downloads_are_not_cached_and_cannot_be_sniffed(): void
    {
        $user = User::factory()->create();
        $file = $this->ownFile($user);

        $response = $this->actingAs($user)->get($file->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Audit 03 N1: `private, no-store` (sebelumnya respons akhir `no-store, public`).
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }

    public function test_an_expired_signed_url_is_refused_even_for_the_owner(): void
    {
        $user = User::factory()->create();
        $file = $this->ownFile($user);
        $url = $file->url();

        $this->travel((int) config('lab.files.url_minutes') + 1)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_a_tampered_signed_url_is_refused(): void
    {
        $user = User::factory()->create();
        $mine = $this->ownFile($user);
        $theirs = $this->ownFile(User::factory()->create());

        // Tanda tangan milik file lain tidak berlaku untuk id file lain.
        $forged = str_replace('/files/'.$mine->id, '/files/'.$theirs->id, $mine->url());

        $this->actingAs($user)->get($forged)->assertForbidden();
    }

    public function test_guests_are_sent_to_login_even_with_a_valid_signature(): void
    {
        $file = $this->ownFile(User::factory()->create());

        $this->get($file->url())->assertRedirect(route('login'));
    }
}
