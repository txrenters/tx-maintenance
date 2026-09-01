<?php

namespace App\Services\Notifications;

use App\Models\Conversation;
use App\Models\Scopes\ConversationScope;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\StaffActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns a freshly logged activity row into a desktop notification for the
 * people it belongs to — coordinators and admins, and the vendors who have a
 * login of their own.
 *
 * Hooking the activity log rather than each of the nineteen activity() call
 * sites is deliberate. The notification bell already renders these same rows
 * (see NotificationController::fetchNotification), so driving both from one
 * source means the desktop can never show something the bell does not — and
 * no business logic had to be touched to add this.
 *
 * Recipients are resolved per event from config/staff_notifications.php.
 */
class StaffActivityNotifier
{
    /**
     * Never allowed to throw: these fire inside the Twilio webhook and the
     * PropertyWare sync, where an exception would cost the caller its
     * delivery receipt or abort an import mid-run.
     */
    public function handle(Activity $activity): void
    {
        try {
            $this->dispatch($activity);
        } catch (\Throwable $e) {
            Log::error('Staff activity notification failed.', [
                'activity_id' => $activity->id,
                'event' => $activity->event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatch(Activity $activity): void
    {
        if (! config('staff_notifications.enabled')) {
            return;
        }

        // Named logs are records, not staff notifications — the automated-
        // messages ledger and the technician/template audit trails. The bell
        // hides them (NotificationController::fetchNotification), and the
        // promise of this class is that the desktop never shows something
        // the bell does not.
        if ($activity->log_name !== null
            && $activity->log_name !== config('activitylog.default_log_name')) {
            return;
        }

        $rules = config('staff_notifications.events.'.$activity->event);

        if (! $rules) {
            return;
        }

        $staff = $this->recipientsFor($activity, $rules['audience']);

        if ($staff->isNotEmpty()) {
            $workOrderId = $this->workOrderIdFor($activity);

            Notification::send($staff, new StaffActivityNotification($this->payloadFor(
                $activity,
                $rules['priority'],
                $workOrderId ? route('work_orders.show', $workOrderId) : null,
            )));
        }

        if (! ($rules['vendors'] ?? false)) {
            return;
        }

        $vendors = $this->vendorRecipientsFor($activity);

        if ($vendors->isNotEmpty()) {
            // Their own board, not the staff work-order page: that is where a
            // logged-in vendor reads their work orders, and it scopes itself.
            Notification::send($vendors, new StaffActivityNotification($this->payloadFor(
                $activity,
                $rules['priority'],
                route('work_orders.vendor'),
            )));
        }
    }

    /**
     * @return array{event: string, title: string, message: string, url: string|null, priority: string, work_order_id: int|string|null, activity_id: int|string|null}
     */
    private function payloadFor(Activity $activity, string $priority, ?string $url): array
    {
        return [
            'event' => (string) $activity->event,
            'title' => (string) $activity->description,
            // Mirrors the bell's own fallback chain so the two read alike.
            'message' => Str::limit((string) ($activity->properties['message']
                ?? $activity->properties['filename']
                ?? $activity->properties['jobber_error_message']
                ?? $activity->description), 200),
            'url' => $url,
            'priority' => $priority,
            'work_order_id' => $this->workOrderIdFor($activity),
            'activity_id' => $activity->id,
        ];
    }

    /**
     * The vendor users this event legitimately belongs to.
     *
     * Holds exactly the line NotificationController::fetchNotification holds
     * for the bell: a vendor sees their own vendor thread and nothing else.
     * Widening this leaks tenant and owner message bodies to a contractor, so
     * every branch here fails closed.
     *
     * @return Collection<int, User>
     */
    private function vendorRecipientsFor(Activity $activity): Collection
    {
        $vendors = match ($activity->event) {
            'vendor_auto_assigned' => $this->vendorsById($activity->properties['vendor_id'] ?? null),
            'work_order_message_received' => $this->vendorOnUnreadVendorThread($activity),
            default => collect(),
        };

        $users = $vendors->map(fn (Vendor $vendor) => $vendor->user)
            ->filter()
            ->filter(fn (User $user) => $user->hasRole('vendor'));

        if (! config('staff_notifications.notify_causer')) {
            $users = $users->reject(
                fn (User $user) => (int) $user->id === (int) $activity->causer_id
            );
        }

        return $users->unique('id')->values();
    }

    /**
     * The vendor on a message the vendor has not sent themselves.
     *
     * read_by_vendor is the app's own signal for this: ConversationController
     * stores `! $isVendorMessage`, and the vendor portal stores true on the
     * vendor's own outbound message. So an unread vendor-thread message is
     * precisely one sent *to* the vendor.
     *
     * @return Collection<int, Vendor>
     */
    private function vendorOnUnreadVendorThread(Activity $activity): Collection
    {
        if ($activity->subject_type !== (new Conversation)->getMorphClass()) {
            return collect();
        }

        // Deliberately not $activity->subject: ConversationScope narrows that
        // to whoever happens to be authenticated, so a vendor's notification
        // would silently depend on who triggered the event. Who to notify is
        // not a question about the current session.
        $conversation = Conversation::withoutGlobalScope(ConversationScope::class)
            ->find($activity->subject_id);

        if (! $conversation) {
            return collect();
        }

        if ($conversation->conversation_type !== 'vendor' || $conversation->read_by_vendor) {
            return collect();
        }

        // No vendor_id means we cannot say whose thread this is, and guessing
        // from the work order's vendor list would notify every vendor on it.
        return $this->vendorsById($conversation->vendor_id);
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function vendorsById(int|string|null $vendorId): Collection
    {
        return $vendorId ? Vendor::with('user')->whereKey($vendorId)->get() : collect();
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(Activity $activity, string $audience): Collection
    {
        $recipients = match ($audience) {
            'assigned' => $this->assignedCoordinatorFor($activity),
            'woc' => $this->usersWithRole('woc'),
            'admin' => $this->usersWithRole('admin'),
            'staff' => $this->usersWithRole(['woc', 'admin']),
            default => collect(),
        };

        if (! config('staff_notifications.notify_causer')) {
            $recipients = $recipients->reject(
                fn (User $user) => (int) $user->id === (int) $activity->causer_id
            );
        }

        return $recipients->unique('id')->values();
    }

    /**
     * The coordinator on the work order this activity concerns.
     *
     * An unassigned work order falls back to every WOC — nobody owns it yet,
     * and silence would be worse than a shared alert.
     *
     * @return Collection<int, User>
     */
    private function assignedCoordinatorFor(Activity $activity): Collection
    {
        $workOrderId = $this->workOrderIdFor($activity);

        $coordinator = $workOrderId
            ? WorkOrder::with('woc')->find($workOrderId)?->woc
            : null;

        return $coordinator ? collect([$coordinator]) : $this->usersWithRole('woc');
    }

    /**
     * @param  string|array<int, string>  $role
     * @return Collection<int, User>
     */
    private function usersWithRole(string|array $role): Collection
    {
        return User::role($role)->get();
    }

    /**
     * Mirrors the bell: the property first, then the subject it hangs off.
     */
    private function workOrderIdFor(Activity $activity): int|string|null
    {
        return $activity->properties['work_order_id']
            ?? $activity->subject?->work_order_id
            ?? null;
    }
}
