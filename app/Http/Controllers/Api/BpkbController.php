<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BpkbRequest;
use App\Jobs\ProcessBpkbJob;
use App\Models\BpkbProcessTrack;
use App\Models\StokUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\TerimaBpkb;
use App\Models\Dealer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BpkbController extends Controller
{
    public function process(BpkbRequest $request): JsonResponse
    {
        $valid = $request->validated();

        $cleansingMesin  = $this->cleansingNoMesinWithDetails($valid['nomesin']);
        $cleansedNoMesin = $cleansingMesin['cleansed'];
        $akurasi         = $cleansingMesin['overall_accuracy_pct'] . '%';
        $cleansedNoBpkb  = $this->cleansingNoBpkb($valid['nobpkb']);

        $imagePaths = [];
        foreach ($valid['images'] as $image) {
            $path = $image->store('bpkb/temp', 'public');
            $imagePaths[] = $path;
        }

        $stokUnit = StokUnit::select('nm_customer', 'fk_dealer')
            ->where('no_mesin', $cleansedNoMesin)
            ->first();

        $bastd = DB::connection('pgsql_nms')
            ->table('data_unit.tblbastd_detail')
            ->where('fk_mesin', $cleansedNoMesin)
            ->select('tgl_mohon_samsat')
            ->first();

        $existing = BpkbProcessTrack::query()->where('no_mesin', $cleansedNoMesin)->whereIn('status', ['queued', 'processing'])->first();

        if ($existing) {
            return response()->json([
                'message'  => 'BPKB dengan nomor mesin ini sudah dalam antrian.',
                'track_id' => $existing->id,
            ], 409);
        }

        $track = BpkbProcessTrack::create([
            'no_mesin'          => $cleansedNoMesin,
            'no_bpkb'           => $cleansedNoBpkb,
            'nama_konsumen'     => $stokUnit?->nm_customer,
            'fk_dealer'         => $stokUnit?->fk_dealer,
            'tgl_mohon_samsat'  => $bastd?->tgl_mohon_samsat ? Carbon::parse($bastd->tgl_mohon_samsat)->toDateString() : null,
            'image_paths'       => $imagePaths,
            'stage'             => 'pending',
            'status'            => 'queued',
            'akurasi'           => $akurasi,
        ]);

        ProcessBpkbJob::dispatch($track);

        return response()->json([
            'message'           => 'BPKB sedang diproses.',
            'track_id'          => $track->id,
            // 'no_mesin_cleansed' => $cleansedNoMesin,
            // 'no_bpkb_cleansed'  => $cleansedNoBpkb,
        ], 202);
    }

    /**
     * Function untuk melakukan cleansing no_bpkb dari request.
     *
     * Contoh Input : "W02371856-BPKB POLRI 2025"
     * Result Output: "W-02371856"
     *
     * @param string $rawNoBpkb
     * @return string
     */
    public function cleansingNoBpkb(string $rawNoBpkb): string
    {
        $rawNoBpkb = strtoupper(trim($rawNoBpkb));

        // Mengambil kode utama BPKB (huruf pertama + '-' + angka/kode serial)
        if (preg_match('/^([A-Z])\s*\-?\s*([0-9A-Z]+)/i', $rawNoBpkb, $matches)) {
            return strtoupper($matches[1] . '-' . $matches[2]);
        }

        return $rawNoBpkb;
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
        return $this->cleansingNoMesinWithDetails($rawNoMesin)['cleansed'];
    }

    /**
     * Cleansing nomor mesin dengan detail akurasi Levenshtein Distance.
     *
     * Mengembalikan array berisi:
     * - original: nomor mesin asli (setelah uppercase/trim)
     * - cleansed: nomor mesin hasil cleansing
     * - prefix_original / prefix_cleansed: detail prefix
     * - serial_original / serial_cleansed: detail serial
     * - levenshtein_distance: jarak edit prefix terhadap DB
     * - serial_corrections: jumlah digit serial yang dikoreksi
     * - prefix_accuracy_pct: persen akurasi prefix (Levenshtein)
     * - serial_accuracy_pct: persen akurasi serial (char mapping)
     * - overall_accuracy_pct: persen akurasi keseluruhan
     *
     * @param string $rawNoMesin
     * @return array
     */
    public function cleansingNoMesinWithDetails(string $rawNoMesin): array
    {
        $rawNoMesin = strtoupper(trim($rawNoMesin));

        $result = [
            'original'             => $rawNoMesin,
            'cleansed'             => $rawNoMesin,
            'prefix_original'      => null,
            'prefix_cleansed'      => null,
            'prefix_matched_from'  => null,
            'levenshtein_distance' => 0,
            'prefix_accuracy_pct'  => 100.0,
            'serial_original'      => null,
            'serial_cleansed'      => null,
            'serial_corrections'   => 0,
            'serial_accuracy_pct'  => 100.0,
            'overall_accuracy_pct' => 100.0,
        ];

        if (strlen($rawNoMesin) < 12) {
            return $result;
        }

        $rawPrefix = substr($rawNoMesin, 0, 5);
        $rawSerial = substr($rawNoMesin, 5, 7);

        // 1. Cleansing prefix dengan info distance
        $prefixInfo = $this->getClosestPrefixWithDistance($rawPrefix);
        $cleansedPrefix = $prefixInfo['prefix'];
        $prefixDistance = $prefixInfo['distance'];

        // 2. Cleansing serial
        $cleansedSerial = $this->cleansingSerialDigits($rawSerial);
        $serialCorrections = 0;
        $serialLen = strlen($rawSerial);
        for ($i = 0; $i < $serialLen; $i++) {
            if ($rawSerial[$i] !== $cleansedSerial[$i]) {
                $serialCorrections++;
            }
        }

        // 3. Gabungkan
        $suffix = strlen($rawNoMesin) > 12 ? substr($rawNoMesin, 12) : '';
        $cleansed = $cleansedPrefix . $cleansedSerial . $suffix;

        // 4. Hitung akurasi
        $prefixLen = max(strlen($rawPrefix), 1);
        $prefixAccuracy = round((1 - $prefixDistance / $prefixLen) * 100, 2);

        $serialLenSafe = max($serialLen, 1);
        $serialAccuracy = round((1 - $serialCorrections / $serialLenSafe) * 100, 2);

        $totalLen = max($prefixLen + $serialLen, 1);
        $totalChanges = $prefixDistance + $serialCorrections;
        $overallAccuracy = round((1 - $totalChanges / $totalLen) * 100, 2);

        $result['cleansed']             = $cleansed;
        $result['prefix_original']      = $rawPrefix;
        $result['prefix_cleansed']      = $cleansedPrefix;
        $result['prefix_matched_from']  = $prefixInfo['source'];
        $result['levenshtein_distance'] = $prefixDistance;
        $result['prefix_accuracy_pct']  = $prefixAccuracy;
        $result['serial_original']      = $rawSerial;
        $result['serial_cleansed']      = $cleansedSerial;
        $result['serial_corrections']   = $serialCorrections;
        $result['serial_accuracy_pct']  = $serialAccuracy;
        $result['overall_accuracy_pct'] = $overallAccuracy;

        return $result;
    }

    /**
     * Mencari prefix 5 digit paling mirip dari database pgsql_nms table public.tbltipe_kendaraan
     */
    private function getClosestPrefixFromDb(string $rawPrefix): string
    {
        return $this->getClosestPrefixWithDistance($rawPrefix)['prefix'];
    }

    /**
     * Mencari prefix 5 digit paling mirip + mengembalikan Levenshtein distance dan sumber match.
     *
     * @return array{prefix: string, distance: int, source: string}
     */
    private function getClosestPrefixWithDistance(string $rawPrefix): array
    {
        try {
            $prefixes = DB::connection('pgsql_nms')
                ->table('public.tbltipe_kendaraan')
                ->whereNotNull('digit_no_mesin')
                ->select(DB::raw("DISTINCT UPPER(LEFT(digit_no_mesin, 5)) as prefix"))
                ->pluck('prefix')
                ->filter()
                ->toArray();

            if (empty($prefixes)) {
                return ['prefix' => $rawPrefix, 'distance' => 0, 'source' => 'fallback_empty_db'];
            }

            $bestMatch = $rawPrefix;
            $minDistance = -1;

            foreach ($prefixes as $validPrefix) {
                $distance = levenshtein($rawPrefix, $validPrefix);

                if ($distance === 0) {
                    return ['prefix' => $validPrefix, 'distance' => 0, 'source' => 'exact_match'];
                }

                if ($minDistance === -1 || $distance < $minDistance) {
                    $minDistance = $distance;
                    $bestMatch = $validPrefix;
                }
            }

            if ($minDistance >= 0 && $minDistance <= 2) {
                return ['prefix' => $bestMatch, 'distance' => $minDistance, 'source' => 'levenshtein_match'];
            }

            return ['prefix' => $rawPrefix, 'distance' => $minDistance, 'source' => 'fallback_too_far'];
        } catch (\Throwable $e) {
            return ['prefix' => $rawPrefix, 'distance' => 0, 'source' => 'fallback_error'];
        }
    }

    /**
     * Melakukan cleansing 7 digit serial number (mengubah karakter typo OCR/keyboard ke angka)
     */
    private function cleansingSerialDigits(string $serial): string
    {
        $charToDigitMap = [
            'O' => '0',
            'o' => '0',
            'I' => '1',
            'i' => '1',
            'L' => '1',
            'l' => '1',
            'Z' => '2',
            'z' => '2',
            'E' => '3',
            'e' => '3',
            'A' => '4',
            'a' => '4',
            'S' => '5',
            's' => '5',
            'T' => '7',
            't' => '7',
            'B' => '8',
            'b' => '8',
            'G' => '9',
            'g' => '9',
            'Q' => '9',
            'q' => '9',
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
                        'pdf_url'      => $track->pdf_path ? route('bpkb.download', $track->id) : null,
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

    public function download(Request $request, BpkbProcessTrack $track)
    {
        $request->merge(['disposition' => 'attachment']);
        return $this->getFile($request, $track);
    }

    /**
     * API untuk mengambil/download file BPKB PDF dari secure storage path.
     * Mendukung pencarian berdasarkan:
     * - Parameter {track} (UUID)
     * - Query param track_id (UUID)
     * - Query param nomesin (Nomor Mesin)
     * - Query param nobpkb (Nomor BPKB)
     * - Query param path / filename
     */
    public function getFile(Request $request, ?BpkbProcessTrack $track = null)
    {
        $targetTrack = ($track && $track->exists) ? $track : null;

        // 1. Berdasarkan track_id di query/body jika model binding tidak ada
        if (!$targetTrack && $request->filled('track_id')) {
            $targetTrack = BpkbProcessTrack::find($request->input('track_id'));
        }

        // 2. Berdasarkan nomesin jika belum ketemu
        if (!$targetTrack && $request->filled('nomesin')) {
            $cleansedNoMesin = $this->cleansingNoMesin($request->input('nomesin'));
            $targetTrack = BpkbProcessTrack::where('no_mesin', $cleansedNoMesin)
                ->orderByRaw("CASE WHEN status = 'completed' THEN 1 WHEN status = 'processing' THEN 2 ELSE 3 END")
                ->latest()
                ->first();
        }

        // 3. Berdasarkan nobpkb jika belum ketemu
        if (!$targetTrack && $request->filled('nobpkb')) {
            $cleansedNoBpkb = $this->cleansingNoBpkb($request->input('nobpkb'));
            $targetTrack = BpkbProcessTrack::where('no_bpkb', $cleansedNoBpkb)
                ->orderByRaw("CASE WHEN status = 'completed' THEN 1 WHEN status = 'processing' THEN 2 ELSE 3 END")
                ->latest()
                ->first();
        }

        $filePath = null;
        $downloadName = null;

        if ($targetTrack) {
            $filePath = $targetTrack->pdf_path;
            $downloadName = ($targetTrack->nama_konsumen ?: $targetTrack->no_bpkb ?: 'bpkb_' . $targetTrack->id) . '.pdf';
        }

        // 4. Jika diberikan direct path atau filename
        if (!$filePath && $request->filled('filename')) {
            $filePath = 'bpkb/pdf/' . ltrim($request->input('filename'), '/\\');
            $downloadName = basename($filePath);
        } elseif (!$filePath && $request->filled('path')) {
            $filePath = ltrim($request->input('path'), '/\\');
            $downloadName = basename($filePath);
        }

        // Cek jika request meminta versi tanpa watermark (clean)
        $wantClean = $request->boolean('without_wm') || $request->boolean('clean') || $request->query('watermark') === '0' || $request->query('watermark') === 'false';
        if ($wantClean && $filePath) {
            $cleanCandidate = 'bpkb/pdf/clean/' . basename($filePath);
            if (Storage::disk('secure')->exists($cleanCandidate)) {
                $filePath = $cleanCandidate;
            }
        }

        // Jika targetTrack ditemukan tapi status belum selesai
        if ($targetTrack && $targetTrack->status !== 'completed') {
            $statusMessages = [
                'queued'     => 'Proses BPKB masih dalam antrian. File PDF belum selesai di-generate.',
                'processing' => 'Proses BPKB sedang berlangsung (tahap: ' . ($targetTrack->stage ?? 'proses') . '). Silakan coba beberapa saat lagi.',
                'failed'     => 'Proses pembuatan BPKB gagal: ' . ($targetTrack->error_message ?? 'Terjadi kesalahan pada worker antrian.'),
            ];

            return response()->json([
                'status'        => false,
                'code'          => 'PROCESS_' . strtoupper($targetTrack->status),
                'message'       => $statusMessages[$targetTrack->status] ?? 'File BPKB belum siap diunduh.',
                'track_id'      => (string) $targetTrack->id,
                'stage'         => $targetTrack->stage,
                'track_status'  => $targetTrack->status,
                'error_message' => $targetTrack->error_message,
            ], 422);
        }

        // Resolusi lokasi file fisik (termasuk fallback jika path berbeda separator / direktori)
        $resolvedDisk = null;
        $normalizedPath = null;

        if ($filePath) {
            $normalizedPath = str_replace('\\', '/', ltrim($filePath, '/\\'));

            if (Storage::disk('secure')->exists($normalizedPath)) {
                $resolvedDisk = 'secure';
            } elseif (Storage::disk('local')->exists($normalizedPath)) {
                $resolvedDisk = 'local';
            } elseif (Storage::disk('public')->exists($normalizedPath)) {
                $resolvedDisk = 'public';
            }
        }

        // Jika file fisik tidak ditemukan
        if (!$normalizedPath || !$resolvedDisk) {
            return response()->json([
                'status'        => false,
                'code'          => 'FILE_NOT_FOUND',
                'message'       => 'File BPKB tidak ditemukan pada storage path secure.',
                'track_id'      => $targetTrack ? (string) $targetTrack->id : null,
                'expected_path' => $filePath,
                'storage_root'  => config('filesystems.disks.secure.root'),
                'suggestion'    => $targetTrack ? 'Pastikan antrian queue worker (php artisan queue:work) telah berjalan dan file tersimpan di storage path.' : 'Periksa kembali parameter pencarian no_mesin, no_bpkb, atau track_id.',
            ], 404);
        }

        $fullPath = Storage::disk($resolvedDisk)->path($normalizedPath);

        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return response()->json([
                'status'        => false,
                'code'          => 'FILE_NOT_FOUND',
                'message'       => 'File BPKB tidak ditemukan pada storage path disk ' . $resolvedDisk . '.',
                'track_id'      => $targetTrack ? (string) $targetTrack->id : null,
                'expected_path' => $filePath,
                'full_path'     => $fullPath,
            ], 404);
        }

        // Coba perbaiki permission jika file belum readable oleh web server
        if (!is_readable($fullPath)) {
            @chmod($fullPath, 0664);
            clearstatcache(true, $fullPath);
        }

        if (!is_readable($fullPath)) {
            return response()->json([
                'status'        => false,
                'code'          => 'FILE_PERMISSION_DENIED',
                'message'       => 'File BPKB ditemukan di server tetapi tidak memiliki izin baca (permission denied) oleh web server.',
                'track_id'      => $targetTrack ? (string) $targetTrack->id : null,
                'file_path'     => $fullPath,
                'suggestion'    => 'Jalankan perintah chmod 664 atau chown www-data:www-data pada file/direktori storage di server.',
            ], 500);
        }

        if (filesize($fullPath) === 0) {
            return response()->json([
                'status'        => false,
                'code'          => 'FILE_EMPTY',
                'message'       => 'File BPKB ditemukan tetapi ukurannya 0 byte (kosong). Silakan proses ulang dokumen.',
                'track_id'      => $targetTrack ? (string) $targetTrack->id : null,
                'file_path'     => $fullPath,
            ], 422);
        }

        $mimeType = Storage::disk($resolvedDisk)->mimeType($normalizedPath) ?: 'application/pdf';
        $headers = [
            'Content-Type'   => $mimeType,
            'Content-Length' => (string) filesize($fullPath),
        ];

        $disposition = $request->query('disposition', 'inline');
        if ($disposition === 'attachment' || $request->boolean('download')) {
            return response()->download($fullPath, $downloadName, $headers);
        }

        return response()->file($fullPath, $headers);
    }


    /**
     * API untuk mengambil file BPKB PDF versi bersih (Tanpa Watermark / Remove Watermark).
     */
    public function getCleanFile(Request $request, ?BpkbProcessTrack $track = null)
    {
        $request->merge(['clean' => 1]);
        return $this->getFile($request, $track);
    }

    /**
     * API untuk mengambil metadata / informasi data BPKB dari storage path.
     */
    public function getFileInfo(Request $request, ?BpkbProcessTrack $track = null): JsonResponse
    {
        $targetTrack = ($track && $track->exists) ? $track : null;

        if (!$targetTrack && $request->filled('track_id')) {
            $targetTrack = BpkbProcessTrack::find($request->input('track_id'));
        }

        if (!$targetTrack && $request->filled('nomesin')) {
            $cleansed = $this->cleansingNoMesin($request->input('nomesin'));
            $targetTrack = BpkbProcessTrack::where('no_mesin', $cleansed)->latest()->first();
        }

        if (!$targetTrack && $request->filled('nobpkb')) {
            $cleansed = $this->cleansingNoBpkb($request->input('nobpkb'));
            $targetTrack = BpkbProcessTrack::where('no_bpkb', $cleansed)->latest()->first();
        }

        if (!$targetTrack) {
            return response()->json([
                'status'  => false,
                'code'    => 'TRACK_NOT_FOUND',
                'message' => 'Data tracking BPKB tidak ditemukan pada database.',
            ], 404);
        }

        $normalizedPath = $targetTrack->pdf_path ? str_replace('\\', '/', ltrim($targetTrack->pdf_path, '/\\')) : null;
        $fileExists = $normalizedPath && (Storage::disk('secure')->exists($normalizedPath) || Storage::disk('local')->exists($normalizedPath) || Storage::disk('public')->exists($normalizedPath));
        $resolvedDisk = $fileExists ? (Storage::disk('secure')->exists($normalizedPath) ? 'secure' : (Storage::disk('local')->exists($normalizedPath) ? 'local' : 'public')) : null;

        $fileSizeBytes = ($fileExists && $resolvedDisk) ? Storage::disk($resolvedDisk)->size($normalizedPath) : 0;
        $lastModified = ($fileExists && $resolvedDisk) ? date('Y-m-d H:i:s', Storage::disk($resolvedDisk)->lastModified($normalizedPath)) : null;

        return response()->json([
            'status' => true,
            'data'   => [
                'track_id'             => $targetTrack->id,
                'no_mesin'             => $targetTrack->no_mesin,
                'no_bpkb'              => $targetTrack->no_bpkb,
                'nama_konsumen'        => $targetTrack->nama_konsumen,
                'fk_dealer'            => $targetTrack->fk_dealer,
                'nm_dealer'            => $targetTrack->nm_dealer,
                'tgl_mohon_samsat'     => $targetTrack->tgl_mohon_samsat ? Carbon::parse($targetTrack->tgl_mohon_samsat)->toDateString() : null,
                'stage'                => $targetTrack->stage,
                'status'               => $targetTrack->status,
                'error_message'        => $targetTrack->error_message,
                'akurasi'              => $targetTrack->akurasi,
                'pdf_path'             => $targetTrack->pdf_path,
                'file_saved_on_server' => $fileExists,
                'file_size_bytes'      => $fileSizeBytes,
                'file_size_formatted'  => $fileExists ? $this->formatBytes($fileSizeBytes) : null,
                'last_modified'        => $lastModified,
                'file_url'             => $fileExists ? url('/api/bpkb/file/' . $targetTrack->id) : null,
                'download_url'         => $fileExists ? url('/api/bpkb/file/' . $targetTrack->id . '?disposition=attachment') : null,
            ]
        ]);
    }

    public function activity(): JsonResponse
    {
        $monthNow = now()->format('Y-m');
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

    public function activityToday(): JsonResponse
    {
        $count = BpkbProcessTrack::where('status', 'completed')
            ->whereDate('created_at', today())
            ->count();

        $target = (int) config('bpkb.daily_target', 100);

        return response()->json([
            'count'  => $count,
            'target' => $target,
            'date'   => now()->toDateString(),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 5);
        if ($limit <= 0) {
            $limit = 5;
        }

        $tracks = BpkbProcessTrack::orderBy('created_at', 'desc')
            ->take($limit)
            ->get();

        $data = $tracks->map(function (BpkbProcessTrack $track) {
            return [
                'track_id'   => (string) $track->id,
                'nobpkb'     => (string) $track->no_bpkb,
                'nomesin'    => (string) $track->no_mesin,
                'status'     => (string) $track->status,
                'created_at' => $track->created_at ? (is_string($track->created_at) ? $track->created_at : $track->created_at->format('Y-m-d H:i:s')) : null,
            ];
        });

        return response()->json($data);
    }

    /**
     * API Monitoring BPKB yang sudah diproses dan tersimpan di secure storage server.
     *
     * Filter yang didukung:
     * - search: mencari di no_mesin, no_bpkb, nama_konsumen, atau track_id
     * - status: completed, processing, queued, failed
     * - stage: create_pdf, update_stok_unit, update_tgl_bpkb_siap, completed
     * - date: YYYY-MM-DD
     * - start_date & end_date: YYYY-MM-DD
     * - month: YYYY-MM
     * - has_file: true/false (hanya yang memiliki file PDF di server)
     * - per_page: jumlah data per halaman (default: 15, max: 100)
     * - page: nomor halaman
     * - sort_by: created_at, updated_at, nama_konsumen, no_mesin, no_bpkb (default: created_at)
     * - sort_direction: asc / desc (default: desc)
     */
    public function monitoring(Request $request): JsonResponse
    {
        $query = BpkbProcessTrack::query();

        // Search
        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $matchedDealerCodes = [];
            try {
                $matchedDealerCodes = DB::connection('pgsql_nms')
                    ->table('public.tbldealer')
                    ->where('nm_dealer', 'ilike', "%{$search}%")
                    ->orWhere('kd_dealer_md', 'ilike', "%{$search}%")
                    ->pluck('kd_dealer_md')
                    ->toArray();
            } catch (\Throwable $e) {
                $matchedDealerCodes = [];
            }

            $query->where(function ($q) use ($search, $matchedDealerCodes) {
                $q->where('no_mesin', 'like', "%{$search}%")
                  ->orWhere('no_bpkb', 'like', "%{$search}%")
                  ->orWhere('nama_konsumen', 'like', "%{$search}%")
                  ->orWhere('id', 'like', "%{$search}%")
                  ->orWhere('fk_dealer', 'like', "%{$search}%");

                if (!empty($matchedDealerCodes)) {
                    $q->orWhereIn('fk_dealer', $matchedDealerCodes);
                }
            });
        }

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter stage
        if ($request->filled('stage')) {
            $query->where('stage', $request->input('stage'));
        }

        // Filter tanggal spesifik
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        // Filter range tanggal
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        // Filter bulan (format: YYYY-MM)
        if ($request->filled('month')) {
            $query->where('created_at', 'like', $request->input('month') . '%');
        }

        // Filter hanya yang memiliki file PDF
        if ($request->boolean('has_file')) {
            $query->whereNotNull('pdf_path');
        }

        // Filter dealer
        if ($request->filled('fk_dealer')) {
            $query->where('fk_dealer', $request->input('fk_dealer'));
        }

        // Ringkasan statistik (Stats)
        $stats = [
            'total_all'        => (clone $query)->count(),
            'total_completed'  => (clone $query)->where('status', 'completed')->count(),
            'total_processing' => (clone $query)->where('status', 'processing')->count(),
            'total_queued'     => (clone $query)->where('status', 'queued')->count(),
            'total_failed'     => (clone $query)->where('status', 'failed')->count(),
            'total_with_pdf'   => (clone $query)->whereNotNull('pdf_path')->count(),
        ];

        // Sorting
        $allowedSort = ['created_at', 'updated_at', 'nama_konsumen', 'no_mesin', 'no_bpkb', 'fk_dealer', 'tgl_mohon_samsat'];
        $sortBy = in_array($request->query('sort_by'), $allowedSort) ? $request->query('sort_by') : 'created_at';
        $sortDir = strtolower($request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $paginator = $query->paginate($perPage);

        // Ambil nama dealer dari DB pgsql_nms -> public.tbldealer
        $dealerCodes = collect($paginator->items())->pluck('fk_dealer')->filter()->unique()->values()->toArray();
        $dealerNames = [];
        if (!empty($dealerCodes)) {
            try {
                $dealerNames = DB::connection('pgsql_nms')
                    ->table('public.tbldealer')
                    ->whereIn('kd_dealer_md', $dealerCodes)
                    ->pluck('nm_dealer', 'kd_dealer_md')
                    ->toArray();
            } catch (\Throwable $e) {
                $dealerNames = [];
            }
        }

        $items = collect($paginator->items())->map(function (BpkbProcessTrack $track) use ($dealerNames) {
            $fileExists = $track->pdf_path && Storage::disk('secure')->exists($track->pdf_path);
            $fileSizeBytes = $fileExists ? Storage::disk('secure')->size($track->pdf_path) : 0;
            $fileSizeFormatted = $fileExists ? $this->formatBytes($fileSizeBytes) : null;
            $lastModified = $fileExists ? date('Y-m-d H:i:s', Storage::disk('secure')->lastModified($track->pdf_path)) : null;

            return [
                'track_id'             => (string) $track->id,
                'no_mesin'             => (string) $track->no_mesin,
                'no_bpkb'              => (string) $track->no_bpkb,
                'nama_konsumen'        => $track->nama_konsumen,
                'fk_dealer'            => $track->fk_dealer,
                'nm_dealer'            => $track->fk_dealer ? ($dealerNames[$track->fk_dealer] ?? null) : null,
                'tgl_mohon_samsat'     => $track->tgl_mohon_samsat ? Carbon::parse($track->tgl_mohon_samsat)->toDateString() : null,
                'stage'                => $track->stage,
                'status'               => $track->status,
                'error_message'        => $track->error_message,
                'akurasi'              => $track->akurasi,
                'pdf_path'             => $track->pdf_path,
                'file_saved_on_server' => $fileExists,
                'file_size_bytes'      => $fileSizeBytes,
                'file_size_formatted'  => $fileSizeFormatted,
                'file_last_modified'   => $lastModified,
                'file_url'             => $fileExists ? url('/api/bpkb/file/' . $track->id) : null,
                'download_url'         => $fileExists ? url('/api/bpkb/file/' . $track->id . '?disposition=attachment') : null,
                'created_at'           => $track->created_at ? (is_string($track->created_at) ? $track->created_at : $track->created_at->format('Y-m-d H:i:s')) : null,
                'updated_at'           => $track->updated_at ? (is_string($track->updated_at) ? $track->updated_at : $track->updated_at->format('Y-m-d H:i:s')) : null,
            ];
        });

        return response()->json([
            'status'     => true,
            'stats'      => $stats,
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
            'data'       => $items,
        ]);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Ambil status pengaturan efek scan dokumen
     */
    public function getScanEffectSetting(): JsonResponse
    {
        return response()->json([
            'status'     => true,
            'enabled'    => \App\Models\BpkbSetting::isScanEffectEnabled(),
            'contrast'   => \App\Models\BpkbSetting::getScanContrast(),
            'brightness' => \App\Models\BpkbSetting::getScanBrightness(),
        ]);
    }

    /**
     * Aktifkan atau nonaktifkan efek scan dokumen
     */
    public function updateScanEffectSetting(Request $request): JsonResponse
    {
        $enabled = $request->boolean('enabled');
        if ($request->has('toggle')) {
            $enabled = !\App\Models\BpkbSetting::isScanEffectEnabled();
        }

        \App\Models\BpkbSetting::setScanEffect($enabled);

        if ($request->filled('contrast')) {
            \App\Models\BpkbSetting::updateOrCreate(
                ['key' => 'scan_contrast'],
                ['value' => (string) $request->input('contrast')]
            );
        }

        if ($request->filled('brightness')) {
            \App\Models\BpkbSetting::updateOrCreate(
                ['key' => 'scan_brightness'],
                ['value' => (string) $request->input('brightness')]
            );
        }

        return response()->json([
            'status'     => true,
            'message'    => 'Pengaturan efek scan dokumen berhasil diperbarui.',
            'enabled'    => \App\Models\BpkbSetting::isScanEffectEnabled(),
            'contrast'   => \App\Models\BpkbSetting::getScanContrast(),
            'brightness' => \App\Models\BpkbSetting::getScanBrightness(),
        ]);
    }

    /**
     * Ambil status persentase / kualitas kompresi PDF
     */
    public function getCompressSetting(): JsonResponse
    {
        $quality = \App\Models\BpkbSetting::getPdfCompressQuality();

        return response()->json([
            'status'           => true,
            'quality'          => $quality,
            'quality_percent'  => $quality . '%',
            'max_target_width' => 1000,
            'description'      => 'Kualitas kompresi gambar PDF BPKB (10% - 100%). Nilai lebih kecil menghasilkan file lebih ringan.',
        ]);
    }

    /**
     * Perbarui persentase / kualitas kompresi PDF
     */
    public function updateCompressSetting(Request $request): JsonResponse
    {
        // Fleksibel menerima input: quality, percent, persen, compression, nilai
        $inputVal = $request->input('quality')
            ?? $request->input('percent')
            ?? $request->input('persen')
            ?? $request->input('compression')
            ?? $request->input('nilai');

        if ($inputVal === null || !is_numeric($inputVal)) {
            return response()->json([
                'status'  => false,
                'message' => 'Parameter persen kompresi (quality / persen) wajib diisi dengan angka 10 - 100.',
            ], 422);
        }

        $quality = (int) $inputVal;
        if ($quality < 10 || $quality > 100) {
            return response()->json([
                'status'  => false,
                'message' => 'Nilai persen kompresi harus berada dalam rentang 10 sampai 100.',
            ], 422);
        }

        \App\Models\BpkbSetting::setPdfCompressQuality($quality);

        return response()->json([
            'status'          => true,
            'message'         => 'Pengaturan persen kompresi PDF berhasil disimpan.',
            'quality'         => $quality,
            'quality_percent' => $quality . '%',
        ]);
    }

    /**
     * Mengulang (retry) proses BPKB yang gagal.
     * Mendukung pencarian berdasarkan:
     * - Route parameter {track} (UUID)
     * - Query/Body track_id (UUID)
     * - Query/Body nomesin (Nomor Mesin)
     * - Query/Body nobpkb (Nomor BPKB)
     *
     * Mendukung upload file gambar baru jika sebelumnya gagal karena gambar hilang/rusak.
     */
    public function retry(Request $request, ?BpkbProcessTrack $track = null): JsonResponse
    {
        $targetTrack = ($track && $track->exists) ? $track : null;

        if (!$targetTrack && $request->filled('track_id')) {
            $targetTrack = BpkbProcessTrack::find($request->input('track_id'));
        }

        if (!$targetTrack && $request->filled('nomesin')) {
            $cleansedNoMesin = $this->cleansingNoMesin($request->input('nomesin'));
            $targetTrack = BpkbProcessTrack::where('no_mesin', $cleansedNoMesin)
                ->orderByRaw("CASE WHEN status = 'failed' THEN 1 WHEN status = 'queued' THEN 2 ELSE 3 END")
                ->latest()
                ->first();
        }

        if (!$targetTrack && $request->filled('nobpkb')) {
            $cleansedNoBpkb = $this->cleansingNoBpkb($request->input('nobpkb'));
            $targetTrack = BpkbProcessTrack::where('no_bpkb', $cleansedNoBpkb)
                ->orderByRaw("CASE WHEN status = 'failed' THEN 1 WHEN status = 'queued' THEN 2 ELSE 3 END")
                ->latest()
                ->first();
        }

        if (!$targetTrack) {
            return response()->json([
                'status'  => false,
                'code'    => 'TRACK_NOT_FOUND',
                'message' => 'Data tracking proses BPKB tidak ditemukan.',
            ], 404);
        }

        // Cek jika proses sedang berjalan
        if ($targetTrack->status === 'processing' && !$request->boolean('force')) {
            return response()->json([
                'status'   => false,
                'code'     => 'ALREADY_PROCESSING',
                'message'  => 'Proses BPKB saat ini sedang berjalan (tahap: ' . ($targetTrack->stage ?? 'processing') . '). Gunakan parameter force=true jika ingin memaksa antri ulang.',
                'track_id' => (string) $targetTrack->id,
            ], 409);
        }

        // Cek jika proses sudah selesai sebelumnya
        if ($targetTrack->status === 'completed' && !$request->boolean('force')) {
            return response()->json([
                'status'   => false,
                'code'     => 'ALREADY_COMPLETED',
                'message'  => 'Proses BPKB ini sudah selesai sebelumnya. Gunakan parameter force=true jika ingin memproses ulang dari awal.',
                'track_id' => (string) $targetTrack->id,
            ], 422);
        }

        // Jika request menyertakan gambar baru
        if ($request->hasFile('images')) {
            $newImagePaths = [];
            foreach ($request->file('images') as $image) {
                $newImagePaths[] = $image->store('bpkb/temp', 'public');
            }
            $targetTrack->image_paths = $newImagePaths;
        }

        // Validasi ketersediaan gambar atau file PDF yang sudah digenerate
        $hasImages = !empty($targetTrack->image_paths);
        $hasPdf = !empty($targetTrack->pdf_path) && Storage::disk('secure')->exists($targetTrack->pdf_path);

        if (!$hasImages && !$hasPdf) {
            return response()->json([
                'status'   => false,
                'code'     => 'IMAGES_NOT_FOUND',
                'message'  => 'File gambar BPKB tidak ditemukan pada storage untuk diproses ulang. Silakan sertakan file images pada request.',
                'track_id' => (string) $targetTrack->id,
            ], 422);
        }

        // 1. Cek jika request menyertakan koreksi nomor mesin (nomesin / no_mesin)
        $inputMesin = $request->input('nomesin') ?? $request->input('no_mesin');
        $isMesinChanged = false;

        if (!blank($inputMesin)) {
            $cleansingMesin = $this->cleansingNoMesinWithDetails($inputMesin);
            $newCleansedMesin = $cleansingMesin['cleansed'];

            if ($targetTrack->no_mesin !== $newCleansedMesin) {
                $targetTrack->no_mesin = $newCleansedMesin;
                $targetTrack->akurasi  = $cleansingMesin['overall_accuracy_pct'] . '%';
                $isMesinChanged = true;

                // Ambil ulang nama konsumen & fk_dealer untuk nomor mesin baru
                $stokUnit = StokUnit::select('nm_customer', 'fk_dealer')
                    ->where('no_mesin', $newCleansedMesin)
                    ->first();
                $targetTrack->nama_konsumen = $stokUnit?->nm_customer;
                $targetTrack->fk_dealer     = $stokUnit?->fk_dealer;

                // Ambil ulang tgl_mohon_samsat untuk nomor mesin baru
                $bastd = DB::connection('pgsql_nms')
                    ->table('data_unit.tblbastd_detail')
                    ->where('fk_mesin', $newCleansedMesin)
                    ->select('tgl_mohon_samsat')
                    ->first();
                $targetTrack->tgl_mohon_samsat = $bastd?->tgl_mohon_samsat
                    ? Carbon::parse($bastd->tgl_mohon_samsat)->toDateString()
                    : null;
            }
        }

        // 2. Cek jika request menyertakan koreksi nomor BPKB (nobpkb / no_bpkb)
        $inputBpkb = $request->input('nobpkb') ?? $request->input('no_bpkb');
        if (!blank($inputBpkb)) {
            $newCleansedBpkb = $this->cleansingNoBpkb($inputBpkb);
            if ($targetTrack->no_bpkb !== $newCleansedBpkb) {
                $targetTrack->no_bpkb = $newCleansedBpkb;
            }
        }

        // 3. Pastikan nama konsumen, fk_dealer, dan tgl_mohon_samsat terisi jika sebelumnya masih kosong
        if (!$isMesinChanged) {
            if (empty($targetTrack->nama_konsumen) || empty($targetTrack->fk_dealer)) {
                $stokUnit = StokUnit::select('nm_customer', 'fk_dealer')
                    ->where('no_mesin', $targetTrack->no_mesin)
                    ->first();
                if ($stokUnit) {
                    if (empty($targetTrack->nama_konsumen) && $stokUnit->nm_customer) {
                        $targetTrack->nama_konsumen = $stokUnit->nm_customer;
                    }
                    if (empty($targetTrack->fk_dealer) && $stokUnit->fk_dealer) {
                        $targetTrack->fk_dealer = $stokUnit->fk_dealer;
                    }
                }
            }

            if (empty($targetTrack->tgl_mohon_samsat)) {
                $bastd = DB::connection('pgsql_nms')
                    ->table('data_unit.tblbastd_detail')
                    ->where('fk_mesin', $targetTrack->no_mesin)
                    ->select('tgl_mohon_samsat')
                    ->first();
                if ($bastd?->tgl_mohon_samsat) {
                    $targetTrack->tgl_mohon_samsat = Carbon::parse($bastd->tgl_mohon_samsat)->toDateString();
                }
            }
        }

        // Reset status tracking ke queued
        $targetTrack->stage = 'pending';
        $targetTrack->status = 'queued';
        $targetTrack->error_message = null;
        $targetTrack->save();

        ProcessBpkbJob::dispatch($targetTrack);

        return response()->json([
            'status'   => true,
            'message'  => 'Proses BPKB berhasil diantrikan ulang.',
            'track_id' => (string) $targetTrack->id,
            'data'     => [
                'track_id'         => (string) $targetTrack->id,
                'no_mesin'         => (string) $targetTrack->no_mesin,
                'no_bpkb'          => (string) $targetTrack->no_bpkb,
                'nama_konsumen'    => $targetTrack->nama_konsumen,
                'fk_dealer'        => $targetTrack->fk_dealer,
                'tgl_mohon_samsat' => $targetTrack->tgl_mohon_samsat ? Carbon::parse($targetTrack->tgl_mohon_samsat)->toDateString() : null,
                'stage'            => $targetTrack->stage,
                'status'           => $targetTrack->status,
                'akurasi'          => $targetTrack->akurasi,
            ],
        ], 200);
    }

    /**
     * Mengulang semua proses BPKB yang berstatus gagal (failed).
     */
    public function retryAllFailed(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->input('limit', 50), 200));

        $failedTracks = BpkbProcessTrack::where('status', 'failed')
            ->latest()
            ->take($limit)
            ->get();

        if ($failedTracks->isEmpty()) {
            return response()->json([
                'status'  => true,
                'message' => 'Tidak ada proses BPKB yang berstatus gagal untuk diulang.',
                'count'   => 0,
                'retried' => [],
            ]);
        }

        $retried = [];
        $skipped = [];

        foreach ($failedTracks as $track) {
            $hasImages = !empty($track->image_paths);
            $hasPdf = !empty($track->pdf_path) && Storage::disk('secure')->exists($track->pdf_path);

            if (!$hasImages && !$hasPdf) {
                $skipped[] = [
                    'track_id' => (string) $track->id,
                    'no_mesin' => (string) $track->no_mesin,
                    'reason'   => 'File gambar dan PDF tidak ditemukan di server.',
                ];
                continue;
            }

            if (empty($track->nama_konsumen) || empty($track->fk_dealer)) {
                $stokUnit = StokUnit::select('nm_customer', 'fk_dealer')
                    ->where('no_mesin', $track->no_mesin)
                    ->first();
                if ($stokUnit) {
                    if (empty($track->nama_konsumen) && $stokUnit->nm_customer) {
                        $track->nama_konsumen = $stokUnit->nm_customer;
                    }
                    if (empty($track->fk_dealer) && $stokUnit->fk_dealer) {
                        $track->fk_dealer = $stokUnit->fk_dealer;
                    }
                }
            }

            if (empty($track->tgl_mohon_samsat)) {
                $bastd = DB::connection('pgsql_nms')
                    ->table('data_unit.tblbastd_detail')
                    ->where('fk_mesin', $track->no_mesin)
                    ->select('tgl_mohon_samsat')
                    ->first();
                if ($bastd?->tgl_mohon_samsat) {
                    $track->tgl_mohon_samsat = Carbon::parse($bastd->tgl_mohon_samsat)->toDateString();
                }
            }

            $track->stage = 'pending';
            $track->status = 'queued';
            $track->error_message = null;
            $track->save();

            ProcessBpkbJob::dispatch($track);

            $retried[] = [
                'track_id'      => (string) $track->id,
                'no_mesin'      => (string) $track->no_mesin,
                'no_bpkb'       => (string) $track->no_bpkb,
                'nama_konsumen' => $track->nama_konsumen,
            ];
        }

        return response()->json([
            'status'        => true,
            'message'       => count($retried) . ' proses BPKB gagal berhasil diantrikan ulang.',
            'total_failed'  => $failedTracks->count(),
            'retried_count' => count($retried),
            'skipped_count' => count($skipped),
            'retried'       => $retried,
            'skipped'       => $skipped,
        ]);
    }

    /**
     * API untuk menghitung data BPKB per fk_dealer dan tgl_mohon_samsat
     * beserta detailnya, dikelompokkan berdasarkan fk_dealer.
     *
     * Filter opsional:
     * - fk_dealer: filter kode dealer tertentu
     * - tgl_mohon_samsat / date: filter tanggal mohon samsat (YYYY-MM-DD)
     * - start_date & end_date: filter rentang tanggal mohon samsat
     * - month: filter bulan (YYYY-MM)
     * - status: filter status (completed, processing, queued, failed)
     * - search: pencarian pada no_mesin, no_bpkb, nama_konsumen, atau fk_dealer
     * - include_details: boolean (default: true)
     * - sort_by: total (default) / fk_dealer
     */
    public function byDealer(Request $request): JsonResponse
    {
        $query = BpkbProcessTrack::query();

        // 1. Filter fk_dealer
        if ($request->filled('fk_dealer')) {
            $query->where('fk_dealer', trim($request->input('fk_dealer')));
        }

        // 2. Filter status
        if ($request->filled('status')) {
            $query->where('status', trim($request->input('status')));
        }

        // 3. Filter tanggal spesifik
        $date = $request->input('tgl_mohon_samsat') ?? $request->input('date');
        if ($date) {
            $query->whereDate('tgl_mohon_samsat', $date);
        }

        // 4. Filter rentang tanggal
        if ($request->filled('start_date')) {
            $query->whereDate('tgl_mohon_samsat', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('tgl_mohon_samsat', '<=', $request->input('end_date'));
        }

        // 5. Filter bulan (format: YYYY-MM)
        if ($request->filled('month')) {
            $month = trim($request->input('month'));
            $query->where(function ($q) use ($month) {
                $q->where('tgl_mohon_samsat', 'like', "{$month}%");
            });
        }

        // 6. Search keyword
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('no_mesin', 'like', "%{$search}%")
                  ->orWhere('no_bpkb', 'like', "%{$search}%")
                  ->orWhere('nama_konsumen', 'like', "%{$search}%")
                  ->orWhere('fk_dealer', 'like', "%{$search}%");
            });
        }

        $tracks = $query->orderBy('fk_dealer')
            ->orderByDesc('tgl_mohon_samsat')
            ->orderByDesc('created_at')
            ->get();

        // Ambil nama dealer dari DB pgsql_nms -> public.tbldealer
        $dealerCodes = $tracks->pluck('fk_dealer')->filter()->unique()->values()->toArray();
        $dealerNames = [];
        if (!empty($dealerCodes)) {
            try {
                $dealerNames = DB::connection('pgsql_nms')
                    ->table('public.tbldealer')
                    ->whereIn('kd_dealer_md', $dealerCodes)
                    ->pluck('nm_dealer', 'kd_dealer_md')
                    ->toArray();
            } catch (\Throwable $e) {
                $dealerNames = [];
            }
        }

        $includeDetails = $request->has('include_details') ? $request->boolean('include_details') : true;

        // Kelompokkan per fk_dealer
        $grouped = $tracks->groupBy(function ($item) {
            return $item->fk_dealer ?: 'UNKNOWN';
        });

        $result = [];

        foreach ($grouped as $dealerCode => $dealerTracks) {
            $displayCode = $dealerCode === 'UNKNOWN' ? null : $dealerCode;
            $dealerName = $displayCode ? ($dealerNames[$displayCode] ?? null) : 'Dealer Tidak Diketahui';

            // Kelompokkan per tgl_mohon_samsat di dalam dealer
            $byDateGrouped = $dealerTracks->groupBy(function ($item) {
                return $item->tgl_mohon_samsat ? Carbon::parse($item->tgl_mohon_samsat)->toDateString() : 'Tanpa Tanggal';
            });

            $datesBreakdown = [];
            foreach ($byDateGrouped as $tgl => $itemsOnDate) {
                $dateEntry = [
                    'tgl_mohon_samsat' => $tgl === 'Tanpa Tanggal' ? null : $tgl,
                    'total'            => $itemsOnDate->count(),
                ];

                if ($includeDetails) {
                    $dateEntry['details'] = $itemsOnDate->map(function (BpkbProcessTrack $item) {
                        return [
                            'track_id'         => (string) $item->id,
                            'no_mesin'         => (string) $item->no_mesin,
                            'no_bpkb'          => (string) $item->no_bpkb,
                            'nama_konsumen'    => $item->nama_konsumen,
                            'fk_dealer'        => $item->fk_dealer,
                            'tgl_mohon_samsat' => $item->tgl_mohon_samsat ? Carbon::parse($item->tgl_mohon_samsat)->toDateString() : null,
                            'status'           => $item->status,
                            'stage'            => $item->stage,
                            'pdf_path'         => $item->pdf_path,
                            'file_url'         => $item->pdf_path ? url('/api/bpkb/file/' . $item->id) : null,
                            'created_at'       => $item->created_at ? (is_string($item->created_at) ? $item->created_at : $item->created_at->format('Y-m-d H:i:s')) : null,
                        ];
                    })->values();
                }

                $datesBreakdown[] = $dateEntry;
            }

            $result[] = [
                'fk_dealer' => $displayCode,
                'nm_dealer' => $dealerName,
                'total'     => $dealerTracks->count(),
                'dates'     => $datesBreakdown,
            ];
        }

        // Sorting grup dealer
        $sortBy = $request->query('sort_by', 'total');
        if ($sortBy === 'fk_dealer') {
            usort($result, fn($a, $b) => strcmp($a['fk_dealer'] ?? '', $b['fk_dealer'] ?? ''));
        } else {
            usort($result, fn($a, $b) => $b['total'] <=> $a['total']);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Data berhasil dikelompokkan per fk_dealer dan tgl_mohon_samsat.',
            'summary' => [
                'total_dealers'    => count($result),
                'total_records'    => $tracks->count(),
                'total_completed'  => $tracks->where('status', 'completed')->count(),
                'total_processing' => $tracks->where('status', 'processing')->count(),
                'total_queued'     => $tracks->where('status', 'queued')->count(),
                'total_failed'     => $tracks->where('status', 'failed')->count(),
            ],
            'data'    => $result,
        ]);
    }
}
