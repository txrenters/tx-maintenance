<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <p>Hello,</p>
    <p>
        This is a confirmation from TexasRenters.com Maintenance that the HOA
        violation {{ $property ? 'at '.$property.' ' : '' }}(Work Order
        #{{ $workOrder->work_order_no ?? $workOrder->id }}) has been
        <strong>corrected</strong>.
    </p>
    <p>
        You can view the photos of the completed work using the secure link below:
    </p>
    <p>
        <a href="{{ $galleryUrl }}"
           style="display:inline-block;padding:10px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;">
            View the photos
        </a>
    </p>
    <p style="font-size: 12px; color: #6b7280;">
        If the button does not work, copy and paste this link into your browser:<br>
        {{ $galleryUrl }}
    </p>
    <p>Thank you,<br>TexasRenters.com Maintenance</p>
</body>
</html>
