<?php

namespace App\Jobs;

use App\Services\InspectionApp\HandoffService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A work order was held over the threshold with no approval on record: ask
 * the AI to read the owner's messages now rather than at the next sweep, so
 * an approval that is already in the thread sends the crew within the
 * minute. The sweep is the safety net if this never runs.
 */
class ReadOwnerApprovalForHandoff implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    /** One reading of a row at a time; a second dispatch while one waits is dropped. */
    public int $uniqueFor = 120;

    public function __construct(public readonly int $handoffId) {}

    public function uniqueId(): int
    {
        return $this->handoffId;
    }

    public function handle(HandoffService $service): void
    {
        $service->readAndRelease($this->handoffId);
    }
}
