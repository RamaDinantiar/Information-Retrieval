<?php

namespace Tests\Unit;

use App\Support\Reports\NgawasRecap;
use PHPUnit\Framework\TestCase;

/** Audit 08 W10: sidik jari rekap tidak bergantung pada urutan kunci objek. */
class NgawasRecapFingerprintTest extends TestCase
{
    public function test_object_key_order_does_not_change_the_fingerprint(): void
    {
        $a = ['month' => '2026-11', 'totals' => ['days' => 2, 'hours' => 4.0], 'assistants' => [['userId' => 1, 'name' => 'A']]];
        $b = ['assistants' => [['name' => 'A', 'userId' => 1]], 'totals' => ['hours' => 4.0, 'days' => 2], 'month' => '2026-11'];

        $this->assertSame(NgawasRecap::fingerprint($a), NgawasRecap::fingerprint($b));
    }

    public function test_list_order_still_matters(): void
    {
        $a = ['assistants' => [['userId' => 1], ['userId' => 2]]];
        $b = ['assistants' => [['userId' => 2], ['userId' => 1]]];

        $this->assertNotSame(NgawasRecap::fingerprint($a), NgawasRecap::fingerprint($b));
    }

    public function test_a_changed_value_changes_the_fingerprint(): void
    {
        $a = ['totals' => ['hours' => 4.0]];
        $b = ['totals' => ['hours' => 5.0]];

        $this->assertNotSame(NgawasRecap::fingerprint($a), NgawasRecap::fingerprint($b));
    }
}
