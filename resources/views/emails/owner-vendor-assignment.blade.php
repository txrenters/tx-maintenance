<!DOCTYPE html>
<html lang="en">
<body>
    <p>Hello {{ $owner->name ?: trim($owner->first_name.' '.$owner->last_name) }},</p>
    <p>
        We have assigned {{ $vendor->name }}@if ($vendor->phone) ({{ $vendor->phone }})@endif
        to handle the repairs at {{ $propertyAddress }} under Work Order #{{ $workOrder->work_order_no }}.
    </p>
    @if ($includeTenantLine)
    <p>The vendor will contact the tenant directly to coordinate and schedule the appointment.</p>
    @endif
    <p>Thank you,<br>TexasRenters.com</p>
</body>
</html>
