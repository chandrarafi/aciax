<?php

namespace App\Jobs;

use App\Models\BpkbProcessTrack;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessBpkbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public BpkbProcessTrack $track,
    ) {}

    public function handle(): void
    {
        $this->track->update(['status' => 'processing']);

        try {
            $this->createPdf();
            $this->updateStokUnit();
            $this->updateTglBpkbSiap();
            $this->cleanupTempImages();

            $this->track->update([
                'stage'         => 'completed',
                'status'        => 'completed',
                'error_message' => null,
            ]);
        } catch (\Exception $e) {
            $this->track->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->track->update([
            'status'        => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }

    private function createPdf(): void
    {
        // Naikkan memory limit untuk proses pembuatan PDF dengan gambar
        ini_set('memory_limit', '512M');

        $imagePaths = $this->track->image_paths ?? [];

        // Jika PDF sudah ada dan file fisik valid di disk secure (misal saat retry setelah gagal di update_stok_unit), gunakan PDF yang sudah ada
        if (empty($imagePaths) && $this->track->pdf_path && Storage::disk('secure')->exists($this->track->pdf_path)) {
            return;
        }

        if (empty($imagePaths)) {
            throw new \Exception("File gambar BPKB tidak ditemukan pada storage untuk memproses PDF.");
        }

        $this->track->update(['stage' => 'create_pdf']);
        sleep(2);

        $compressQuality = \App\Models\BpkbSetting::getPdfCompressQuality();

        $imagesWm = [];
        $imagesClean = [];
        $maxW = 0;
        $maxH = 0;

        foreach ($imagePaths as $imagePath) {
            $tmp = null;
            if (Storage::disk('public')->exists($imagePath)) {
                $tmp = Storage::disk('public')->path($imagePath);
            } elseif (Storage::disk('secure')->exists($imagePath)) {
                $tmp = Storage::disk('secure')->path($imagePath);
            } elseif (Storage::disk('local')->exists($imagePath)) {
                $tmp = Storage::disk('local')->path($imagePath);
            } else {
                $candidate = storage_path('app/public/' . $imagePath);
                if (file_exists($candidate)) {
                    $tmp = $candidate;
                }
            }

            if (!$tmp || !file_exists($tmp)) {
                continue;
            }

            $size = @getimagesize($tmp);
            if (!$size) {
                continue;
            }

            $origW = $size[0];
            $origH = $size[1];

            // Downscale gambar resolusi tinggi ke max lebar 800px agar ukuran PDF ~300KB
            $maxTargetW = 800;
            if ($origW > $maxTargetW) {
                $scale = $maxTargetW / $origW;
                $newW  = (int) ($origW * $scale);
                $newH  = (int) ($origH * $scale);
            } else {
                $newW  = $origW;
                $newH  = $origH;
            }

            $pdfW = $newW * 0.75;
            $pdfH = $newH * 0.75;
            $maxW = max($maxW, $pdfW);
            $maxH = max($maxH, $pdfH);

            $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            $src = match ($ext) {
                'png'   => @imagecreatefrompng($tmp),
                'webp'  => @imagecreatefromwebp($tmp),
                default => @imagecreatefromjpeg($tmp),
            };

            if (!$src) {
                continue;
            }

            // 1. Buat canvas bersih (Clean - tanpa watermark)
            $imgClean = imagecreatetruecolor($newW, $newH);
            imagefill($imgClean, 0, 0, imagecolorallocate($imgClean, 255, 255, 255));
            imagecopyresampled($imgClean, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src); // Bebaskan memori gambar mentah segera

            // Terapkan efek scan dokumen jika diaktifkan pada tabel bpkb_settings
            if (\App\Models\BpkbSetting::isScanEffectEnabled()) {
                $this->applyScanEffect($imgClean);
            }

            // Simpan versi clean terkompresi adaptif (~70KB per halaman agar total 4 halaman ~300KB)
            $compressedClean = $this->compressJpegWithQuality($imgClean, $compressQuality, 70000);

            $imagesClean[] = [
                'base64' => base64_encode($compressedClean),
                'width'  => $pdfW,
                'height' => $pdfH,
            ];

            imagedestroy($imgClean); // Bebaskan memori clean canvas

            // 2. Buat versi watermark: decode gambar yang SUDAH terkompresi, lalu timpa watermark di atasnya.
            //    Watermark diterapkan SETELAH kompresi agar teks selalu tajam & tidak ikut blur oleh JPEG.
            $imgWm = imagecreatefromstring($compressedClean);
            unset($compressedClean);

            $font = $this->getAvailableFont();
            $fontSize = 13;
            $angle = 30;
            $color = imagecolorallocatealpha($imgWm, 70, 70, 70, 85);
            $wmText = 'Menara Agung';

            $stepX = 140;
            $stepY = 80;

            if ($font && function_exists('imagettftext')) {
                for ($x = -150; $x < $newW + 200; $x += $stepX) {
                    for ($y = -50; $y < $newH + 200; $y += $stepY) {
                        @imagettftext($imgWm, $fontSize, $angle, $x, $y, $color, $font, $wmText);
                    }
                }
            } else {
                $textColor = imagecolorallocate($imgWm, 150, 150, 150);
                for ($x = 20; $x < $newW; $x += $stepX) {
                    for ($y = 20; $y < $newH; $y += $stepY) {
                        @imagestring($imgWm, 3, $x, $y, $wmText, $textColor);
                    }
                }
            }

            // Simpan watermark - base image sudah terkompresi ~70KB,
            // quality 70 cukup untuk menjaga watermark tetap tajam tanpa membengkak
            ob_start();
            imagejpeg($imgWm, null, 70);
            $compressedWm = ob_get_clean();
            imagedestroy($imgWm);

            $imagesWm[] = [
                'base64' => base64_encode($compressedWm),
                'width'  => $pdfW,
                'height' => $pdfH,
            ];
            unset($compressedWm);
        }

        $paperSize = [0, 0, $maxW ?: 800, $maxH ?: 1100];
        $sanitizedName = $this->track->nama_konsumen ? str_replace(['/', '\\'], '_', $this->track->nama_konsumen) : 'bpkb_' . $this->track->id;
        $filename = $sanitizedName . '.pdf';

        // 1. Simpan PDF Versi Watermark (Default)
        $pdfWm = Pdf::loadView('pdf.bpkb', [
            'images'        => $imagesWm,
            'namaKonsumen'  => $this->track->nama_konsumen,
        ])->setPaper($paperSize);

        $pdfPath = 'bpkb/pdf/' . $filename;
        Storage::disk('secure')->put($pdfPath, $pdfWm->output());
        @chmod(Storage::disk('secure')->path($pdfPath), 0664);

        // 2. Simpan PDF Versi Clean (Tanpa Watermark)
        $pdfClean = Pdf::loadView('pdf.bpkb', [
            'images'        => $imagesClean,
            'namaKonsumen'  => $this->track->nama_konsumen,
        ])->setPaper($paperSize);

        $pdfCleanPath = 'bpkb/pdf/clean/' . $filename;
        Storage::disk('secure')->put($pdfCleanPath, $pdfClean->output());
        @chmod(Storage::disk('secure')->path($pdfCleanPath), 0664);

        $this->track->update(['pdf_path' => $pdfPath]);
    }


    private function getAvailableFont(): ?string
    {
        $candidateFonts = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/TTF/OpenSans.ttf',
        ];

        foreach ($candidateFonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    private function applyScanEffect(&$img): void
    {
        $contrast = \App\Models\BpkbSetting::getScanContrast();
        $brightness = \App\Models\BpkbSetting::getScanBrightness();

        // 1. Kontras & Kecerahan Dokumen
        imagefilter($img, IMG_FILTER_CONTRAST, $contrast);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, $brightness);

        // 2. Penajaman detail teks (Gentle Cross-Laplacian Sharpen)
        $sharpenMatrix = [
            [0, -1, 0],
            [-1, 6, -1],
            [0, -1, 0]
        ];
        imageconvolution($img, $sharpenMatrix, 2, 0);
    }

    /**
     * Kompresi JPEG secara adaptif dengan target batas byte per halaman
     * agar total PDF (4 halaman) dijamin selalu di bawah 1MB (< 1024KB).
     */
    private function compressJpegWithQuality($img, int $initialQuality = 72, int $maxBytes = 220000, int $minQuality = 40): string
    {
        $quality = $initialQuality;
        ob_start();
        imagejpeg($img, null, $quality);
        $data = ob_get_clean();

        // Jika ukuran data masih melebihi batas per-halaman (220KB), turunkan quality secara bertahap
        while (strlen($data) > $maxBytes && $quality > $minQuality) {
            $quality -= 6;
            ob_start();
            imagejpeg($img, null, $quality);
            $data = ob_get_clean();
        }

        return $data;
    }

    private function cleanupTempImages(): void
    {
        foreach ($this->track->image_paths ?? [] as $imagePath) {
            Storage::disk('public')->delete($imagePath);
            Storage::disk('secure')->delete($imagePath);
            Storage::disk('local')->delete($imagePath);
            $localCandidate = storage_path('app/public/' . $imagePath);
            if (file_exists($localCandidate)) {
                @unlink($localCandidate);
            }
        }
        $this->track->update(['image_paths' => null]);
    }

    private function updateStokUnit(): void
    {
        $this->track->update(['stage' => 'update_stok_unit']);
        sleep(2);

        $noMesin = $this->track->no_mesin;
        $noBpkb  = $this->track->no_bpkb;

        if (blank($noMesin) || blank($noBpkb)) {
            throw new \Exception("Gagal update stok unit: nomor mesin atau nomor BPKB kosong.");
        }

        $stokUnit = DB::connection('pgsql_nms')
            ->table('data_unit.tblstock_unit')
            ->where('no_mesin', $noMesin)
            ->first();

        if (! $stokUnit) {
            throw new \Exception("Data stok unit untuk no. mesin '{$noMesin}' tidak ditemukan di database stokunit.");
        }

        DB::connection('pgsql_nms')
            ->table('data_unit.tblstock_unit')
            ->where('no_mesin', $noMesin)
            ->update([
                'no_bpkb' => $noBpkb,
                'tgl_input_bpkb' => now()
            ]);
    }

    private function updateTglBpkbSiap(): void
    {
        $this->track->update(['stage' => 'update_tgl_bpkb_siap']);
        sleep(2);

        $noMesin = $this->track->no_mesin;
        $noBpkb  = $this->track->no_bpkb;

        if (blank($noMesin) || blank($noBpkb)) {
            throw new \Exception("Gagal update tgl_bpkb_siap: nomor mesin atau nomor BPKB kosong.");
        }

        $now = now();

        $databastd = DB::connection('pgsql_nms')
            ->table('data_unit.tblbastd_detail')
            ->where('fk_mesin', $noMesin)
            ->first();

        if (! $databastd) {
            throw new \Exception("Data BASTD untuk no. mesin '{$noMesin}' tidak ditemukan di database data_unit.tblbastd_detail.");
        }

        // Simpan tgl_mohon_samsat ke track jika ada
        $trackUpdates = [];
        if ($databastd->tgl_mohon_samsat) {
            $trackUpdates['tgl_mohon_samsat'] = Carbon::parse($databastd->tgl_mohon_samsat)->toDateString();
        }

        // Backfill fk_dealer jika pada track masih kosong
        if (empty($this->track->fk_dealer)) {
            $dealer = DB::connection('pgsql_nms')
                ->table('data_unit.tblstock_unit')
                ->where('no_mesin', $noMesin)
                ->value('fk_dealer');
            if ($dealer) {
                $trackUpdates['fk_dealer'] = $dealer;
            }
        }

        if (!empty($trackUpdates)) {
            $this->track->update($trackUpdates);
        }

        $affected = DB::connection('pgsql_nms')
            ->table('data_unit.tblbastd_detail')
            ->where('fk_mesin', $noMesin)
            ->update([
                'no_bpkb'        => $noBpkb,
                'tgl_input_bpkb' => $now,
                'tgl_bpkb_siap'  => $databastd->tgl_mohon_samsat,
                'is_input_bpkb'  => true,
            ]);

        if ($affected === 0) {
            throw new \Exception("Gagal memperbarui tabel BASTD detail untuk no. mesin '{$noMesin}'.");
        }
    }
}
