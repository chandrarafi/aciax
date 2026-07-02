<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BpkbRequest;
use App\Jobs\ProcessBpkbJob;
use App\Models\BpkbProcessTrack;
use App\Models\StokUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class BpkbController extends Controller
{
    public function process(BpkbRequest $request): JsonResponse
    {
        $valid = $request->validated();

        // Persist images to storage BEFORE dispatching (temp files die with the request)
        $imagePaths = [];
        foreach ($valid['images'] as $image) {
            $path = $image->store('bpkb/temp', 'public');
            $imagePaths[] = $path;
        }

        // Look up customer name
        $stokUnit = StokUnit::select('nm_customer')
            ->where('no_mesin', $valid['nomesin'])
            ->first();

        // Create tracking record
        $track = BpkbProcessTrack::create([
            'no_mesin'       => $valid['nomesin'],
            'no_bpkb'        => $valid['nobpkb'],
            'nama_konsumen'  => $stokUnit?->nm_customer,
            'image_paths'    => $imagePaths,
            'stage'          => 'pending',
            'status'         => 'queued',
        ]);

        // Dispatch queued job
        ProcessBpkbJob::dispatch($track);

        return response()->json([
            'message'   => 'BPKB sedang diproses.',
            'track_id'  => $track->id,
        ], 202);
    }

    public function track(BpkbProcessTrack $track): JsonResponse
    {
        return response()->json($track);
    }
}
