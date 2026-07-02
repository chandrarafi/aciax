<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BpkbRequest;
use App\Models\MasterCustomer;
use App\Models\StokUnit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BpkbController extends Controller
{
    public function process(BpkbRequest $request)
    {
        $valid = $request->validated();
        $stokUnit = StokUnit::with('masterCustomer')
            ->where('no_mesin', $valid['nomesin'])
            ->first();

        $namaKonsumen = $stokUnit?->masterCustomer?->NamaCustomer;

        $images = [];
        $paths = [];
        $maxW = 0;
        $maxH = 0;
        foreach ($valid['images'] as $image) {
            $tmp = $image->getRealPath();
            $size = getimagesize($tmp);
            $w = $size[0] * 0.75;
            $h = $size[1] * 0.75;
            $maxW = max($maxW, $w);
            $maxH = max($maxH, $h);
            $path = $image->store('bpkb', 'public');
            $paths[] = $path;

            $src = match (strtolower($image->getClientOriginalExtension())) {
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
                'width' => $w,
                'height' => $h,
            ];
        }

        $pdf = Pdf::loadView('pdf.bpkb', compact('images', 'namaKonsumen'))
            ->setPaper([0, 0, $maxW, $maxH]);

        $filename = $namaKonsumen . '.pdf';
        Storage::disk('public')->put('bpkb/pdf/' . $filename, $pdf->output());

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'message' => 'PDF berhasil dibuat.',
        ]);
    }
}
