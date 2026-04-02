<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFallbackVendorRequest;
use App\Http\Requests\UpdateFallbackVendorRequest;
use App\Models\FallbackVendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class FallbackVendorController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', FallbackVendor::class);

        $fallbackVendors = FallbackVendor::orderBy('priority')->orderBy('name')->get();

        return inertia('FallbackVendor/Index', [
            'title' => 'Fallback Vendors',
            'fallbackVendors' => $fallbackVendors,
        ]);
    }

    public function store(StoreFallbackVendorRequest $request): RedirectResponse
    {
        Gate::authorize('create', FallbackVendor::class);

        FallbackVendor::create($request->validated());

        return back()->with('success', 'Fallback vendor created successfully.');
    }

    public function update(UpdateFallbackVendorRequest $request, FallbackVendor $fallbackVendor): RedirectResponse
    {
        Gate::authorize('update', $fallbackVendor);

        $fallbackVendor->update($request->validated());

        return back()->with('success', 'Fallback vendor updated successfully.');
    }

    public function destroy(FallbackVendor $fallbackVendor): RedirectResponse
    {
        Gate::authorize('delete', $fallbackVendor);

        $fallbackVendor->delete();

        return back()->with('success', 'Fallback vendor deleted successfully.');
    }
}
