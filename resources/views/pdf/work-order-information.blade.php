@php
    // dompdf does not render .webp, so use the PNG copy of the brand logo.
    $logoPath = public_path('tx-logo.png');
    $logo = file_exists($logoPath)
        ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath))
        : null;

    $fmtDate = function ($value) {
        if (! $value) {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('m/d/Y');
        } catch (\Throwable $e) {
            return '';
        }
    };

    // Format a US phone number as (XXX) XXX-XXXX; leave anything else untouched.
    $fmtPhone = function ($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        return filled($value) ? $value : null;
    };

    $tenant = $workOrder->requested_by;

    $requesterName = $workOrder->service_request_contact_name
        ?: trim(($tenant->first_name ?? '').' '.($tenant->last_name ?? ''));

    $workPhone = $fmtPhone($tenant->work_phone ?? null);
    $mobilePhone = $fmtPhone($tenant->mobile_phone ?? ($workOrder->service_request_contact_phone ?? null));
    $homePhone = $fmtPhone($tenant->home_phone ?? null);
    // Don't repeat the same number under both Mobile and Home.
    if ($homePhone && $homePhone === $mobilePhone) {
        $homePhone = null;
    }

    // "Managed By" is the coordinator on the work order (matches the app's
    // managed_by relation), NOT the generic work-order-coordinator login.
    $coordinator = $workOrder->managed_by;
    $coordinatorName = trim(($coordinator->first_name ?? '').' '.($coordinator->last_name ?? ''))
        ?: trim($workOrder->woc->name ?? '');
    $coordinatorPhone = $fmtPhone(
        $workOrder->woc->wocNumber->twilioPhoneNumber->phone_number ?? ($workOrder->woc->phone ?? null)
    );
    $coordinatorEmail = $coordinator->email ?? ($workOrder->woc->email ?? null);

    $buildingLine = collect([
        $workOrder->building->address ?? null,
        $workOrder->building->address_cont ?? null,
        $workOrder->building->city ?? null,
        $workOrder->building->state_region ?? null,
    ])->filter()->implode(', ');

    $subLocation = $workOrder->specific_location ?: $workOrder->client_data;

    $age = null;
    if ($workOrder->created_date) {
        try {
            $age = (int) \Illuminate\Support\Carbon::parse($workOrder->created_date)
                ->startOfDay()
                ->diffInDays(\Illuminate\Support\Carbon::now()->startOfDay());
        } catch (\Throwable $e) {
            $age = null;
        }
    }

    $dash = '—';
    $or = fn ($v) => filled($v) ? e($v) : $dash;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 30px 36px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2933; font-size: 10.5px; line-height: 1.4; margin: 0; }

        .company { border-bottom: 3px solid #0f2c66; padding-bottom: 10px; margin-bottom: 6px; }
        .company .name { font-size: 15px; font-weight: bold; color: #0f2c66; }
        .company .addr { font-size: 9.5px; color: #6b7280; }

        .doc-title { font-size: 16px; font-weight: bold; color: #0f2c66; letter-spacing: 0.5px; margin: 12px 0 10px; }

        .wo-banner { width: 100%; background: #0f2c66; color: #ffffff; border-radius: 6px; margin-bottom: 14px; }
        .wo-banner td { padding: 9px 14px; }
        .wo-banner .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #a9c0f0; }
        .wo-banner .big { font-size: 15px; font-weight: bold; }
        .pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 9px; font-weight: bold; background: #ffffff; color: #0f2c66; }
        .pill-emergency { background: #dc2626; color: #ffffff; }

        .section { font-size: 10px; font-weight: bold; color: #2563EA; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; margin: 14px 0 6px; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid td { padding: 4px 8px; vertical-align: top; width: 50%; border-bottom: 1px solid #f0f2f5; }
        .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; color: #9aa5b1; }
        .val { font-size: 10.5px; color: #1f2933; }

        .desc-box { background: #f5f7fa; border: 1px solid #e5e7eb; border-radius: 6px; padding: 9px 11px; }

        table.vendors { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.vendors th { background: #eef2fb; color: #0f2c66; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; padding: 6px 8px; border-bottom: 1px solid #d5def3; }
        table.vendors td { padding: 6px 8px; border-bottom: 1px solid #eef1f5; }

        .footer { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8.5px; color: #9aa5b1; text-align: center; }
    </style>
</head>
<body>
    <table class="company" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="vertical-align:middle;">
                @if ($logo)
                    <img src="{{ $logo }}" style="height:34px;" alt="TexasRenters.com">
                @else
                    <div class="name">TexasRenters.com, LLC</div>
                @endif
            </td>
            <td style="text-align:right; vertical-align:middle;">
                <div style="font-size:10px; font-weight:bold; color:#0f2c66;">TexasRenters.com, LLC</div>
                <div class="addr">5225 Katy Freeway, Ste 545 &middot; Houston, TX 77007</div>
            </td>
        </tr>
    </table>

    <div class="doc-title">Work Order Detail</div>

    <table class="wo-banner">
        <tr>
            <td style="width: 34%;">
                <div class="label">Work Order #</div>
                <div class="big">{{ $or($workOrder->work_order_no) }}</div>
            </td>
            <td style="width: 33%; text-align: center;">
                <div class="label">Status</div>
                <span class="pill">{{ $workOrder->status ?: 'Open' }}</span>
            </td>
            <td style="width: 33%; text-align: right;">
                <div class="label">Priority</div>
                <span class="pill {{ $workOrder->is_emergency ? 'pill-emergency' : '' }}">
                    {{ $workOrder->is_emergency ? 'EMERGENCY' : ($workOrder->priority ?: $dash) }}
                </span>
            </td>
        </tr>
    </table>

    <div class="section">Work Order Information</div>
    <table class="grid">
        <tr>
            <td>
                <div class="lbl">Request By</div>
                <div class="val">{{ $requesterName ?: $dash }}</div>
            </td>
            <td>
                <div class="lbl">Date Created</div>
                <div class="val">{{ $fmtDate($workOrder->created_date) ?: $dash }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="width: 100%;">
                <div class="lbl">Location</div>
                <div class="val">{{ $buildingLine ?: $or($workOrder->location) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Work Phone</div>
                <div class="val">{{ $or($workPhone) }}</div>
            </td>
            <td>
                <div class="lbl">Managed By</div>
                <div class="val">{{ $coordinatorName ?: 'TexasRenters Property Management' }}</div>
                @if ($coordinatorPhone)<div class="val">Tel. {{ $coordinatorPhone }}</div>@endif
                @if ($coordinatorEmail)<div class="val">Email: {{ $coordinatorEmail }}</div>@endif
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Mobile Phone</div>
                <div class="val">{{ $or($mobilePhone) }}</div>
            </td>
            <td>
                <div class="lbl">Home Phone</div>
                <div class="val">{{ $or($homePhone) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Priority</div>
                <div class="val">{{ $or($workOrder->priority) }}</div>
            </td>
            <td>
                <div class="lbl">Authorization to Enter</div>
                <div class="val">{{ $or($workOrder->authorized_to_enter) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Start Date</div>
                <div class="val">{{ $fmtDate($workOrder->start_date) ?: $dash }}</div>
            </td>
            <td>
                <div class="lbl">Scheduled End Date</div>
                <div class="val">{{ $fmtDate($workOrder->scheduled_end_date) ?: $dash }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="lbl">Age</div>
                <div class="val">{{ $age !== null ? $age : $dash }}</div>
            </td>
            <td>
                <div class="lbl">Date Completed</div>
                <div class="val">{{ $fmtDate($workOrder->completed_date) ?: $dash }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="width: 100%;">
                <div class="lbl">Category</div>
                <div class="val">{{ $or($workOrder->category) }}</div>
            </td>
        </tr>
    </table>

    <div class="section">Description of Work</div>
    <div class="desc-box">
        @if ($subLocation)
            <div class="lbl">Location</div>
            <div class="val" style="margin-bottom:6px;">{{ $subLocation }}</div>
        @endif
        <div class="lbl">Description</div>
        <div class="val">{!! nl2br(e($workOrder->description ?: 'No description provided.')) !!}</div>
    </div>

    <div class="section">Vendors</div>
    <table class="vendors">
        <thead>
            <tr>
                <th style="width: 45%;">Name</th>
                <th style="width: 30%;">Address</th>
                <th style="width: 25%;">Phone</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($workOrder->vendors as $vendor)
                <tr>
                    <td>{{ $or($vendor->name) }}</td>
                    <td>{{ $or($vendor->address ?? null) }}</td>
                    <td>{{ $or($fmtPhone($vendor->phone)) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="color:#9aa5b1;">No vendor assigned.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        TexasRenters.com Property Management &middot; This document was generated automatically.
    </div>
</body>
</html>
