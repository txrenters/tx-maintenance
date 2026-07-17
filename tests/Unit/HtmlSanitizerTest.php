<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_strips_script_tags_entirely(): void
    {
        $out = $this->sanitizer->clean('<p>hi</p><script>alert(1)</script>');

        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function test_removes_event_handler_attributes(): void
    {
        $out = $this->sanitizer->clean('<p onclick="steal()">hi</p>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('hi', $out);
    }

    public function test_drops_javascript_href_but_keeps_http_links(): void
    {
        $bad = $this->sanitizer->clean('<a href="javascript:alert(1)">x</a>');
        $good = $this->sanitizer->clean('<a href="https://texasrenters.com">x</a>');

        $this->assertStringNotContainsString('javascript', $bad);
        $this->assertStringContainsString('https://texasrenters.com', $good);
        $this->assertStringContainsString('rel="noopener noreferrer"', $good);
    }

    public function test_unwraps_disallowed_tags_keeping_text(): void
    {
        $out = $this->sanitizer->clean('<div><span>keep me</span></div>');

        $this->assertStringContainsString('keep me', $out);
        $this->assertStringNotContainsString('<div>', $out);
        $this->assertStringNotContainsString('<span>', $out);
    }
}
