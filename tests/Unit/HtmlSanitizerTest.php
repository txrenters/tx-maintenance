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

    public function test_strips_html_comments_including_abrupt_close_mxss(): void
    {
        $out = $this->sanitizer->clean('<!--><img src=x onerror=alert(1)>-->');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('alert', $out);

        $out2 = $this->sanitizer->clean('<p>hi<!-- secret -->there</p>');

        $this->assertStringNotContainsString('secret', $out2);
        $this->assertStringContainsString('hi', $out2);
        $this->assertStringContainsString('there', $out2);
    }

    public function test_empty_and_whitespace_input_return_empty(): void
    {
        $this->assertSame('', $this->sanitizer->clean(''));
        $this->assertSame('', $this->sanitizer->clean('   '));
    }

    public function test_plain_text_is_preserved(): void
    {
        $out = $this->sanitizer->clean('just words');

        $this->assertStringContainsString('just words', $out);
    }
}
