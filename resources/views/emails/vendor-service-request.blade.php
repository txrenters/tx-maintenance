<x-mail::message>
Dear {{ $vendorName }},

Please see attached service request.

Please be sure to contact the tenants within 2 business hours of the receipt of this request to schedule a time with them for repairs. To avoid trip charges, it is advised that you schedule with the tenants via email, this way, you have written proof that they have confirmed the appointment time with you. If written proof cannot be provided and tenants are not at the home at the scheduled time, we cannot charge trip charges. As always, please keep Texas Renters updated on all progress related to the service request including scheduling, progress, estimates, etc.

Finally, please be advised that you may not proceed with any work where the total bill is in excess of $250.00 without prior written approval.

Best regards,
The [TexasRenters.com](https://www.texasrenters.com/) Property Management Team

Please be sure to take before and after pictures of ALL repairs. You can upload these pictures directly through your maintenance dashboard upon completion of the repair. Please title each photo with the property address. We cannot process an invoice for payment without photos.

@if($portalUrl)
<x-mail::button :url="$portalUrl">
Open Work Order &amp; Upload Photos
</x-mail::button>
@endif

As always, please keep in mind the $250 cut off. If the repair will be over this amount, please stop work and contact our office or email an estimate.

If you have any questions or issues reaching or communicating with the tenants, please contact us and let us know.

**NOTE TO OUR VENDORS:** Texas Renters payment policy: Invoices are paid on the 1st and 15th of the month. Thank you.
</x-mail::message>
