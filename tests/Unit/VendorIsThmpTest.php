<?php

namespace Tests\Unit;

use App\Models\Vendor;
use PHPUnit\Framework\TestCase;

class VendorIsThmpTest extends TestCase
{
    public function test_it_matches_the_thmp_vendor_by_name(): void
    {
        $this->assertTrue((new Vendor(['name' => 'Texas Home Maintenance Pros']))->isThmp());
    }

    public function test_it_is_case_and_whitespace_insensitive(): void
    {
        $this->assertTrue((new Vendor(['name' => '  texas home maintenance pros ']))->isThmp());
    }

    public function test_a_third_party_vendor_is_not_thmp(): void
    {
        $this->assertFalse((new Vendor(['name' => 'Reliable Plumbing']))->isThmp());
    }

    public function test_the_owner_placeholder_is_not_thmp(): void
    {
        $this->assertFalse((new Vendor(['name' => 'OWNER VENDOR']))->isThmp());
    }
}
