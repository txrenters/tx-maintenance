<?php

namespace App\Jobs;

use App\Models\ServiceSchedule;
use App\Services\OwnerAppointmentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOwnerAppointmentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $serviceScheduleId) {}

    public function handle(OwnerAppointmentNotificationService $service): void
    {
        $serviceSchedule = ServiceSchedule::query()->find($this->serviceScheduleId);

        if (! $serviceSchedule) {
            return;
        }

        $service->notify($serviceSchedule);
    }
}
