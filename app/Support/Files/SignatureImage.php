<?php

namespace App\Support\Files;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Menyiapkan gambar tanda tangan untuk rekap ngawas (US-RJN-02): latar putih
 * dijadikan transparan, ruang kosong dipangkas, lebar maksimal 600 px, hasil
 * berupa PNG transparan. Dipakai untuk tanda tangan asisten dan pengesah.
 */
class SignatureImage
{
    public const MAX_WIDTH = 600;

    public const MAX_KB = 1024;

    /** Gambar mentah yang lebih besar dari ini diperkecil dulu agar pemrosesan piksel tetap ringan. */
    private const WORK_WIDTH = 1200;

    private const MAX_SOURCE_PIXELS = 12_000_000;

    /** Piksel dengan luminans di atas ini dianggap kertas (putih) bila gambar tak punya transparansi. */
    private const PAPER = 240;

    /** Piksel yang lebih gelap dari ini dianggap tinta penuh. */
    private const INK = 110;

    /** Ambang alfa (0–255) piksel yang dihitung sebagai goresan saat memangkas. */
    private const TRIM_ALPHA = 24;

    private const PADDING = 4;

    /**
     * @return string path file PNG sementara (hapus setelah dipakai)
     */
    public function fromUpload(UploadedFile $upload, string $field = 'signature'): string
    {
        $mime = FileStore::realMime($upload);

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)
            || ! in_array(strtolower($upload->getClientOriginalExtension()), ['png', 'jpg', 'jpeg'], true)) {
            $this->fail($field, 'Format tanda tangan harus PNG atau JPG.');
        }

        if ($upload->getSize() > self::MAX_KB * 1024) {
            $this->fail($field, 'Ukuran gambar tanda tangan maksimal 1 MB.');
        }

        return $this->process((string) file_get_contents($upload->getRealPath()), $field);
    }

    /**
     * @param  string  $dataUrl  `data:image/png;base64,…` dari kanvas
     */
    public function fromDataUrl(string $dataUrl, string $field = 'signature_data'): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            $this->fail($field, 'Gambar tanda tangan tidak valid.');
        }

        $binary = base64_decode($m[1], true);

        if ($binary === false || strlen($binary) > self::MAX_KB * 1024) {
            $this->fail($field, 'Gambar tanda tangan tidak valid atau lebih dari 1 MB.');
        }

        return $this->process($binary, $field);
    }

    private function process(string $binary, string $field): string
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            $this->fail($field, 'Gambar tanda tangan tidak dapat dibaca atau terlalu besar.');
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            $this->fail($field, 'Gambar tanda tangan tidak dapat dibaca.');
        }

        $work = $this->downscale($source, self::WORK_WIDTH);
        $alpha = $this->alphaMap($work);
        $box = $this->boundingBox($alpha, imagesx($work), imagesy($work));

        if ($box === null) {
            $this->fail($field, 'Tanda tangan tampak kosong. Goreskan atau unggah tanda tangan yang jelas.');
        }

        $png = $this->render($work, $alpha, $box);

        $path = tempnam(sys_get_temp_dir(), 'sig');
        imagepng($png, $path, 9);

        return $path;
    }

    private function downscale(GdImage $image, int $maxWidth): GdImage
    {
        if (imagesx($image) <= $maxWidth) {
            return $image;
        }

        $scaled = imagescale($image, $maxWidth, -1, IMG_BICUBIC);

        return $scaled === false ? $image : $scaled;
    }

    /**
     * Alfa per piksel (0–255, baris demi baris). Gambar bertransparansi memakai
     * alfa aslinya; gambar buram: putih → transparan, makin gelap makin pekat.
     *
     * @return list<list<int>>
     */
    private function alphaMap(GdImage $image): array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $hasAlpha = false;

        for ($y = 0; $y < $h && ! $hasAlpha; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 60) {
                    $hasAlpha = true;
                    break;
                }
            }
        }

        $map = [];

        for ($y = 0; $y < $h; $y++) {
            $row = [];

            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($image, $x, $y);
                $a = (int) round(255 - ((($c >> 24) & 0x7F) * 255 / 127));

                if (! $hasAlpha) {
                    $luma = (int) (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF));
                    $a = $luma >= self::PAPER ? 0 : ($luma <= self::INK ? 255 : (int) round((self::PAPER - $luma) * 255 / (self::PAPER - self::INK)));
                }

                $row[] = $a;
            }

            $map[] = $row;
        }

        return $map;
    }

    /**
     * @param  list<list<int>>  $alpha
     * @return array{0: int, 1: int, 2: int, 3: int}|null [x1, y1, x2, y2]
     */
    private function boundingBox(array $alpha, int $w, int $h): ?array
    {
        $x1 = $w;
        $y1 = $h;
        $x2 = -1;
        $y2 = -1;

        foreach ($alpha as $y => $row) {
            foreach ($row as $x => $a) {
                if ($a > self::TRIM_ALPHA) {
                    $x1 = min($x1, $x);
                    $x2 = max($x2, $x);
                    $y1 = min($y1, $y);
                    $y2 = max($y2, $y);
                }
            }
        }

        return $x2 < 0 ? null : [max(0, $x1 - self::PADDING), max(0, $y1 - self::PADDING), min($w - 1, $x2 + self::PADDING), min($h - 1, $y2 + self::PADDING)];
    }

    /**
     * @param  list<list<int>>  $alpha
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     */
    private function render(GdImage $source, array $alpha, array $box): GdImage
    {
        [$x1, $y1, $x2, $y2] = $box;
        $w = max(1, $x2 - $x1 + 1);
        $h = max(1, $y2 - $y1 + 1);

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, (int) imagecolorallocatealpha($out, 0, 0, 0, 127));

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = $alpha[$y1 + $y][$x1 + $x];

                if ($a === 0) {
                    continue;
                }

                $c = imagecolorat($source, $x1 + $x, $y1 + $y);
                imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, max(0, min(127, 127 - (int) round($a * 127 / 255)))));
            }
        }

        if ($w > self::MAX_WIDTH) {
            $scaled = imagescale($out, self::MAX_WIDTH, -1, IMG_BICUBIC);

            if ($scaled !== false) {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                $out = $scaled;
            }
        }

        return $out;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
