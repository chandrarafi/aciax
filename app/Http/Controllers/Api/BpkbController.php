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
                        'error_message'=> $track->error_message,
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
