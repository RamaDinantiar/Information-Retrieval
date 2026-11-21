<?php

namespace Tests\Feature\Admin;

use App\Actions\User\TransferSecretaryPosition;
use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Jabatan Sekretaris/Bendahara dipegang satu akun; Admin menunjuk, pemindahan wajib dikonfirmasi. */
class SecretaryPositionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function update(User $user, array $overrides = [])
    {
        return $this->actingAs($this->admin)->put(route('admin.users.update', $user), [
            'role' => 'asisten', 'position' => 'sekretaris_bendahara', 'nim' => $user->nim, 'name' => $user->name, 'email' => $user->email, ...$overrides,
        ]);
    }

    public function test_the_first_secretary_needs_no_confirmation()
    {
        $user = User::factory()->asisten()->create();

        $this->update($user)->assertSessionHasNoErrors();

        $this->assertSame(Position::SekretarisBendahara, $user->fresh()->position);
    }

    public function test_appointing_a_second_account_is_rejected_without_confirmation_and_names_the_current_holder()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create(['name' => 'Dimas Asisten']);
        $new = User::factory()->asisten()->create();

        $this->update($new)->assertSessionHasErrors('position');

        $this->assertStringContainsString('Dimas Asisten', session('errors')->first('position'));
        $this->assertSame(Position::SekretarisBendahara, $current->fresh()->position);
        $this->assertSame(Position::None, $new->fresh()->position);
    }

    public function test_confirming_moves_the_position_notifies_both_and_writes_the_audit_log()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create(['name' => 'Dimas Asisten']);
        $new = User::factory()->asisten()->create(['name' => 'Rani Baru']);

        $this->update($new, ['confirm_transfer' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(Position::None, $current->fresh()->position);
        $this->assertSame(Position::SekretarisBendahara, $new->fresh()->position);
        $this->assertSame(1, User::where('position', Position::SekretarisBendahara)->count());

        $this->assertSame('Jabatan Sekretaris/Bendahara dialihkan', $current->notifications()->sole()->data['title']);
        $this->assertStringContainsString('Rani Baru', $current->notifications()->sole()->data['body']);
        $this->assertSame('Anda menjadi Sekretaris/Bendahara', $new->notifications()->sole()->data['title']);

        $log = AuditLog::where('action', 'user.role_changed')->where('auditable_id', $current->id)->sole();
        $this->assertSame('none', $log->after_json['position']);
        $this->assertStringContainsString('Rani Baru', $log->after_json['reason']);
    }

    public function test_an_inactive_holder_is_also_replaced()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create(['is_active' => false]);
        $new = User::factory()->asisten()->create();

        $this->update($new)->assertSessionHasErrors('position');
        $this->update($new, ['confirm_transfer' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(Position::None, $current->fresh()->position);
    }

    public function test_resaving_the_current_holder_needs_no_confirmation()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create();

        $this->update($current, ['name' => 'Nama Baru'])->assertSessionHasNoErrors();

        $this->assertSame('Nama Baru', $current->fresh()->name);
        $this->assertSame(Position::SekretarisBendahara, $current->fresh()->position);
        $this->assertSame(0, $current->notifications()->count());
    }

    public function test_demoting_the_holder_to_regular_leaves_no_secretary()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create();

        $this->update($current, ['position' => 'none'])->assertSessionHasNoErrors();

        $this->assertSame(0, User::where('position', Position::SekretarisBendahara)->count());
    }

    public function test_creating_a_new_secretary_account_follows_the_same_rule()
    {
        $current = User::factory()->asisten(Position::SekretarisBendahara)->create();
        $payload = ['role' => 'asisten', 'position' => 'sekretaris_bendahara', 'nim' => '202399001', 'name' => 'Akun Baru', 'email' => 'baru@itpln.ac.id'];

        $this->actingAs($this->admin)->post(route('admin.users.store'), $payload)->assertSessionHasErrors('position');
        $this->assertSame(0, User::where('nim', '202399001')->count());

        $this->actingAs($this->admin)->post(route('admin.users.store'), [...$payload, 'confirm_transfer' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(Position::SekretarisBendahara, User::where('nim', '202399001')->sole()->position);
        $this->assertSame(Position::None, $current->fresh()->position);
    }

    public function test_the_users_page_exposes_the_current_secretary()
    {
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $p) => $p->where('secretary', null));

        $current = User::factory()->asisten(Position::SekretarisBendahara)->create(['name' => 'Dimas Asisten']);

        $this->actingAs($this->admin)->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $p) => $p->where('secretary', ['id' => $current->id, 'name' => 'Dimas Asisten', 'isActive' => true]));
    }

    public function test_the_admin_dashboard_warns_when_no_active_secretary_exists()
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $p) => $p->where('dashboard.noSecretary', true));

        $holder = User::factory()->asisten(Position::SekretarisBendahara)->create();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $p) => $p->where('dashboard.noSecretary', false));

        $holder->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $p) => $p->where('dashboard.noSecretary', true));
    }

    public function test_the_action_reports_how_many_holders_were_replaced()
    {
        $old = User::factory()->asisten(Position::SekretarisBendahara)->create();
        $new = User::factory()->asisten(Position::SekretarisBendahara)->create(); // data lama dengan dua pemegang

        $this->assertSame(1, app(TransferSecretaryPosition::class)($new));
        $this->assertSame(Position::None, $old->fresh()->position);
    }

    public function test_non_admins_cannot_change_positions()
    {
        $user = User::factory()->asisten()->create();

        $this->actingAs(User::factory()->asisten(Position::SekretarisBendahara)->create())
            ->put(route('admin.users.update', $user), ['role' => 'asisten', 'position' => 'sekretaris_bendahara'])->assertForbidden();
    }
}
