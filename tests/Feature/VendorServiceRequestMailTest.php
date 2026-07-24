<?php

namespace Tests\Feature;

use App\Mail\VendorServiceRequestMail;
use Tests\TestCase;

class VendorServiceRequestMailTest extends TestCase
{
    public function test_it_uses_the_work_orders_sender_and_ccs_the_additional_addresses(): void
    {
        $mail = new VendorServiceRequestMail(
            vendorName: 'Acme Plumbing',
            workOrderNo: '43339',
            pdfContent: '%PDF-fake',
        );

        $envelope = $mail->envelope();
        $cc = array_map(fn ($address) => $address->address, $envelope->cc);

        $this->assertSame('workorders@texasrenters.com', $envelope->from->address);
        $this->assertSame([
            'mc@texasrenters.com',
            'ofm@txhomemp.com',
        ], $cc);
    }

    public function test_it_includes_the_tenant_contact_note_for_occupied_work_orders(): void
    {
        $html = (new VendorServiceRequestMail(
            vendorName: 'Acme Plumbing',
            workOrderNo: '43339',
            pdfContent: '%PDF-fake',
            isVacant: false,
        ))->render();

        $this->assertStringContainsString('reaching or communicating with the tenants', $html);
    }

    public function test_it_drops_the_tenant_contact_note_for_vacant_work_orders(): void
    {
        $html = (new VendorServiceRequestMail(
            vendorName: 'Acme Plumbing',
            workOrderNo: '43339',
            pdfContent: '%PDF-fake',
            isVacant: true,
        ))->render();

        $this->assertStringNotContainsString('reaching or communicating with the tenants', $html);
    }
}
