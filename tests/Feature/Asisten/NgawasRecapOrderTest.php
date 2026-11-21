<?php

namespace Tests\Feature\Asisten;

use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\NgawasRecapOrder;
use App\Models\User;
use App\Support\Reports\NgawasRecap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsNgawasGoldenFixture;
use Tests\TestCase;

/** Pengaturan Urutan Rekap: urutan asisten dan baris kelas, per semester. */
class NgawasRecapOrderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsNgawasGoldenFixture;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->seedFromFixture();
        $this->manager = User::factory()->asisten(Position::SekretarisBendahara)->create();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    /** @return array{0: list<int>, 1: array<int, list<string>>} */
    private function current(): array
    {
        $recap = NgawasRecap::build($this->semester, '2026-04');
        $ids = array_column($recap['assistants'], 'userId');
        $classes = [];
        foreach ($recap['assistants'] as $a) {
            $classes[$a['userId']] = array_values(array_map(fn ($r) => $r['key'], array_filter($a['rows'], fn ($r) => ! $r['placeholder'])));
        }

        return [$ids, $classes];
    }

    private function save(array $ids, array $classes, ?User $user = null)
    {
        $payload = array_map(fn ($id) => ['userId' => $id, 'classes' => $classes[$id] ?? []], $ids);

        return $this->actingAs($user ?? $this->manager)->put(route('asisten.ngawas-recap.order.update'), ['month' => '2026-04', 'assistants' => $payload]);
    }

    public function test_the_assistant_order_is_saved_and_used_by_the_recap()
    {
        [$ids, $classes] = $this->current();

        $this->save(array_reverse($ids), $classes)->assertSessionHasNoErrors();

        $this->assertSame(array_reverse($ids), array_column(NgawasRecap::build($this->semester, '2026-04')['assistants'], 'userId'));
        $this->assertSame(count($ids), NgawasRecapOrder::count());
        $this->assertSame(1, AuditLog::where('action', 'ngawas_recap.order.updated')->count());
    }

    public function test_the_class_order_within_an_assistant_is_saved()
    {
        [$ids, $classes] = $this->current();
        $multi = collect($classes)->first(fn ($c) => count($c) > 1);
        $userId = array_search($multi, $classes, true);
        $classes[$userId] = array_reverse($multi);

        $this->save($ids, $classes)->assertSessionHasNoErrors();

        $row = collect(NgawasRecap::build($this->semester, '2026-04')['assistants'])->firstWhere('userId', $userId);
        $this->assertSame(array_reverse($multi), array_values(array_map(fn ($r) => $r['key'], $row['rows'])));
    }

    public function test_class_keys_not_shown_in_the_dialog_are_kept()
    {
        [$ids, $classes] = $this->current();
        NgawasRecapOrder::create(['semester_id' => $this->semester->id, 'user_id' => $ids[0], 'position' => 0, 'class_order_json' => ['c99999']]);

        $this->save($ids, $classes)->assertSessionHasNoErrors();

        $this->assertSame('c99999', collect(NgawasRecapOrder::where('user_id', $ids[0])->value('class_order_json'))->last());
    }

    public function test_resetting_returns_to_the_automatic_order()
    {
        [$ids, $classes] = $this->current();
        $this->save(array_reverse($ids), $classes);

        $this->actingAs($this->manager)->delete(route('asisten.ngawas-recap.order.destroy'), ['month' => '2026-04'])->assertSessionHasNoErrors();

        $this->assertSame(0, NgawasRecapOrder::count());
        $this->assertSame($ids, array_column(NgawasRecap::build($this->semester, '2026-04')['assistants'], 'userId'));
    }

    public function test_invalid_payloads_are_rejected()
    {
        [$ids] = $this->current();
        $url = route('asisten.ngawas-recap.order.update');

        $this->actingAs($this->manager)->put($url, ['month' => '2026-04', 'assistants' => [['userId' => 999999, 'classes' => []]]])->assertSessionHasErrors('assistants');
        $this->actingAs($this->manager)->put($url, ['month' => '2026-04', 'assistants' => [['userId' => $ids[0], 'classes' => ['bad key']]]])->assertSessionHasErrors('assistants.0.classes.0');
        $this->actingAs($this->manager)->put($url, ['month' => '2026-04', 'assistants' => [['userId' => $ids[0], 'classes' => []], ['userId' => $ids[0], 'classes' => []]]])->assertSessionHasErrors();
        $this->actingAs($this->manager)->put($url, ['month' => 'x', 'assistants' => []])->assertSessionHasErrors(['month', 'assistants']);

        $this->assertSame(0, NgawasRecapOrder::count());
    }

    public function test_only_the_managers_may_change_the_order()
    {
        [$ids, $classes] = $this->current();
        $regular = User::factory()->asisten()->create();

        $this->save($ids, $classes, $regular)->assertForbidden();
        $this->actingAs($regular)->delete(route('asisten.ngawas-recap.order.destroy'), ['month' => '2026-04'])->assertForbidden();
        $this->save($ids, $classes, User::factory()->admin()->create())->assertSessionHasNoErrors();
    }
}
