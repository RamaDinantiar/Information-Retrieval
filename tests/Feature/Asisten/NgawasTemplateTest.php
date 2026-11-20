<?php

namespace Tests\Feature\Asisten;

use App\Support\Reports\NgawasTemplate;
use App\Support\Reports\NgawasTemplateValidator;
use App\Support\Reports\XlsxShapes;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/** B2/FR-RJN-12: template `resources/templates/rekap-ngawas.xlsx` dan validatornya. */
class NgawasTemplateTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function copyTemplateThrough(callable $mutate): string
    {
        $spreadsheet = IOFactory::load(NgawasTemplate::path());
        $mutate($spreadsheet);
        $path = $this->temp[] = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function test_the_repository_template_passes_validation()
    {
        $this->assertFileExists(NgawasTemplate::path());
        $this->assertSame([], NgawasTemplateValidator::validate(NgawasTemplate::path()));
    }

    public function test_the_template_holds_no_data_only_styles_header_legend_and_logos()
    {
        $spreadsheet = IOFactory::load(NgawasTemplate::path());

        foreach ([0, 1] as $i) {
            $ws = $spreadsheet->getSheet($i);

            for ($r = NgawasTemplate::FIRST_DATA_ROW; $r < NgawasTemplate::TOTAL_ROW; $r++) {
                foreach (['A', 'B', 'C', 'D', 'E', 'J', 'AI'] as $col) {
                    $this->assertNull($ws->getCell($col.$r)->getValue(), "{$col}{$r} sheet {$i}");
                }

                $this->assertSame('none', $ws->getCell('J'.$r)->getStyle()->getFill()->getFillType(), "warna sisa di J{$r}");
            }

            $this->assertCount(2, collect($ws->getDrawingCollection())->filter(fn ($d) => str_starts_with($d->getName(), 'Picture')), 'hanya dua logo');
            $this->assertCount(2, $ws->getDrawingCollection(), 'tanpa gambar tanda tangan');
            $this->assertSame(31, (int) $ws->getCell('AI15')->getValue());
        }

        $recap = $spreadsheet->getSheet(0);
        $this->assertSame('Minggu', trim((string) $recap->getCell('B80')->getValue()));
        $this->assertSame('0070C0', $recap->getStyle('A80')->getFill()->getStartColor()->getRGB());
        $this->assertSame('REKAPITULASI KEHADIRAN ASISTEN LABORATORIUM INFORMATION RETRIEVAL', $recap->getCell('A8')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_approver_text_boxes_carry_placeholders_and_no_personal_names()
    {
        $shapes = XlsxShapes::read(NgawasTemplate::path());
        $texts = [];

        foreach ($shapes as $sheet => $anchors) {
            foreach ($anchors as $xml) {
                foreach (XlsxShapes::textRuns(XlsxShapes::anchor($xml)) as $run) {
                    $texts[$sheet][] = trim((string) $run->nodeValue);
                }
            }
        }

        $this->assertContains('{{pengesah_1_jabatan}}', $texts[0]);
        $this->assertContains('{{pengesah_1_nama}}', $texts[0]);
        $this->assertContains('{{pengesah_2_jabatan}}', $texts[0]);
        $this->assertContains('{{pengesah_2_nama}}', $texts[0]);
        $this->assertContains('{{pengesah_2_nama}}', $texts[1]);

        // Selain penanda, satu-satunya teks di bentuk adalah catatan rumus "1 sks = 50 menit".
        $static = array_values(array_filter(array_merge(...array_values($texts)), fn (string $t) => $t !== '' && ! str_starts_with($t, '{{')));
        $this->assertLessThanOrEqual(12, count($static));
        foreach ($static as $text) {
            $this->assertLessThanOrEqual(30, mb_strlen($text), "teks terlalu panjang untuk catatan rumus: {$text}");
            $this->assertDoesNotMatchRegularExpression('/Dr\.|M\.Kom|S\.T|ST,|Prof/u', $text, "kemungkinan nama orang: {$text}");
        }
    }

    public function test_the_package_metadata_is_neutral()
    {
        $zip = new ZipArchive;
        $zip->open(NgawasTemplate::path());
        $core = (string) $zip->getFromName('docProps/core.xml');
        $zip->close();

        $this->assertStringContainsString('<dc:creator>IR Lab Management System</dc:creator>', $core);
        $this->assertStringContainsString('<cp:lastModifiedBy>IR Lab Management System</cp:lastModifiedBy>', $core);
    }

    public function test_a_renamed_sheet_is_reported_with_the_expected_name()
    {
        $path = $this->copyTemplateThrough(fn ($s) => $s->getSheet(0)->setTitle('Salah'));

        $errors = NgawasTemplateValidator::validate($path);

        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Format Aslab')));
    }

    public function test_a_missing_legend_colour_header_or_text_box_is_reported()
    {
        // PhpSpreadsheet menyimpan ulang tanpa kotak teks: validator harus melaporkannya.
        $noShapes = $this->copyTemplateThrough(fn () => null);
        $this->assertNotEmpty(array_filter(NgawasTemplateValidator::validate($noShapes), fn ($e) => str_contains($e, 'Kotak teks pengesah')));

        $noLegend = $this->copyTemplateThrough(function ($s) {
            $s->getSheet(0)->getStyle('A82')->getFill()->setFillType('none');
            $s->getSheet(0)->setCellValue('B83', '');
        });
        $errors = NgawasTemplateValidator::validate($noLegend);
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'additional') && str_contains($e, 'A82')));
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'B83')));

        $noHeader = $this->copyTemplateThrough(fn ($s) => $s->getSheet(1)->setCellValue('B13', 'NAMA'));
        $this->assertNotEmpty(array_filter(NgawasTemplateValidator::validate($noHeader), fn ($e) => str_contains($e, 'kolom B')));
    }

    public function test_a_file_that_is_not_a_workbook_is_rejected()
    {
        $path = $this->temp[] = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'bukan xlsx');

        $this->assertStringContainsString('tidak dapat dibaca', NgawasTemplateValidator::validate($path)[0]);
    }

    public function test_a_workbook_with_a_single_sheet_is_rejected()
    {
        $path = $this->copyTemplateThrough(fn ($s) => $s->removeSheetByIndex(1));

        $this->assertSame(['Template harus memiliki tepat 2 sheet.'], NgawasTemplateValidator::validate($path));
    }

    public function test_the_make_template_command_refuses_a_missing_source()
    {
        $this->artisan('ngawas:make-template', ['source' => '/tidak/ada.xlsx'])
            ->expectsOutputToContain('File sumber tidak ditemukan')
            ->assertFailed();
    }
}
