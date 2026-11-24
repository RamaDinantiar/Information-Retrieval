<?php

namespace App\Support\Reports;

/**
 * Satu sheet "Rekap Nilai" sebagai grid sel → XML worksheet. Gaya dirujuk lewat id `cellXfs`
 * dari `resources/templates/rekap-nilai/styles.xml` (diambil dari template Excel rekap nilai).
 */
final class RecapSheet
{
    /** @var array<int, array<int, array{v: string|float|int|null, f: string|null, s: int}>> */
    private array $cells = [];

    /** @var list<string> */
    private array $merges = [];

    /** @var array<int, float> */
    private array $widths = [];

    /** @var array<int, float> */
    private array $heights = [];

    public function __construct(public readonly string $name) {}

    public function set(int $row, int $col, string|float|int|null $value, int $style, ?string $formula = null): void
    {
        $this->cells[$row][$col] = ['v' => $value, 'f' => $formula, 's' => $style];
    }

    public function merge(string $range): void
    {
        $this->merges[] = $range;
    }

    public function width(int $col, float $width): void
    {
        $this->widths[$col] = $width;
    }

    public function height(int $row, float $height): void
    {
        $this->heights[$row] = $height;
    }

    public static function column(int $index): string
    {
        $letters = '';
        for ($n = $index; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    public function toXml(bool $selected): string
    {
        ksort($this->cells);
        $lastRow = (int) array_key_last($this->cells);
        $lastCol = 1;
        foreach ($this->cells as $cols) {
            $lastCol = max($lastCol, (int) array_key_last($cols));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="B1:'.self::column($lastCol).$lastRow.'"/>'
            .'<sheetViews><sheetView '.($selected ? 'tabSelected="1" ' : '').'zoomScale="80" zoomScaleNormal="80" workbookViewId="0">'
            .'<pane xSplit="4" ySplit="4" topLeftCell="E5" activePane="bottomRight" state="frozen"/>'
            .'<selection pane="bottomRight" activeCell="E5" sqref="E5"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>';

        if ($this->widths !== []) {
            ksort($this->widths);
            $xml .= '<cols>';
            foreach ($this->widths as $col => $width) {
                $xml .= '<col min="'.$col.'" max="'.$col.'" width="'.$width.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($this->cells as $row => $cols) {
            ksort($cols);
            $height = isset($this->heights[$row]) ? ' ht="'.$this->heights[$row].'" customHeight="1"' : '';
            $xml .= '<row r="'.$row.'"'.$height.'>';
            foreach ($cols as $col => $cell) {
                $xml .= $this->cellXml(self::column($col).$row, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($this->merges !== []) {
            $xml .= '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $range) {
                $xml .= '<mergeCell ref="'.$range.'"/>';
            }
            $xml .= '</mergeCells>';
        }

        return $xml.'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    }

    /**
     * @param  array{v: string|float|int|null, f: string|null, s: int}  $cell
     */
    private function cellXml(string $ref, array $cell): string
    {
        ['v' => $value, 'f' => $formula, 's' => $style] = $cell;
        $open = '<c r="'.$ref.'" s="'.$style.'"';

        if ($formula !== null) {
            return $open.'><f>'.self::escape($formula).'</f>'.($value === null ? '' : '<v>'.$value.'</v>').'</c>';
        }

        return match (true) {
            $value === null => $open.'/>',
            is_string($value) => $open.' t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>',
            default => $open.'><v>'.$value.'</v></c>',
        };
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
