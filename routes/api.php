<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BpkbController;
use App\Models\StokUnit;
use Illuminate\Support\Facades\DB;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/get-dealer', function (Request $request) {
    $dealers = DB::connection('pgsql_nms')
        ->table('H1_DOS.stokunit as stokunit')
        ->select(['stokunit.fk_dealer', 'd.nm_dealer',
            DB::raw("CASE WHEN stokunit.bentuk_penjualan = '1' THEN 'Cash' WHEN stokunit.bentuk_penjualan = '2' THEN 'Kredit' ELSE 'Lainnya' END as bentuk_penjualan"),])
        ->join('public.tbldealer as d', 'd.kd_dealer_md', '=', 'stokunit.fk_dealer')
        ->where('stokunit.no_mesin', $request->input('nomesin'))
        ->first();
    return response()->json($dealers);
});

Route::middleware('check-secret-key')->group(function () {
    Route::post('/bpkb/process', [BpkbController::class, 'process']);
    Route::get('/bpkb/recent', [BpkbController::class, 'recent']);
    Route::get('/bpkb/monitoring', [BpkbController::class, 'monitoring']);
    Route::get('/bpkb/track/{track}', [BpkbController::class, 'track']);
    Route::get('/bpkb/track/{track}/stream', [BpkbController::class, 'trackStream']);
    Route::get('/bpkb/file', [BpkbController::class, 'getFile']);
    Route::get('/bpkb/file/{track}', [BpkbController::class, 'getFile']);
    Route::get('/bpkb/file-clean', [BpkbController::class, 'getCleanFile']);
    Route::get('/bpkb/file/{track}/clean', [BpkbController::class, 'getCleanFile']);
    Route::get('/bpkb/remove-watermark', [BpkbController::class, 'getCleanFile']);
    Route::post('/bpkb/remove-watermark', [BpkbController::class, 'getCleanFile']);
    Route::get('/bpkb/file-info', [BpkbController::class, 'getFileInfo']);
    Route::get('/bpkb/file-info/{track}', [BpkbController::class, 'getFileInfo']);
    Route::get('/activity', [BpkbController::class, 'activity']);
    Route::get('/activity/today', [BpkbController::class, 'activityToday']);
    Route::get('/bpkb/settings/scan-effect', [BpkbController::class, 'getScanEffectSetting']);
    Route::post('/bpkb/settings/scan-effect', [BpkbController::class, 'updateScanEffectSetting']);
});
