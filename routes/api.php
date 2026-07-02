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
    $dealers = StokUnit::select(['fk_dealer', 'd.nm_dealer'])
        ->join('public.tbldealer as d', 'd.kd_dealer_md', '=', 'stokunit.fk_dealer')        
        ->where('no_mesin', $request->input('nomesin'))
        ->first();
    return response()->json($dealers);
});

Route::post('/bpkb/process', BpkbController::class . '@process');