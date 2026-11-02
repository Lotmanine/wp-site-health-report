<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RunScan;
use App\Models\Scan;
use App\Scanner\Support\BlockedTarget;
use App\Scanner\Support\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController
{
    public function store(Request $request, UrlGuard $guard): JsonResponse
    {
        $data = $request->validate([
            'scan_ref' => ['required', 'string', 'max:64'],
            'url' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $guard->check($data['url']);
        } catch (BlockedTarget $blocked) {
            return response()->json(['error' => $blocked->reason], 422);
        }

        // n8n retries on network errors, so the same reference must not start a second scan.
        $scan = Scan::where('scan_ref', $data['scan_ref'])->first();
        if ($scan === null) {
            $scan = Scan::create($data + ['status' => 'queued']);
            RunScan::dispatch($scan->id);
        }

        return response()->json(['id' => $scan->id, 'status' => $scan->status], 202);
    }

    // Looked up here rather than by route binding, which would hit the database before the key check.
    public function show(string $id): JsonResponse
    {
        $scan = Scan::findOrFail($id);

        return response()->json([
            'id' => $scan->id,
            'status' => $scan->status,
            'result' => $scan->result,
            'error' => $scan->error,
        ]);
    }
}
