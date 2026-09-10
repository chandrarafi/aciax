<?php

namespace App\Jobs;

use App\Models\BpkbProcessTrack;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
            $this->cleanupTempImages();
            $this->updateStokUnit();
            $this->updateTglBpkbSiap();

            $this->track->update([
                'stage'  => 'completed',
                'status' => 'completed',
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

        $this->track->update(['stage' => 'create_pdf']);
        sleep(2);

        $imagePaths = $this->track->image_paths ?? [];
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

            // Downscale gambar resolusi tinggi (misal dari kamera 12MP/24MP) ke max lebar 1400px untuk hasil scan tajam & jernih
            $maxTargetW = 1400;
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

            // Terapkan efek scan dokumen (kertas putih bersih, kontras teks pekat, tajam seperti scanner)
            $this->applyScanEffect($imgClean);

            // Simpan versi clean
            ob_start();
            imagejpeg($imgClean, null, 85);
            $compressedClean = ob_get_clean();

            $imagesClean[] = [
                'base64' => base64_encode($compressedClean),
                'width'  => $pdfW,
                'height' => $pdfH,
            ];
            unset($compressedClean);

            // 2. Buat canvas dengan watermark "Menara Agung" miring full persis seperti test_wm.png
            $imgWm = imagecreatetruecolor($newW, $newH);
            imagecopy($imgWm, $imgClean, 0, 0, 0, 0, $newW, $newH);
            imagedestroy($imgClean); // Bebaskan memori clean canvas

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

            ob_start();
            imagejpeg($imgWm, null, 85);
            $compressedWm = ob_get_clean();
            imagedestroy($imgWm); // Bebaskan memori canvas watermarked

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

        // 2. Simpan PDF Versi Clean (Tanpa Watermark)
        $pdfClean = Pdf::loadView('pdf.bpkb', [
            'images'        => $imagesClean,
            'namaKonsumen'  => $this->track->nama_konsumen,
        ])->setPaper($paperSize);

        $pdfCleanPath = 'bpkb/pdf/clean/' . $filename;
        Storage::disk('secure')->put($pdfCleanPath, $pdfClean->output());

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

    /**
     * Terapkan efek scan dokumen (CamScanner style):
     * 1. Meningkatkan kontras dokumen agar teks/tinta/stempel lebih jelas dan pekat.
     * 2. Mencerahkan background kertas agar putih bersih alami.
     * 3. Penajaman teks (Gentle Cross-Laplacian Sharpen) agar hasil seperti optik flatbed scanner.
     */
    private function applyScanEffect(&$img): void
    {
        // 1. Kontras & Kecerahan Dokumen
        imagefilter($img, IMG_FILTER_CONTRAST, -12);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, 8);

        // 2. Penajaman detail teks (Gentle Cross-Laplacian Sharpen)
        $sharpenMatrix = [
            [0, -1, 0],
            [-1, 6, -1],
            [0, -1, 0]
        ];
        imageconvolution($img, $sharpenMatrix, 2, 0);
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

        DB::connection('pgsql_meta')
            ->table('tblstock_unit')
            ->where('no_mesin', $this->track->no_mesin)
            ->update(['no_bpkb' => $this->track->no_bpkb]);
    }

    private function updateTglBpkbSiap(): void
    {
        $this->track->update(['stage' => 'update_tgl_bpkb_siap']);
        sleep(2);

        $detail = DB::connection('pgsql_meta')
            ->table('tblterima_bpkb_detail')
            ->where('fk_mesin', $this->track->no_mesin)
            ->select('fk_tanda_terima')
            ->first();

        if ($detail) {
            DB::connection('pgsql_meta')
                ->table('tblterima_bpkb')
                ->where('no_tanda_terima', $detail->fk_tanda_terima)
                ->update(['tgl_bpkb_siap' => now()]);
        }
    }
}
