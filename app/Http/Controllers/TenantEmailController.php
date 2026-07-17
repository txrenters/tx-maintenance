<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendTenantJobberEmailRequest;
use App\Models\TenantEmailAttachment;
use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Services\TenantJobberEmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantEmailController extends Controller
{
    public function __construct(private TenantJobberEmailSender $sender) {}

    public function index(Tenants $tenant, Request $request): JsonResponse
    {
        Gate::authorize('view_tenant', $tenant);

        $jobberJobId = $request->integer('jobber_job_id');

        abort_unless(
            $jobberJobId === 0 || $tenant->jobberJobs()->whereKey($jobberJobId)->exists(),
            404,
        );

        $emails = TenantEmailNotification::query()
            ->whereBelongsTo($tenant, 'tenant')
            ->when($jobberJobId !== 0, fn ($query) => $query->where('jobber_job_id', $jobberJobId))
            ->with('attachments')
            ->latest('sent_at')
            ->latest('id')
            ->get();

        return response()->json(['tenant_emails' => $emails]);
    }

    public function store(SendTenantJobberEmailRequest $request, Tenants $tenant): RedirectResponse
    {
        $data = $request->validated();
        $job = isset($data['jobber_job_id'])
            ? $tenant->jobberJobs()->findOrFail($data['jobber_job_id'])
            : null;
        $recipient = $data['to'];

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'to' => 'This tenant does not have a valid email address.',
            ]);
        }

        $files = array_map(
            fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(),
                'contentType' => $file->getMimeType() ?: 'application/octet-stream',
                'bytes' => (string) $file->get(),
            ],
            $request->file('attachments', []),
        );

        $this->sender->send(
            tenant: $tenant,
            job: $job,
            to: $recipient,
            subject: $data['subject'],
            html: $data['body'],
            files: $files,
            sentBy: $request->user(),
            mailbox: $data['from_email'],
        );

        return back();
    }

    public function download(TenantEmailAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view_tenants', Tenants::class);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}
