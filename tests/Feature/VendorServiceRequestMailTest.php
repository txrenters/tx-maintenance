<?php

namespace Tests\Feature;

use App\Mail\VendorServiceRequestMail;
use Tests\TestCase;

class VendorServiceRequestMailTest extends TestCase
{
    public function test_it_ccs_the_additional_addresses(): void
    {
        $mail = new VendorServiceRequestMail(
            vendorName: 'Acme Plumbing',
            workOrderNo: '43339',
            pdfContent: '%PDF-fake',
        );

        $cc = array_map(fn ($address) => $address->address, $mail->envelope()->cc);

        $this->assertContains('workorders@texasrenters.com', $cc);
        $this->assertContains('mc@texasrenters.com', $cc);
        $this->assertContains('ofm@txhomemp.com', $cc);
    }
}
