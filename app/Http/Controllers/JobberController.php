<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class JobberController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? Invoice::count() : $request->per_page)
        : 10;

        $invoices = Invoice::query()
            ->with(['work_order', 'vendor'])
            ->filter(request(['search']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'title' => $invoice->title,
                    'filename' => $invoice->filename,
                    'filetype' => $invoice->filetype,
                    'amount' => Number::currency($invoice->amount),
                    'status' => $invoice->status,
                    'work_order_no' => $invoice->work_order->work_order_no,
                    'vendor' => $invoice->vendor->name,
                    'created_at' => $invoice->created_at->format('F d, Y'),
                ];
            });

        return inertia('Invoice/Index', [
            'title' => 'Invoices',
            'invoices' => $invoices,
            'filter' => $request->only(['search', 'per_page']),

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
