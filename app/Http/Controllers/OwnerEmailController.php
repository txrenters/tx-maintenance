<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendOwnerWorkOrderEmailRequest;
use App\Models\Owner;
use App\Models\OwnerEmailAttachment;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use App\Services\OwnerWorkOrderEmailSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnerEmailController extends Controller
{
    public function __construct(private OwnerWorkOrderEmailSender $sender) {}

    public function index(Owner $owner, Request $request): JsonResponse
    {
        Gate::authorize('view_owner', $owner);

        $workOrderId = $request->integer('work_order_id');
        abort_unless($workOrderId === 0 || $owner->work_orders()->whereKey($workOrderId)->exists(), 404);

        return response()->json([
            ...$this->emails($owner->id, $workOrderId, $request->integer('before_id')),
        ]);
    }

    public function store(SendOwnerWorkOrderEmailRequest $request, Owner $owner): RedirectResponse
    {
        $data = $request->validated();
        $workOrder = isset($data['work_order_id'])
            ? $owner->work_orders()->findOrFail($data['work_order_id'])
            : null;

        $this->send($request, $owner, $workOrder, $data);

        return back();
    }

    public function workOrderIndex(WorkOrder $workOrder, Request $request): JsonResponse
    {
        Gate::authorize('view_owners', Owner::class);

        $ownerId = $request->integer('owner_id');
        abort_unless($ownerId === 0 || $workOrder->owners()->whereKey($ownerId)->exists(), 404);

        return response()->json([
            ...$this->emails($ownerId, $workOrder->id, $request->integer('before_id')),
            'sender_email' => (string) $request->user()->email,
        ]);
    }

    public function workOrderStore(SendOwnerWorkOrderEmailRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validated();
        $owner = $workOrder->owners()->findOrFail($data['owner_id']);

        $this->send($request, $owner, $workOrder, $data);

        return back();
    }

    public function download(OwnerEmailAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view_owners', Owner::class);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    /**
     * @return array{owner_emails: Collection<int, OwnerEmailNotification>, has_more: bool, next_before_id: int|null}
     */
    private function emails(int $ownerId, int $workOrderId, int $beforeId = 0): array
    {
        $emails = OwnerEmailNotification::query()
            ->when($ownerId !== 0, fn ($query) => $query->where('owner_id', $ownerId))
            ->when($workOrderId !== 0, fn ($query) => $query->where('work_order_id', $workOrderId))
            ->when($beforeId !== 0, fn ($query) => $query->where('id', '<', $beforeId))
            ->with(['attachments', 'workOrder:id,work_order_no'])
            ->latest('id')
            ->limit(26)
            ->get();

        $hasMore = $emails->count() > 25;
        $page = $emails->take(25)->values();

        return [
            'owner_emails' => $page,
            'has_more' => $hasMore,
            'next_before_id' => $hasMore ? $page->min('id') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(SendOwnerWorkOrderEmailRequest $request, Owner $owner, ?WorkOrder $workOrder, array $data): void
    {
        $files = array_map(
            fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(),
                'contentType' => $file->getMimeType() ?: 'application/octet-stream',
                'bytes' => (string) $file->get(),
            ],
            $request->file('attachments', []),
        );

        $this->sender->send(
            owner: $owner,
            workOrder: $workOrder,
            to: $data['to'],
            mailbox: $data['from_email'],
            subject: $data['subject'],
            html: $data['body'],
            files: $files,
            sentBy: $request->user(),
        );
    }
}
