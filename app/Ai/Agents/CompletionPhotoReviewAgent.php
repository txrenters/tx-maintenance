<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Looks at a vendor's "after" photo next to the work order's reported issue
 * and says whether the photo plausibly shows that issue addressed — a
 * read-only flag staff see on the Attachments tab before the work order
 * moves toward payment. It never blocks an upload, never messages anyone,
 * and a wrong verdict costs a human one extra glance at a photo.
 *
 * The stakes are asymmetric: quietly waving through unfinished work wastes
 * money, but a photo review must not read as an accusation either — the
 * verdict language stays neutral and every doubt lands on 'unclear'.
 */
class CompletionPhotoReviewAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You review completed-work photos for a Texas property management company.',
            'You are given the reported maintenance issue of a work order and one photo a vendor uploaded as an AFTER photo — evidence the work is done.',
            '',
            'Verdicts:',
            '- looks_resolved: the photo plausibly relates to the reported issue and shows it addressed (repaired, replaced, cleaned, installed). Photos legitimately differ in angle and framing — plausible is enough.',
            '- mismatch: the photo clearly does not relate to the reported issue, or clearly shows the issue still present and unaddressed.',
            '- unclear: you cannot tell — too dark, too blurry, too tight a crop, or a generic shot that neither confirms nor contradicts the work.',
            '',
            'Use mismatch ONLY when you are confident; every doubt is unclear. Many trades produce afters that look mundane (a pipe joint, a breaker panel, a patched wall) — mundane is not mismatch.',
            'note: one short neutral sentence a coordinator can act on, e.g. "The photo shows a kitchen sink but the issue is a bedroom ceiling leak." Never speculate about intent.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->required(),
            'note' => $schema->string()->required(),
        ];
    }
}
