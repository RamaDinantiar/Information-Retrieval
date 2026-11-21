<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LabClass;
use App\Models\Room;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit 03 P0-6: kolom yang tidak ada di formulir tidak boleh bisa diubah dengan menambahkannya ke request.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_cannot_pick_a_role_or_skip_verification(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Sinta', 'nim' => '202331099', 'major' => 'S1 Teknik Informatika', 'cohort_year' => 2023,
            'email' => 'sinta@itpln.ac.id', 'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'terms' => '1',
            'role' => 'admin', 'position' => 'ketua', 'is_active' => false, 'must_change_password' => true,
            'email_verified_at' => now()->toDateTimeString(), 'id' => 9999,
        ]);

        $user = User::where('nim', '202331099')->sole();

        $this->assertSame(Role::Praktikan, $user->role);
        $this->assertSame('none', $user->position->value);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->email_verified_at, 'Email harus tetap menunggu verifikasi.');
        $this->assertNotSame(9999, $user->id);
    }

    public function test_the_profile_form_cannot_change_role_nim_or_activation(): void
    {
        $user = User::factory()->create(['nim' => '202331044']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Nama Baru', 'email' => $user->email,
            'role' => 'admin', 'nim' => '999999999', 'position' => 'ketua', 'is_active' => false, 'must_change_password' => true,
            'password' => 'DiubahLewatProfil1', 'email_verified_at' => null,
        ]);

        $fresh = $user->fresh();

        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame(Role::Praktikan, $fresh->role);
        $this->assertSame('202331044', $fresh->nim);
        $this->assertSame('none', $fresh->position->value);
        $this->assertTrue($fresh->is_active);
        $this->assertFalse($fresh->must_change_password);
        $this->assertFalse(Hash::check('DiubahLewatProfil1', $fresh->password));
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_a_report_form_cannot_set_the_owner_status_or_review_fields(): void
    {
        Storage::fake('lab');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
        $semester = Semester::factory()->active()->create(['start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $class = LabClass::factory()->create(['semester_id' => $semester->id, 'room_id' => Room::factory()->create()->id]);
        $me = User::factory()->asisten()->create();
        $other = User::factory()->asisten()->create();
        $otherSemester = Semester::factory()->archived()->create(['start_date' => '2026-02-01', 'end_date' => '2026-06-30']);

        $this->actingAs($me)->post(route('asisten.reports.store'), [
            'date' => '2026-10-05', 'start_time' => '07:20', 'end_time' => '09:50',
            'class_id' => $class->id, 'activity' => 'Jaga Lab', 'material' => 'OLAP',
            'notes' => 'Praktikum berjalan lancar tanpa kendala.', 'assistant_ids' => [$me->id],
            'photo' => UploadedFile::fake()->image('dokumentasi.jpg'),
            'user_id' => $other->id, 'status' => 'verified', 'verified_by' => $other->id, 'verified_at' => now()->toDateTimeString(),
            'semester_id' => $otherSemester->id, 'editable_until' => '2099-01-01 00:00:00', 'id' => 777,
        ])->assertSessionHasNoErrors();

        $report = SupervisionReport::sole();

        $this->assertSame($me->id, $report->user_id);
        $this->assertSame('draft', $report->status);
        $this->assertNull($report->verified_by);
        $this->assertNull($report->verified_at);
        $this->assertSame($semester->id, $report->semester_id);
        $this->assertSame('2026-10-06', $report->editable_until->toDateString());
        $this->assertNotSame(777, $report->id);
    }

    public function test_the_admin_user_form_cannot_set_a_known_password(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'role' => 'praktikan', 'position' => 'none', 'nim' => '202331055', 'name' => 'Rina Praktikan',
            'email' => 'rina@itpln.ac.id', 'major' => 'S1 Teknik Informatika', 'cohort_year' => 2023,
            'password' => 'DipilihAdmin123', 'must_change_password' => false,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'rina@itpln.ac.id')->first();

        $this->assertNotNull($user, 'Akun harus terbentuk.');
        $this->assertFalse(Hash::check('DipilihAdmin123', $user->password));
        $this->assertTrue($user->must_change_password, 'Password sementara wajib diganti pada login pertama.');
    }
}
