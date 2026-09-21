<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The in-house technician roster: a card grid of profiles, each with the
 * photo the tenant appointment text attaches. Admin + WOC via
 * TechnicianPolicy; every change is written to the activity log so "who
 * edited Kevin's profile" has an answer.
 */
class TechnicianController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Technician::class);

        $technicians = Technician::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return inertia('Technician/Index', [
            'title' => 'Technicians',
            'technicians' => $technicians->map(fn (Technician $technician): array => $this->toPageArray($technician))->values(),
            'roles' => Technician::ROLE_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Technician::class);

        $technician = Technician::create($this->validated($request));

        $this->audit($request, $technician, 'created');

        return back()->with('success', "{$technician->name} added.");
    }

    public function update(Request $request, Technician $technician): RedirectResponse
    {
        Gate::authorize('update', $technician);

        $technician->update($this->validated($request));

        $this->audit($request, $technician, 'updated');

        return back()->with('success', "{$technician->name} saved.");
    }

    public function destroy(Request $request, Technician $technician): RedirectResponse
    {
        Gate::authorize('delete', $technician);

        $technician->delete();

        $this->audit($request, $technician, 'deleted');

        return back()->with('success', "{$technician->name} removed.");
    }

    /**
     * Active technicians for the service-schedule dialog's picker, so the
     * coordinator can name who is going and the tenant's appointment text
     * carries their photo.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Technician::class);

        return response()->json([
            'technicians' => Technician::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Technician $technician): array => [
                    'id' => $technician->id,
                    'name' => $technician->name,
                    'has_photo' => $technician->hasPhoto(),
                ])
                ->values(),
        ]);
    }

    /**
     * Save (or replace) the photo the tenant appointment text attaches. The
     * previous file is left in place on purpose: conversation media rows in
     * already-sent threads point at it, and the thread must keep showing the
     * photo that actually went out.
     *
     * JPEG/PNG only and 2MB at most: these are the types and size US
     * carriers reliably deliver over MMS, so a photo that saves here is a
     * photo Twilio can send.
     */
    public function updatePhoto(Request $request, Technician $technician): RedirectResponse
    {
        Gate::authorize('update', $technician);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $file = $request->file('photo');
        $path = Storage::putFile('technician-photos', $file);

        if ($path === false) {
            return back()->with('error', 'The photo could not be saved. Please try again.');
        }

        $technician->update([
            'photo_path' => $path,
            'photo_content_type' => $file->getMimeType(),
        ]);

        $this->audit($request, $technician, 'updated');

        return back()->with('success', "{$technician->name}'s photo saved.");
    }

    /**
     * Stop attaching a photo for this technician. The file itself is kept —
     * sent threads still reference it (see updatePhoto).
     */
    public function destroyPhoto(Request $request, Technician $technician): RedirectResponse
    {
        Gate::authorize('update', $technician);

        $technician->update([
            'photo_path' => null,
            'photo_content_type' => null,
        ]);

        $this->audit($request, $technician, 'updated');

        return back()->with('success', "{$technician->name}'s photo removed.");
    }

    /**
     * The current photo, for the roster card and profile preview. Staff-only;
     * the tenant-facing copy travels through the signed conversation-media
     * route.
     */
    public function showPhoto(Technician $technician): BinaryFileResponse
    {
        Gate::authorize('viewAny', Technician::class);

        if (! $technician->hasPhoto() || ! Storage::exists($technician->photo_path)) {
            abort(404);
        }

        return response()->file(Storage::path($technician->photo_path), [
            'Content-Type' => $technician->photo_content_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(Technician::ROLES)],
            'is_active' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:200'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPageArray(Technician $technician): array
    {
        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'initials' => $technician->initials(),
            'role' => $technician->role,
            'role_label' => Technician::ROLE_LABELS[$technician->role] ?? $technician->role,
            'is_active' => $technician->is_active,
            'phone' => $technician->phone,
            'email' => $technician->email,
            'address' => $technician->address,
            'specialty' => $technician->specialty,
            'notes' => $technician->notes,
            'has_photo' => $technician->hasPhoto(),
            // Versioned by updated_at so the preview refreshes after an upload.
            'photo_url' => $technician->hasPhoto()
                ? route('technicians.photo.show', ['technician' => $technician->id, 'v' => $technician->updated_at?->timestamp])
                : null,
            'updated_at' => $technician->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One activity-log line per change: who, which technician, what moved.
     */
    private function audit(Request $request, Technician $technician, string $event): void
    {
        activity('technician')
            ->causedBy($request->user())
            ->performedOn($technician)
            ->withProperties([
                'name' => $technician->name,
                'changes' => $event === 'updated' ? $technician->getChanges() : [],
            ])
            ->log($event);
    }
}
