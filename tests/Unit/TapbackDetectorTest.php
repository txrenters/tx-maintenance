<?php

namespace Tests\Unit;

use App\Services\TapbackDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The tapback grammar: Apple's SMS-fallback reaction texts must be recognized
 * in every documented shape, and nothing that a human could plausibly type as
 * a real message may ever be swallowed as a reaction.
 */
class TapbackDetectorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function namedVerbs(): array
    {
        return [
            'Liked' => ['Liked', '👍', 'Liked a message', false],
            'Loved' => ['Loved', '❤️', 'Loved a message', false],
            'Disliked' => ['Disliked', '👎', 'Disliked a message', true],
            'Laughed at' => ['Laughed at', '😂', 'Laughed at a message', false],
            'Emphasized' => ['Emphasized', '‼️', 'Emphasized a message', false],
            'Questioned' => ['Questioned', '❓', 'Questioned a message', true],
        ];
    }

    #[DataProvider('namedVerbs')]
    public function test_every_named_verb_is_detected(string $verb, string $emoji, string $label, bool $needsReply): void
    {
        $result = TapbackDetector::detect($verb.' “We will send a vendor Tuesday.”');

        $this->assertNotNull($result);
        $this->assertSame($emoji, $result['emoji']);
        $this->assertSame($label, $result['label']);
        $this->assertSame('We will send a vendor Tuesday.', $result['quoted']);
        $this->assertFalse($result['removal']);
        $this->assertSame($needsReply, $result['needs_reply']);
    }

    public function test_straight_quotes_work_too(): void
    {
        $result = TapbackDetector::detect('Liked "Sounds good"');

        $this->assertNotNull($result);
        $this->assertSame('Sounds good', $result['quoted']);
    }

    public function test_multiline_quoted_text_is_matched_and_collapsed(): void
    {
        $body = "Liked “Hello,\n\nTexasRenters.com has received a new service request for your property.\n\nWarm regards”";

        $result = TapbackDetector::detect($body);

        $this->assertNotNull($result);
        $this->assertSame(
            'Hello, TexasRenters.com has received a new service request for your property. Warm regards',
            $result['quoted']
        );
    }

    public function test_quoted_text_may_itself_contain_quotes(): void
    {
        $result = TapbackDetector::detect('Liked “He said "all clear" this morning”');

        $this->assertNotNull($result);
        $this->assertSame('He said "all clear" this morning', $result['quoted']);
    }

    public function test_our_ref_footer_lives_inside_the_quote(): void
    {
        $result = TapbackDetector::detect('Loved “We will take care of it. (Ref: WO#4312)”');

        $this->assertNotNull($result);
        $this->assertSame('We will take care of it. (Ref: WO#4312)', $result['quoted']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removalNouns(): array
    {
        return [
            'like' => ['a like', '👍'],
            'heart' => ['a heart', '❤️'],
            'dislike' => ['a dislike', '👎'],
            'laugh' => ['a laugh', '😂'],
            'exclamation' => ['an exclamation', '‼️'],
            'question mark' => ['a question mark', '❓'],
        ];
    }

    #[DataProvider('removalNouns')]
    public function test_every_removal_noun_is_detected(string $noun, string $emoji): void
    {
        $result = TapbackDetector::detect('Removed '.$noun.' from “We will send a vendor Tuesday.”');

        $this->assertNotNull($result);
        $this->assertSame($emoji, $result['emoji']);
        $this->assertSame('Removed a reaction', $result['label']);
        $this->assertTrue($result['removal']);
        $this->assertFalse($result['needs_reply']);
    }

    public function test_a_custom_emoji_reaction_is_detected(): void
    {
        $result = TapbackDetector::detect('Reacted 😂 to “No worries at all”');

        $this->assertNotNull($result);
        $this->assertSame('😂', $result['emoji']);
        $this->assertSame('Reacted 😂 to a message', $result['label']);
        $this->assertFalse($result['removal']);
        $this->assertFalse($result['needs_reply']);
    }

    public function test_a_multi_codepoint_emoji_reaction_is_detected(): void
    {
        $result = TapbackDetector::detect('Reacted 🫶🏻 to “Thank you for the quick fix”');

        $this->assertNotNull($result);
        $this->assertSame('🫶🏻', $result['emoji']);
        $this->assertFalse($result['needs_reply']);
    }

    public function test_a_custom_emoji_removal_is_a_removal(): void
    {
        $result = TapbackDetector::detect('Removed 😂 from “No worries at all”');

        $this->assertNotNull($result);
        $this->assertSame('😂', $result['emoji']);
        $this->assertSame('Removed a reaction', $result['label']);
        $this->assertTrue($result['removal']);
        $this->assertFalse($result['needs_reply']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeCustomEmoji(): array
    {
        return [
            'thumbs down' => ['👎'],
            'question' => ['❓'],
            'angry' => ['😡'],
            'broken heart' => ['💔'],
            'crying' => ['😭'],
        ];
    }

    #[DataProvider('negativeCustomEmoji')]
    public function test_negative_custom_reactions_still_need_a_reply(string $emoji): void
    {
        $result = TapbackDetector::detect('Reacted '.$emoji.' to “The vendor comes Tuesday”');

        $this->assertNotNull($result);
        $this->assertTrue($result['needs_reply']);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function notTapbacks(): array
    {
        return [
            'sentence starting with the verb' => ['I Liked “the plan” too'],
            'text after the closing quote' => ['Liked "the quote" and will call you'],
            'lowercase verb' => ['liked “ok”'],
            'human sentence in Reacted form' => ['Reacted quickly to “the news”'],
            'missing closing quote' => ['Liked “We will send a vendor'],
            'no quote at all' => ['Liked it a lot'],
            'plain message' => ['Thanks, see you Tuesday'],
            'empty string' => [''],
            'whitespace only' => ["  \n  "],
            'null' => [null],
        ];
    }

    #[DataProvider('notTapbacks')]
    public function test_everything_else_is_left_alone(?string $body): void
    {
        $this->assertNull(TapbackDetector::detect($body));
    }
}
