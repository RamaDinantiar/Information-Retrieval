<?php

namespace Tests\Unit;

use App\Support\NimFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NimFormatterTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'tanpa pemisah' => ['202331044', '2023 – 31 – 044'],
            'hyphen' => ['2023-31-044', '2023 – 31 – 044'],
            'hyphen spasi' => ['2023 - 31 - 044', '2023 – 31 – 044'],
            'en dash spasi (sudah benar)' => ['2023 – 32 – 038', '2023 – 32 – 038'],
            'spasi di tepi' => ['  202131010 ', '2021 – 31 – 010'],
            'kosong' => ['', ''],
            'null' => [null, ''],
            'bukan 9 digit dibiarkan' => ['12345', '12345'],
            'mengandung huruf dibiarkan' => ['A2023-31-044', 'A2023-31-044'],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_formats_any_nim_shape(?string $input, string $expected)
    {
        $this->assertSame($expected, NimFormatter::format($input));
    }
}
