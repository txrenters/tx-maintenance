<?php

namespace Tests\Unit;

use App\Models\Vendor;
use PHPUnit\Framework\TestCase;

class VendorUserEmailTest extends TestCase
{
    public function test_a_real_address_is_used_as_is(): void
    {
        $this->assertSame('office@gradysair.example', Vendor::userEmailFor('office@gradysair.example', 761069582));
    }

    public function test_a_missing_address_gets_the_per_vendor_placeholder(): void
    {
        $this->assertSame('4066574337@texasrenter.com', Vendor::userEmailFor(null, 4066574337));
    }

    public function test_a_blank_address_gets_the_same_placeholder_as_a_missing_one(): void
    {
        // PropertyWare's REST vendor payload carries "" for a vendor with no
        // e-mail; treating that as an address keyed 3,400 vendors to one user.
        $this->assertSame('4066574337@texasrenter.com', Vendor::userEmailFor('', 4066574337));
        $this->assertSame('4066574337@texasrenter.com', Vendor::userEmailFor('   ', '4066574337'));
    }
}
