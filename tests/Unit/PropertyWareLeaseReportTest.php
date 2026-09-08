<?php

namespace Tests\Unit;

use App\Services\PropertyWareLeaseReport;
use PHPUnit\Framework\TestCase;

/**
 * Address matching decides whether a lease reaches its property at all, so the
 * spellings PropertyWare actually produces are pinned here. The cases come
 * from the live report: matching on the address alone found 54% of the roster,
 * and handling the suffix difference took it to 99%.
 */
class PropertyWareLeaseReportTest extends TestCase
{
    private PropertyWareLeaseReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new PropertyWareLeaseReport;
    }

    public function test_casing_and_punctuation_do_not_change_the_key(): void
    {
        $this->assertSame(
            $this->report->addressKey('6341 Del Monte Dr'),
            $this->report->addressKey('6341 DEL MONTE DR.'),
        );
    }

    public function test_a_spelled_out_street_suffix_matches_its_abbreviation(): void
    {
        $this->assertSame(
            $this->report->addressKey('4606 E Meadow Dr'),
            $this->report->addressKey('4606 E Meadow Drive'),
        );
    }

    public function test_extra_whitespace_collapses(): void
    {
        $this->assertSame('1122 cascade creek dr', $this->report->addressKey('  1122   Cascade  Creek   Dr  '));
    }

    public function test_a_trailing_street_suffix_can_be_dropped(): void
    {
        // Building 1122 is named "1122 Cascade Creek" but addressed
        // "1122 Cascade Creek Dr"; both have to reach the same property.
        $this->assertSame(
            $this->report->addressKey('1122 Cascade Creek'),
            $this->report->withoutStreetSuffix($this->report->addressKey('1122 Cascade Creek Dr')),
        );
    }

    public function test_a_suffix_in_the_middle_of_an_address_is_kept(): void
    {
        // Every suffix word is abbreviated, wherever it sits, so "Lane"
        // becomes "ln" even mid-address.
        $this->assertSame('1500 park ln ct', $this->report->addressKey('1500 Park Lane Ct'));
        // Only the trailing one is dropped: the inner "ln" has to survive, or
        // distinct streets would merge.
        $this->assertSame('1500 park ln', $this->report->withoutStreetSuffix('1500 park ln ct'));
    }

    public function test_an_address_with_no_trailing_suffix_yields_nothing_to_strip(): void
    {
        $this->assertSame('', $this->report->withoutStreetSuffix('1122 cascade creek'));
    }

    public function test_a_bare_number_and_suffix_is_refused(): void
    {
        // Stripping this would leave "123", which collides with every other
        // property on the block.
        $this->assertSame('', $this->report->withoutStreetSuffix('123 dr'));
    }

    public function test_an_empty_address_produces_an_empty_key(): void
    {
        $this->assertSame('', $this->report->addressKey('   '));
        $this->assertSame('', $this->report->addressKey(''));
    }
}
