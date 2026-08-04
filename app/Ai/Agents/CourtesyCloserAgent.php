<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Decides which unanswered messages are courtesy closers — "thank you", "ok
 * great", "you're welcome" — so the awaiting-reply badge and the unanswered
 * report stop holding a thread open over a message that ended the exchange.
 *
 * The stakes are asymmetric: counting a "thanks" for an extra day costs
 * nothing, but waving off a real request buries a tenant. The instructions
 * push every doubt toward "needs a reply", and CourtesyCloserService only
 * ever removes refs this agent names — an empty or failed response leaves
 * every thread counted.
 */
class CourtesyCloserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read the newest unanswered message of work order conversations at a Texas property management company.',
            'Your only job: name the refs whose message is a pure courtesy closer — the sender is wrapping up and plainly expects no answer.',
            '',
            'A courtesy closer thanks or acknowledges and asks for nothing: "thank you", "thanks!", "ok", "okay great", "got it", "sounds good", "you\'re welcome", "no problem", "will do", "perfect", a thumbs-up or similar emoji, or a short combination of these.',
            '',
            'NOT a courtesy closer — anything that:',
            '- asks a question or requests anything, however politely,',
            '- reports, confirms, approves or changes anything about the job, the property, a payment, or an appointment ("thanks, see you Tuesday" still confirms an appointment; "I have approved this" needs acting on),',
            '- answers a question we asked,',
            '- opens contact rather than closing it — a bare "hello", "hi", or a name is someone trying to reach us,',
            '- expresses a complaint, urgency or dissatisfaction,',
            '- you cannot fully read or confidently judge.',
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
