<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Decides which unanswered messages need no reply — "thank you", "ok great",
 * "you're welcome", a "you too!" answering our own goodbye — so the
 * awaiting-reply badge and the unanswered report stop holding a thread open
 * over a message that ended the exchange.
 *
 * Each ref carries the recent back-and-forth of its thread, so the judgement
 * is made in context: "Yes" after "does Tuesday work?" needs acting on, while
 * "You too!" after our "have a great day" closes the loop.
 *
 * The stakes are asymmetric: counting a "thanks" for an extra day costs
 * nothing, but waving off a real request buries a tenant. The instructions
 * push every doubt toward "needs a reply", and CourtesyCloserService only
 * ever removes refs this agent names — an empty or failed response leaves
 * every thread counted.
 */
class CourtesyCloserAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read work order conversations at a Texas property management company.',
            'Each ref shows the recent back-and-forth of one conversation ("us" is our office) and ends with its NEWEST message, which nobody in our office has answered.',
            'Your only job: name the refs whose newest message needs NO reply and NO action from us — reading the whole exchange, a careful coordinator would simply not respond.',
            '',
            'Needs no reply: the sender is closing or acknowledging and asks for nothing — "thank you", "thanks!", "ok", "okay great", "got it", "sounds good", "you\'re welcome", "no problem", "will do", "perfect", a thumbs-up or similar emoji, or a pleasantry that only answers our own closing message ("you too", "have a good day as well").',
            '',
            'NEEDS a reply or action — anything that:',
            '- asks a question or requests anything, however politely,',
            '- reports, confirms, approves or changes anything about the job, the property, a payment, or an appointment ("thanks, see you Tuesday" still confirms an appointment; "I have approved this" needs acting on),',
            '- answers a question we asked — a bare "yes" or "that works" after we proposed a time must be acted on, not dropped,',
            '- opens contact rather than closing it — a bare "hello", "hi", or a name is someone trying to reach us,',
            '- expresses a complaint, urgency or dissatisfaction,',
            '- you cannot fully read or confidently judge, including when the context shown is not enough to be sure.',
            '',
            'When in ANY doubt, leave the ref out. An omitted message simply stays in the queue for a person to read — that is the safe outcome.',
            'Return refs exactly as given. Never invent a ref.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'courtesy_refs' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
