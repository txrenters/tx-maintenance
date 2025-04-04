<?php

namespace App\Http\Controllers;

use App\Models\ServiceStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServiceStatusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_status', ServiceStatus::class);

        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? ServiceStatus::count() : $request->per_page)
        : 10;

        $service_status = ServiceStatus::query()
            ->filter(request(['search']))
            ->orderBy('name', 'ASC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($service_status) {
                return [
                    'id' => $service_status->id,
                    'name' => $service_status->name,
                    'description' => $service_status->description,
                ];
            });

        return inertia('ServiceStatus/Index', [
            'title' => 'Service Status',
            'service_status' => $service_status,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create_status', ServiceStatus::class);

        $request->validate([
            'name' => 'required',
            'description' => 'nullable',
        ]);

        ServiceStatus::create($request->all());

        return redirect()->route('service_status.index');

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServiceStatus $serviceStatus)
    {
        Gate::authorize('update_status', ServiceStatus::class);

        $request->validate([
            'name' => 'required',
            'description' => 'nullable',
        ]);

        $serviceStatus->update($request->all());

        return redirect()->route('service_status.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceStatus $serviceStatus)
    {
        Gate::authorize('delete_status', ServiceStatus::class);

        $serviceStatus->delete();

        return redirect()->route('service_status.index');
    }
}
