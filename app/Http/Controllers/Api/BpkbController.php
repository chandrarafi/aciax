<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BpkbRequest;
use App\Jobs\ProcessBpkbJob;
use App\Models\BpkbProcessTrack;
use App\Models\StokUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Models\TerimaBpkb;
use Illuminate\Support\Facades\DB;

class BpkbController extends Controller
{
    public function process(BpkbRequest $request): JsonResponse
    {
        $valid = $request->validated();

        // Perform cleansing on requested no_mesin
        $cleansedNoMesin = $this->cleansingNoMesin($valid['nomesin']);

        // $imagePaths = [];
        // foreach ($valid['images'] as $image) {
        //     $path = $image->store('bpkb/temp', 'public');
        //     $imagePaths[] = $path;
        // }

        // $stokUnit = StokUnit::select('nm_customer')
        //     ->where('no_mesin', $cleansedNoMesin)
        //     ->first();

        // $track = BpkbProcessTrack::create([
        //     'no_mesin'       => $cleansedNoMesin,
        //     'no_bpkb'        => $valid['nobpkb'],
        //     'nama_konsumen'  => $stokUnit?->nm_customer,
        //     'image_paths'    => $imagePaths,
        //     'stage'          => 'pending',
        //     'status'         => 'queued',
        // ]);

        // ProcessBpkbJob::dispatch($track);

        return response()->json([
            // 'message'           => 'BPKB sedang diproses.',
            // 'track_id'          => $track->id,
            'no_mesin_cleansed' => $cleansedNoMesin,
        ], 202);
    }

    /**
     * Function untuk melakukan cleansing nomor mesin dari request.
     *
     * Contoh Input : "JMEIE2g77865"
     * Result Output: "JME1E2977865"
     *
     * - 5 digit pertama (Prefix): Dicari prefix paling mirip dari DB pgsql_nms -> public.tbltipe_kendaraan
     * - 7 digit berikutnya (Serial): Dicleansing agar semua karakter typo menjadi digit angka
     *
     * @param string $rawNoMesin
     * @return string
     */
    public function cleansingNoMesin(string $rawNoMesin): string
    {
        $rawNoMesin = strtoupper(trim($rawNoMesin));

        // Jika panjang kurang dari 12 karakter, kembalikan string asli
        if (strlen($rawNoMesin) < 12) {
            return $rawNoMesin;
        }

        // Split 5 digit prefix dan 7 digit serial number
        $rawPrefix = substr($rawNoMesin, 0, 5);
        $rawSerial = substr($rawNoMesin, 5, 7);

        // 1. Cleansing 5 digit prefix berdasarkan kemiripan data di public.tbltipe_kendaraan
        $cleansedPrefix = $this->getClosestPrefixFromDb($rawPrefix);

        // 2. Cleansing 7 digit serial number (ganti karakter huruf typo menjadi angka)
        $cleansedSerial = $this->cleansingSerialDigits($rawSerial);

        // Gabungkan kembali (serta sisa karakter jika nomor mesin > 12 digit)
        $suffix = strlen($rawNoMesin) > 12 ? substr($rawNoMesin, 12) : '';

        return $cleansedPrefix . $cleansedSerial . $suffix;
    }

    /**
     * Mencari prefix 5 digit paling mirip dari database pgsql_nms table public.tbltipe_kendaraan
     */
    private function getClosestPrefixFromDb(string $rawPrefix): string
    {
        try {
            // Ambil daftar prefix (5 karakter pertama) dari kolom digit_no_mesin di public.tbltipe_kendaraan
            $prefixes = DB::connection('pgsql_nms')
                ->table('public.tbltipe_kendaraan')
                ->whereNotNull('digit_no_mesin')
                ->select(DB::raw("DISTINCT UPPER(LEFT(digit_no_mesin, 5)) as prefix"))
                ->pluck('prefix')
                ->filter()
                ->toArray();

            if (empty($prefixes)) {
                return $rawPrefix;
            }

            $bestMatch = $rawPrefix;
            $minDistance = -1;

            foreach ($prefixes as $validPrefix) {
                // Gunakan Levenshtein distance untuk mencari string paling mirip
                $distance = levenshtein($rawPrefix, $validPrefix);

                if ($distance === 0) {
                    return $validPrefix; // Match sempurna
                }

                if ($minDistance === -1 || $distance < $minDistance) {
                    $minDistance = $distance;
                    $bestMatch = $validPrefix;
                }
            }

            // Jika jarak kemiripan terdekat <= 2 karakter, gunakan bestMatch
            return ($minDistance >= 0 && $minDistance <= 2) ? $bestMatch : $rawPrefix;
        } catch (\Throwable $e) {
            // Jika terjadi kegagalan query, fallback ke prefix asli
            return $rawPrefix;
        }
    }

    /**
     * Melakukan cleansing 7 digit serial number (mengubah karakter typo OCR/keyboard ke angka)
     */
    private function cleansingSerialDigits(string $serial): string
    {
        $charToDigitMap = [
            'O' => '0', 'o' => '0',
            'I' => '1', 'i' => '1', 'L' => '1', 'l' => '1',
            'Z' => '2', 'z' => '2',
            'E' => '3', 'e' => '3',
            'A' => '4', 'a' => '4',
            'S' => '5', 's' => '5',
            'T' => '7', 't' => '7',
            'B' => '8', 'b' => '8',
            'G' => '9', 'g' => '9', 'Q' => '9', 'q' => '9',
        ];

        return strtr($serial, $charToDigitMap);
    }

    public function track(BpkbProcessTrack $track): JsonResponse
    {
        return response()->json($track);
    }

    public function trackStream(BpkbProcessTrack $track): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->stream(function () use ($track) {
            $lastStage = null;
            $lastStatus = null;

            while (true) {
                $track->refresh();

                if ($track->stage !== $lastStage || $track->status !== $lastStatus) {
                    $data = json_encode([
                        'stage'        => $track->stage,
                        'status'       => $track->status,
                        'pdf_url'      => $track->pdf_path ? Storage::disk('public')->url($track->pdf_path) : null,
                        'error_message' => $track->error_message,
                    ]);

                    echo "data: {$data}\n\n";
                    ob_flush();
                    flush();

                    $lastStage = $track->stage;
                    $lastStatus = $track->status;
                }

                if (in_array($track->status, ['completed', 'failed'])) {
                    break;
                }

                // sleep(1);
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection'    => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function activity(): JsonResponse
    {
        // $monthNow = now()->format('Y-m');
        $monthNow = '2026-04';
        $targetBpkb = TerimaBpkb::where('tgl_tanda_terima', 'like', "{$monthNow}%")->count();
        $completedBpkb = BpkbProcessTrack::where('status', 'completed')
            ->where('created_at', 'like', "{$monthNow}%")
            ->count();
        $pendingBpkb = $targetBpkb - $completedBpkb;

        return response()->json([
            'target_bpkb' => $targetBpkb,
            'completed_bpkb' => $completedBpkb,
            'pending_bpkb' => $pendingBpkb,
        ]);
    }
}
