<?php

namespace Tests\Unit;

use App\Services\PhoneFormatter;
use PHPUnit\Framework\TestCase;

class PhoneFormatterTest extends TestCase
{
    public function test_ten_digit_us_number_gets_the_country_code(): void
    {
        $this->assertSame('+13464122380', PhoneFormatter::e164('(346) 412-2380'));
        $this->assertSame('+13464122380', PhoneFormatter::e164('346-412-2380'));
        $this->assertSame('+13464122380', PhoneFormatter::e164('3464122380'));
    }

    public function test_eleven_digit_number_with_leading_one_only_gains_the_plus(): void
    {
        $this->assertSame('+13464122380', PhoneFormatter::e164('+1 (346) 412-2380'));
        $this->assertSame('+13464122380', PhoneFormatter::e164('13464122380'));
    }

    public function test_longer_international_number_is_passed_through(): void
    {
        $this->assertSame('+443464122380', PhoneFormatter::e164('+44 3464 122380'));
    }

    public function test_unsendable_values_come_back_null(): void
    {
        $this->assertNull(PhoneFormatter::e164('555-0111'));
        $this->assertNull(PhoneFormatter::e164('Dean'));
        $this->assertNull(PhoneFormatter::e164(''));
        $this->assertNull(PhoneFormatter::e164(null));
    }

    public function test_display_still_formats_for_humans(): void
    {
        $this->assertSame('(346) 412-2380', PhoneFormatter::display('+13464122380'));
        $this->assertSame('(346) 412-2380', PhoneFormatter::display('3464122380'));
    }
}
