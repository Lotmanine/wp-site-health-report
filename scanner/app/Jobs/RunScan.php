<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Scanner\ScanRunner;
use App\Scanner\Support\BlockedTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public int $backoff = 5;

    public function __construct(public string $scanId) {}

    public function handle(ScanRunner $runner): void
    {
        $scan = Scan::find($this->scanId);
        if ($scan === null || $scan->status === 'complete') {
            return;
        }

        $scan->update(['status' => 'running']);

        try {
            $scan->update(['status' => 'complete', 'result' => $runner->run($scan->url), 'error' => null]);
        } catch (BlockedTarget $blocked) {
            // A blocked target will not change on retry.
            $scan->update(['status' => 'failed', 'error' => $blocked->reason]);
        }

        Scan::where('created_at', '<', now()->subDay())->delete();
    }

    public function failed(Throwable $exception): void
    {
        Scan::whereKey($this->scanId)->update(['status' => 'failed', 'error' => 'scan_error']);
    }
}
