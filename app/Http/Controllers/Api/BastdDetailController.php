<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BastdDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BastdDetailController extends Controller
{
    /**
     * Ambil data dari tabel data_unit.tblbastd_detail.
     * Mendukung filter:
     * - no_mesin (alias: nomesin, fk_mesin)
     * - fk_bastd
     * - no_bpkb
     * - no_stnk
     * - no_polisi
     * - like: boolean (apakah pencarian no_mesin menggunakan partial match/LIKE)
     * - paginate: boolean (default true)
     * - per_page: integer (default 50, max 500)
     * - limit: integer
     */
    public function index(Request $request): JsonResponse
    {
        $query = BastdDetail::query();

        // 1. Filter no_mesin (mencocokkan kolom fk_mesin)
        $noMesin = $request->input('no_mesin')
            ?? $request->input('nomesin')
            ?? $request->input('fk_mesin');

        if (!blank($noMesin)) {
            $noMesin = trim($noMesin);
            $isLike = $request->boolean('like') || str_contains($noMesin, '%');

            if ($isLike) {
                $searchPattern = str_contains($noMesin, '%') ? $noMesin : "%{$noMesin}%";
                $query->where('fk_mesin', 'ilike', $searchPattern);
            } else {
                $query->where('fk_mesin', $noMesin);
            }
        }

        // 2. Filter opsional lainnya jika dibutuhkan
        if ($request->filled('fk_bastd')) {
            $query->where('fk_bastd', trim($request->input('fk_bastd')));
        }

        if ($request->filled('no_bpkb')) {
            $query->where('no_bpkb', trim($request->input('no_bpkb')));
        }

        if ($request->filled('no_stnk')) {
            $query->where('no_stnk', trim($request->input('no_stnk')));
        }

        if ($request->filled('no_polisi')) {
            $query->where('no_polisi', trim($request->input('no_polisi')));
        }

        // 3. Sorting
        if ($request->filled('sort_by')) {
            $sortBy = $request->input('sort_by');
            $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortDirection);
        }

        // 4. Handle pagination vs get all
        $wantPaginate = $request->has('paginate')
            ? $request->boolean('paginate')
            : !$request->boolean('all');

        // Jika user minta get all tanpa pagination
        if (!$wantPaginate) {
            if ($request->filled('limit')) {
                $limit = min((int) $request->input('limit'), 5000);
                $query->limit($limit);
            } elseif (blank($noMesin) && !$request->filled('fk_bastd')) {
                // Pengaman jika tabel memiliki 300rb+ baris tanpa filter spesifik
                $query->limit(1000);
            }

            $data = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Data tblbastd_detail berhasil diambil',
                'total'   => $data->count(),
                'data'    => $data,
            ]);
        }

        // Pagination
        $perPage = min(max((int) $request->input('per_page', 50), 1), 500);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Data tblbastd_detail berhasil diambil',
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
            'links'   => [
                'prev' => $paginated->previousPageUrl(),
                'next' => $paginated->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Ambil data BASTD detail berdasarkan nomor mesin (fk_mesin).
     */
    public function showByMesin(string $noMesin): JsonResponse
    {
        $records = BastdDetail::where('fk_mesin', trim($noMesin))->get();

        if ($records->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Data tblbastd_detail untuk no_mesin tersebut tidak ditemukan',
                'data'    => [],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data tblbastd_detail berhasil ditemukan',
            'total'   => $records->count(),
            'data'    => $records,
        ]);
    }
}
