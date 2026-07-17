<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OwnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_owners', Owner::class);

        $owners = Owner::query()
            ->with('user')
            ->withCount('work_orders')
            ->whereHas('work_orders')
            ->filter(request(['search']))
            ->orderBy('name', 'ASC')
            ->paginate(100)
            ->withQueryString()
            ->through(function ($owner) {
                return [
                    'id' => $owner->id,
                    'name' => $owner->name ?: trim($owner->first_name.' '.$owner->last_name),
                    'email' => $owner->email,
                    'mobile' => $owner->mobile,
                    'phone' => $owner->phone,
                    'name_on_check' => $owner->name_on_check,
                    'company' => $owner->company,
                    'address' => $owner->address ?: $owner->user?->address,
                    'status' => $owner->status,
                    'propertyware_id' => $owner->propertyware_id,
                    'property_work_orders_count' => $owner->work_orders_count,
                ];
            });

        return inertia('Owner/Index', [
            'title' => 'Owners',
            'owners' => $owners,
            'filter' => $request->only(['search', 'per_page']),
        ]);

    }

    public function show(Request $request, Owner $owner)
    {
        Gate::authorize('view_owner', $owner);

        $propertyWorkOrders = $owner->work_orders()
            ->with('service_status')
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($workOrder) => $this->workOrderData($workOrder));

        $emailNotifications = OwnerEmailNotification::query()
            ->where('owner_id', $owner->id)
            ->with(['attachments', 'workOrder:id,work_order_no'])
            ->latest('sent_at')
            ->latest('id')
            ->limit(26)
            ->get();

        $emailHistoryHasMore = $emailNotifications->count() > 25;
        $emailHistory = $emailNotifications
            ->take(25)
            ->values()
            ->map(fn (OwnerEmailNotification $email): array => [
                'id' => $email->id,
                'direction' => $email->direction,
                'subject' => $email->subject,
                'body_html' => $email->body_html,
                'body_text' => $email->body_text,
                'from_email' => $email->from_email,
                'to_email' => $email->to_email,
                'cc' => $email->cc ?? [],
                'sent_at' => $email->sent_at?->toIso8601String(),
                'work_order' => $email->workOrder ? [
                    'id' => $email->workOrder->id,
                    'work_order_no' => $email->workOrder->work_order_no,
                ] : null,
                'attachments' => $email->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ])->values(),
            ]);

        return inertia('Owner/Show', [
            'title' => $owner->name ?: trim($owner->first_name.' '.$owner->last_name),
            'senderEmail' => (string) $request->user()->email,
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name ?: trim($owner->first_name.' '.$owner->last_name),
                'first_name' => $owner->first_name,
                'last_name' => $owner->last_name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'mobile' => $owner->mobile,
                'home_phone' => $owner->home_phone,
                'work_telephone' => $owner->work_telephone,
                'company' => $owner->company,
                'name_on_check' => $owner->name_on_check,
                'address' => $owner->address,
                'address2' => $owner->address2,
                'city' => $owner->city,
                'state' => $owner->state,
                'zip' => $owner->zip,
                'country' => $owner->country,
                'status' => $owner->status,
                'propertyware_id' => $owner->propertyware_id,
                'percentage_ownership' => $owner->percentage_ownership,
                'notes' => $owner->notes,
            ],
            'propertyWorkOrders' => $propertyWorkOrders,
            'emailHistory' => $emailHistory,
            'emailHistoryHasMore' => $emailHistoryHasMore,
        ]);
    }

    public function destroy(Owner $owner)
    {
        Gate::authorize('delete_owner', $owner);

        if ($owner->work_orders()->exists() || $owner->managementWorkOrders()->exists()) {
            throw ValidationException::withMessages([
                'owner' => 'This contact is linked to work orders and cannot be deleted.',
            ]);
        }

        $owner->delete();

        return redirect()->back();
    }

    public function bulkdelete(Request $request)
    {
        Gate::authorize('delete_owner', Owner::class);

        $ownerIds = $request->validate([
            'ownersId' => ['required', 'array'],
            'ownersId.*' => ['integer', 'exists:owners,id'],
        ])['ownersId'];

        $hasLinkedOwners = Owner::query()
            ->whereKey($ownerIds)
            ->where(fn ($query) => $query
                ->whereHas('work_orders')
                ->orWhereHas('managementWorkOrders'))
            ->exists();

        if ($hasLinkedOwners) {
            throw ValidationException::withMessages([
                'owner' => 'One or more selected contacts are linked to work orders and cannot be deleted.',
            ]);
        }

        Owner::query()->whereKey($ownerIds)->delete();

        return redirect()->back();

    }

    /**
     * @return array<string, mixed>
     */
    private function workOrderData(WorkOrder $workOrder): array
    {
        return [
            'id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'description' => $workOrder->description,
            'location' => $workOrder->location,
            'priority' => $workOrder->priority,
            'status' => $workOrder->status,
            'service_status' => $workOrder->service_status?->name,
            'created_date' => $workOrder->created_date,
            'completed_date' => $workOrder->completed_date,
        ];
    }
}
