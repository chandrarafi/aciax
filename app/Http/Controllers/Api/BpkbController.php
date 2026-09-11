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
use Illuminate\Support\Facades\DB;

class BpkbController extends Controller
{
    public function process(BpkbRequest $request): JsonResponse
    {
        $valid = $request->validated();

        // Perform cleansing on requested no_mesin and no_bpkb
        $cleansedNoMesin = $this->cleansingNoMesin($valid['nomesin']);
        $cleansedNoBpkb  = $this->cleansingNoBpkb($valid['nobpkb']);

        $imagePaths = [];
        foreach ($valid['images'] as $image) {
            $path = $image->store('bpkb/temp', 'public');
            $imagePaths[] = $path;
        }

        $stokUnit = StokUnit::select('nm_customer')
            ->where('no_mesin', $cleansedNoMesin)
            ->first();

        $track = BpkbProcessTrack::create([
            'no_mesin'       => $cleansedNoMesin,
            'no_bpkb'        => $cleansedNoBpkb,
            'nama_konsumen'  => $stokUnit?->nm_customer,
            'image_paths'    => $imagePaths,
            'stage'          => 'pending',
            'status'         => 'queued',
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
                'stage'                => $targetTrack->stage,
                'status'               => $targetTrack->status,
                'error_message'        => $targetTrack->error_message,
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
            $query->where(function ($q) use ($search) {
                $q->where('no_mesin', 'like', "%{$search}%")
                  ->orWhere('no_bpkb', 'like', "%{$search}%")
                  ->orWhere('nama_konsumen', 'like', "%{$search}%")
                  ->orWhere('id', 'like', "%{$search}%");
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
        $allowedSort = ['created_at', 'updated_at', 'nama_konsumen', 'no_mesin', 'no_bpkb'];
        $sortBy = in_array($request->query('sort_by'), $allowedSort) ? $request->query('sort_by') : 'created_at';
        $sortDir = strtolower($request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function (BpkbProcessTrack $track) {
            $fileExists = $track->pdf_path && Storage::disk('secure')->exists($track->pdf_path);
            $fileSizeBytes = $fileExists ? Storage::disk('secure')->size($track->pdf_path) : 0;
            $fileSizeFormatted = $fileExists ? $this->formatBytes($fileSizeBytes) : null;
            $lastModified = $fileExists ? date('Y-m-d H:i:s', Storage::disk('secure')->lastModified($track->pdf_path)) : null;

            return [
                'track_id'             => (string) $track->id,
                'no_mesin'             => (string) $track->no_mesin,
                'no_bpkb'              => (string) $track->no_bpkb,
                'nama_konsumen'        => $track->nama_konsumen,
                'stage'                => $track->stage,
                'status'               => $track->status,
                'error_message'        => $track->error_message,
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
}
