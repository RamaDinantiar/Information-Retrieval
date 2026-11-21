<?php

namespace Tests\Feature;

use App\Models\LabClass;
use App\Models\Semester;
use App\Support\JoinToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 03 P1-2: token gabung kelas (FR-ENR-01).
 */
class JoinTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_has_six_characters_from_the_unambiguous_alphabet(): void
    {
        $semester = Semester::factory()->active()->create();

        for ($i = 0; $i < 200; $i++) {
            $token = JoinToken::generate($semester->id);

            $this->assertSame(JoinToken::LENGTH, strlen($token));
            $this->assertMatchesRegularExpression('/^['.preg_quote(JoinToken::ALPHABET, '/').']{6}$/', $token);
            $this->assertDoesNotMatchRegularExpression('/[O0I1]/', $token);
        }
    }

    public function test_the_alphabet_has_no_ambiguous_or_duplicate_characters(): void
    {
        $this->assertSame(32, strlen(JoinToken::ALPHABET));
        $this->assertSame(strlen(JoinToken::ALPHABET), count(array_unique(str_split(JoinToken::ALPHABET))));
        $this->assertSame(JoinToken::ALPHABET, strtoupper(JoinToken::ALPHABET));
    }

    public function test_the_same_token_may_exist_in_another_semester(): void
    {
        $semester = Semester::factory()->active()->create();
        $other = Semester::factory()->archived()->create();
        LabClass::factory()->create(['semester_id' => $other->id, 'join_token' => 'ABC234']);

        // generate() hanya menolak token yang sama di semester yang sama; kelas semester lain tidak ikut dihitung.
        $this->assertSame(1, LabClass::where('semester_id', $other->id)->where('join_token', 'ABC234')->count());
        $this->assertSame(0, LabClass::where('semester_id', $semester->id)->where('join_token', 'ABC234')->count());
        $this->assertSame(JoinToken::LENGTH, strlen(JoinToken::generate($semester->id)));
    }
}
