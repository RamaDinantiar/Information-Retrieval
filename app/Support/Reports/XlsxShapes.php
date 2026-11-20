<?php

namespace App\Support\Reports;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * PhpSpreadsheet hanya membawa gambar; TextBox, garis konektor, dan bentuk lain
 * di drawing hilang saat file dibuka lalu disimpan. Kelas ini mengambil bentuk
 * tersebut dari sebuah .xlsx (template) dan menyuntikkannya kembali ke .xlsx
 * hasil, dengan posisi digeser dan ID dibuat unik per drawing.
 *
 * Bentuk tidak punya relasi (r:id), sehingga aman dipindahkan antar-file. Anchor
 * yang memuat gambar (`xdr:pic`, termasuk fallback ink) tidak diambil.
 */
/** XPath yang selalu mengembalikan daftar (ekspresi tak valid = galat, bukan `false`). */
final class ShapeXPath extends DOMXPath
{
    /**
     * @return DOMNodeList<\DOMNameSpaceNode|\DOMNode>
     */
    #[\ReturnTypeWillChange]
    public function query(string $expression, ?\DOMNode $contextNode = null, bool $registerNodeNS = true): DOMNodeList
    {
        $result = parent::query($expression, $contextNode, $registerNodeNS);

        if ($result === false) {
            throw new RuntimeException("Ekspresi XPath tidak valid: {$expression}");
        }

        return $result;
    }
}

class XlsxShapes
{
    private const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Anchor bentuk per sheet (indeks 0-based urutan sheet), tiap elemen berupa XML anchor.
     *
     * @return array<int, list<string>>
     */
    public static function read(string $xlsxPath): array
    {
        $zip = self::open($xlsxPath);
        $result = [];

        try {
            foreach (self::drawingPaths($zip) as $sheet => $drawing) {
                $doc = self::dom((string) $zip->getFromName($drawing));
                $xp = self::xpath($doc);
                $shapes = [];

                /** @var DOMNodeList<DOMElement> $anchors */
                $anchors = $xp->query('/xdr:wsDr/xdr:twoCellAnchor | /xdr:wsDr/xdr:oneCellAnchor | /xdr:wsDr/xdr:absoluteAnchor');

                foreach ($anchors as $anchor) {
                    $isShape = $xp->query('.//xdr:sp | .//xdr:cxnSp', $anchor)->length > 0;
                    $hasPicture = $xp->query('.//xdr:pic', $anchor)->length > 0;

                    if ($isShape && ! $hasPicture) {
                        $shapes[] = self::wrap($anchor);
                    }
                }

                $result[$sheet] = $shapes;
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Suntik bentuk ke file hasil. `$adjust` dipanggil per anchor untuk menggeser
     * posisi atau mengganti teks: fn (int $sheet, DOMElement $anchor): void.
     *
     * @param  array<int, list<string>>  $shapes  hasil read()
     * @param  Closure(int, DOMElement): void  $adjust
     */
    public static function inject(string $xlsxPath, array $shapes, Closure $adjust): void
    {
        $zip = self::open($xlsxPath);

        try {
            $paths = self::drawingPaths($zip);

            foreach ($shapes as $sheet => $anchors) {
                if ($anchors === []) {
                    continue;
                }

                $path = $paths[$sheet] ?? throw new RuntimeException("Sheet {$sheet} tidak memiliki drawing untuk disisipi bentuk.");
                $doc = self::dom((string) $zip->getFromName($path));
                $xp = self::xpath($doc);
                $root = $doc->documentElement ?? throw new RuntimeException('Drawing tidak valid.');
                $nextId = self::maxId($xp) + 1;

                foreach ($anchors as $xml) {
                    /** @var DOMElement $anchor */
                    $anchor = $doc->importNode(self::anchor($xml), true);
                    self::stripExtLists($doc, $anchor);
                    $adjust($sheet, $anchor);
                    self::assignId($xp, $anchor, $nextId++);
                    $root->appendChild($anchor);
                }

                $zip->addFromString($path, (string) $doc->saveXML());
            }
        } finally {
            $zip->close();
        }
    }

    /** Elemen anchor dari XML hasil read() (dibungkus `xdr:wsDr` agar namespace-nya lengkap). */
    public static function anchor(string $xml): DOMElement
    {
        $anchor = self::dom($xml)->documentElement?->firstElementChild;

        return $anchor ?? throw new RuntimeException('Anchor bentuk tidak valid.');
    }

    /** Nama bentuk (cNvPr name) pertama pada anchor. */
    public static function name(DOMElement $anchor): string
    {
        $node = self::xpath($anchor->ownerDocument)->query('.//xdr:cNvPr', $anchor)->item(0);

        return $node instanceof DOMElement ? $node->getAttribute('name') : '';
    }

    /** Baris asal (0-based) pojok kiri atas anchor. */
    public static function fromRow(DOMElement $anchor): int
    {
        return (int) self::edge($anchor, 'from', 'row')->nodeValue;
    }

    public static function fromCol(DOMElement $anchor): int
    {
        return (int) self::edge($anchor, 'from', 'col')->nodeValue;
    }

    /** Geser anchor secara kaku (tinggi tetap) ke bawah/atas. */
    public static function shiftRows(DOMElement $anchor, int $delta): void
    {
        foreach (['from', 'to'] as $edge) {
            $node = self::findEdge($anchor, $edge, 'row');

            if ($node !== null) {
                $node->nodeValue = (string) max(0, (int) $node->nodeValue + $delta);
            }
        }
    }

    /** Geser tepi kanan (`to`) bila berada di atau setelah kolom `$fromCol` (0-based). */
    public static function shiftRightEdge(DOMElement $anchor, int $fromCol, int $delta): void
    {
        $node = self::findEdge($anchor, 'to', 'col');

        if ($node !== null && (int) $node->nodeValue >= $fromCol) {
            $node->nodeValue = (string) max(0, (int) $node->nodeValue + $delta);
        }
    }

    /**
     * Ganti teks run: `$map` = [token/teks lama => teks baru]. Mengembalikan jumlah penggantian.
     *
     * @param  array<string, string>  $map
     */
    public static function replaceText(DOMElement $anchor, array $map): int
    {
        $count = 0;

        foreach (self::xpath($anchor->ownerDocument)->query('.//a:t', $anchor) as $text) {
            if (! $text instanceof DOMElement) {
                continue;
            }

            $before = $text->nodeValue ?? '';
            $after = strtr($before, $map);

            if ($after !== $before) {
                $text->nodeValue = '';
                $text->appendChild($text->ownerDocument->createTextNode($after));
                $count++;
            }
        }

        return $count;
    }

    /**
     * Semua teks run (`a:t`) pada anchor, urut dokumen.
     *
     * @return list<DOMElement>
     */
    public static function textRuns(DOMElement $anchor): array
    {
        $runs = [];

        foreach (self::xpath($anchor->ownerDocument)->query('.//a:t', $anchor) as $node) {
            if ($node instanceof DOMElement) {
                $runs[] = $node;
            }
        }

        return $runs;
    }

    public static function setText(DOMElement $run, string $text): void
    {
        $run->nodeValue = '';
        $run->appendChild($run->ownerDocument->createTextNode($text));
    }

    /** Serialisasi satu anchor beserta deklarasi namespace-nya. */
    private static function wrap(DOMElement $anchor): string
    {
        $doc = new DOMDocument;
        $root = $doc->createElementNS(self::NS_XDR, 'xdr:wsDr');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a', self::NS_A);
        $doc->appendChild($root);
        $root->appendChild($doc->importNode($anchor, true));

        return (string) $doc->saveXML();
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("File xlsx tidak dapat dibuka: {$path}");
        }

        return $zip;
    }

    private static function dom(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;

        if ($xml === '' || ! $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('XML drawing tidak valid.');
        }

        return $doc;
    }

    private static function xpath(?DOMDocument $doc): ShapeXPath
    {
        $xp = new ShapeXPath($doc ?? throw new RuntimeException('Dokumen XML kosong.'));
        $xp->registerNamespace('xdr', self::NS_XDR);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('r', self::NS_R);
        $xp->registerNamespace('m', self::NS_MAIN);
        $xp->registerNamespace('rel', self::NS_REL);

        return $xp;
    }

    /**
     * Peta indeks sheet (urutan di workbook) → path part drawing.
     *
     * @return array<int, string>
     */
    private static function drawingPaths(ZipArchive $zip): array
    {
        $workbook = self::xpath(self::dom((string) $zip->getFromName('xl/workbook.xml')));
        $rels = self::xpath(self::dom((string) $zip->getFromName('xl/_rels/workbook.xml.rels')));

        $targets = [];
        foreach ($rels->query('//rel:Relationship') as $rel) {
            if ($rel instanceof DOMElement) {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        $paths = [];
        $index = 0;

        foreach ($workbook->query('//m:sheets/m:sheet') as $sheet) {
            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $target = $targets[$sheet->getAttributeNS(self::NS_R, 'id')] ?? null;

            if ($target !== null) {
                $sheetPath = 'xl/'.ltrim(str_replace('/xl/', '', $target), '/');
                $relsPath = dirname($sheetPath).'/_rels/'.basename($sheetPath).'.rels';
                $relsXml = $zip->getFromName($relsPath);

                if ($relsXml !== false) {
                    foreach (self::xpath(self::dom($relsXml))->query('//rel:Relationship') as $rel) {
                        if ($rel instanceof DOMElement && str_ends_with($rel->getAttribute('Type'), '/drawing')) {
                            $paths[$index] = self::normalize(dirname($sheetPath).'/'.$rel->getAttribute('Target'));
                        }
                    }
                }
            }

            $index++;
        }

        return $paths;
    }

    private static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }

    private static function maxId(ShapeXPath $xp): int
    {
        $max = 1;

        foreach ($xp->query('//xdr:cNvPr/@id') as $attr) {
            $max = max($max, (int) $attr->nodeValue);
        }

        return $max;
    }

    /** Semua cNvPr pada satu anchor (mis. Choice + Fallback) memakai ID baru yang sama. */
    private static function assignId(ShapeXPath $xp, DOMElement $anchor, int $id): void
    {
        foreach ($xp->query('.//xdr:cNvPr', $anchor) as $node) {
            if ($node instanceof DOMElement) {
                $node->setAttribute('id', (string) $id);
            }
        }
    }

    /** `a:extLst` di cNvPr memuat creationId/predDERef yang merujuk ID lama; aman dibuang. */
    private static function stripExtLists(DOMDocument $doc, DOMElement $anchor): void
    {
        $xp = self::xpath($doc);
        $lists = [];

        foreach ($xp->query('.//xdr:cNvPr/a:extLst', $anchor) as $list) {
            if ($list instanceof DOMElement) {
                $lists[] = $list;
            }
        }

        foreach ($lists as $list) {
            $list->parentNode?->removeChild($list);
        }
    }

    private static function findEdge(DOMElement $anchor, string $edge, string $axis): ?DOMElement
    {
        foreach ($anchor->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $edge && $child->namespaceURI === self::NS_XDR) {
                foreach ($child->childNodes as $leaf) {
                    if ($leaf instanceof DOMElement && $leaf->localName === $axis) {
                        return $leaf;
                    }
                }
            }
        }

        return null;
    }

    private static function edge(DOMElement $anchor, string $edge, string $axis): DOMElement
    {
        return self::findEdge($anchor, $edge, $axis) ?? throw new RuntimeException("Anchor tanpa {$edge}/{$axis}.");
    }
}
