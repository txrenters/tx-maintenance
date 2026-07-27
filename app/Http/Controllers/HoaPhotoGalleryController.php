<?php

namespace App\Http\Controllers;

use App\Models\Attachments;
use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

/**
 * Public before/after photo gallery for a corrected HOA violation. The link is
 * a signed URL embedded in the tenant + owner confirmation email, so no login
 * is required but the URL cannot be forged or tampered with.
 */
class HoaPhotoGalleryController extends Controller
{
    public function show(Request $request, string $workOrder)
    {
        $order = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->with('building:id,propertyware_id,name')
            ->findOrFail($workOrder);

        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $order->id)
            ->where(function ($query) {
                $query->where('uploaded_via_tenant_portal', true)
                    ->orWhereIn('type', ['before', 'after']);
            })
            ->latest()
            ->get(['id', 'title', 'filename', 'filetype', 'type', 'created_at']);

        $shape = fn (Attachments $attachment) => [
            'id' => $attachment->id,
            'title' => $attachment->title,
            'url' => asset('storage/'.$attachment->filename),
            'is_image' => str_starts_with((string) $attachment->filetype, 'image/')
                || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $attachment->filename),
            'created_at' => $attachment->created_at,
        ];

        return inertia('HoaPhotoGallery/Show', [
            'title' => 'HOA Violation Photos — #'.$order->work_order_no,
            'workOrder' => [
                'work_order_no' => $order->work_order_no,
                'property' => $order->building?->name,
            ],
            'before' => $attachments->where('type', '!=', 'after')->map($shape)->values(),
            'after' => $attachments->where('type', 'after')->map($shape)->values(),
        ]);
    }
}
