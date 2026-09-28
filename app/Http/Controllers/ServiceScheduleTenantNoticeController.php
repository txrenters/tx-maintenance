<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use App\Services\TenantAppointmentNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff "Send tenant text" action on a service schedule card.
 *
 * Setting a schedule asks "Text the tenant now?"; answering No saves the
 * schedule untexted, and this is how the text goes out later. It sends the
 * same appointment text the automatic path would (the THMP Technician Visit
 * Reminder when technicians were ticked), through the same once-per-schedule
 * claim, and keeps the same gates — it only says why when one is in the way
 * instead of quietly doing nothing.
 */
class ServiceScheduleTenantNoticeController extends Controller
{
    public function store(Request $request, ServiceSchedule $serviceSchedule, TenantAppointmentNotificationService $notifications): JsonResponse
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);

        $refusal = $this->refusal($serviceSchedule, $notifications);

        if ($refusal !== null) {
            return response()->json(['error' => $refusal], 422);
        }

        $notifications->notify($serviceSchedule);

        // notify() claims the schedule before sending; a claim that did not
        // land means another request got there first.
        if ($serviceSchedule->fresh()?->tenant_notified_at === null) {
            return response()->json(['error' => 'The tenant text could not be sent. Please try again.'], 422);
        }

        return response()->json(['sent' => true]);
    }

    /**
     * Why this text must not go out, in words for the coordinator, or null
     * when it can.
     */
    private function refusal(ServiceSchedule $serviceSchedule, TenantAppointmentNotificationService $notifications): ?string
    {
        if ($serviceSchedule->tenant_notified_at !== null) {
            return 'The tenant was already texted about this schedule.';
        }

        if ($serviceSchedule->status === 'completed') {
            return 'A completed schedule is not announced.';
        }

        if ($this->isPast($serviceSchedule)) {
            return 'This appointment is already in the past.';
        }

        $reason = $notifications->skipReason($serviceSchedule);

        if ($reason !== null) {
            return match ($reason) {
                TenantAppointmentNotificationService::SKIP_GATE_OFF => 'Tenant appointment texts are switched off.',
                TenantAppointmentNotificationService::SKIP_CANCELLED => 'A cancelled schedule is not announced.',
                TenantAppointmentNotificationService::SKIP_TENANT_MUTED => 'Tenant automation is paused on this work order. Turn it back on to send.',
                default => 'This work order is marked vacant, turnover, re-key or refresh cleaning, or has no lease on file, so tenant messages are muted.',
            };
        }

        // Without a number the automatic path only logs a thread entry; a
        // button that reports "sent" for that would mislead.
        $tenant = $serviceSchedule->work_order?->requested_by;

        if (blank($tenant?->mobile_phone) && blank($tenant?->home_phone)) {
            return 'The tenant has no phone number on file.';
        }

        return null;
    }

    /**
     * An appointment whose last day has ended is not worth announcing.
     */
    private function isPast(ServiceSchedule $serviceSchedule): bool
    {
        $ends = $serviceSchedule->scheduled_end_date ?: $serviceSchedule->scheduled_date;

        if (blank($ends)) {
            return false;
        }

        return Carbon::parse((string) $ends)->endOfDay()->isPast();
    }
}
