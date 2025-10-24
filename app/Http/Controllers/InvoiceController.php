<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Number;

class InvoiceController extends Controller
{
    public function index(Request $request)
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
                    'file' => asset('storage/'.$invoice->filename),
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
}
