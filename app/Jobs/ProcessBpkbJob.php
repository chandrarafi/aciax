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
        // Skip if PDF already generated (idempotent retry)
        if ($this->track->pdf_path && Storage::disk('public')->exists($this->track->pdf_path)) {
            return;
        }

        $this->track->update(['stage' => 'create_pdf']);
        sleep(2);

        $imagePaths = $this->track->image_paths ?? [];
        $images = [];
        $maxW = 0;
        $maxH = 0;

        foreach ($imagePaths as $imagePath) {
            $tmp = Storage::disk('public')->path($imagePath);
            $size = getimagesize($tmp);
            $w = $size[0] * 0.75;
            $h = $size[1] * 0.75;
            $maxW = max($maxW, $w);
            $maxH = max($maxH, $h);

            $src = match (strtolower(pathinfo($imagePath, PATHINFO_EXTENSION))) {
                'png' => imagecreatefrompng($tmp),
                default => imagecreatefromjpeg($tmp),
            };
            $img = imagecreatetruecolor(imagesx($src), imagesy($src));
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
            imagedestroy($src);

            ob_start();
            imagejpeg($img, null, 50);
            $compressed = ob_get_clean();
            imagedestroy($img);

            $images[] = [
                'base64' => base64_encode($compressed),
                'width'  => $w,
                'height' => $h,
            ];
        }

        $pdf = Pdf::loadView('pdf.bpkb', [
            'images'        => $images,
            'namaKonsumen'  => $this->track->nama_konsumen,
        ])->setPaper([0, 0, $maxW, $maxH]);

        $filename = $this->track->nama_konsumen . '.pdf';
        $pdfPath = 'bpkb/pdf/' . $filename;
        Storage::disk('public')->put($pdfPath, $pdf->output());

        $this->track->update(['pdf_path' => $pdfPath]);
    }

    private function cleanupTempImages(): void
    {
        foreach ($this->track->image_paths ?? [] as $imagePath) {
            Storage::disk('public')->delete($imagePath);
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
