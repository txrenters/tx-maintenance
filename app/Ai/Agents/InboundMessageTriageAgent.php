<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the newest unanswered inbound message of each thread and tags what
 * it is — a reschedule request, a complaint, an access problem, a "job is
 * done", a question, a confirmation — so staff see what a thread needs from
 * the board instead of opening it. When the message proposes a concrete
 * appointment day/time, it also extracts that proposal so a schedule
 * suggestion can be offered to staff.
 *
 * Read-only by design: nothing this agent returns sends a message or changes
 * a work order. A wrong intent is a mislabeled chip; an extracted time is
 * only ever a suggestion a human accepts. The instructions still push
 * accuracy over coverage: 'none' is always available and always safe.
 */
class InboundMessageTriageAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You triage work order conversations at a Texas property management company.',
            'Each ref shows the recent back-and-forth of one conversation ("us" is our office) and ends with its NEWEST message, which nobody in our office has answered. Classify only that newest message, reading the whole exchange for context.',
            '',
            'For every ref return exactly one item with its intent:',
            '- appointment_confirmed: they agree to a proposed or existing appointment ("Tuesday works", "see you then", "yes that time is fine").',
            '- reschedule_request: they ask to move, cancel, or set a different time for a visit.',
            '- complaint: dissatisfaction, urgency, or escalation about the work, the vendor, or the property.',
            '- access_issue: problems getting into the property — keys, lockbox, gate codes, nobody home, pets.',
            '- job_done: they report or confirm the work is finished or a problem is resolved.',
            '- approval: they approve, authorize, or tell us to go ahead with something — a quote, a repair, sending a vendor ("approved", "yes, proceed", "please place a service call").',
            '- question: they ask for information, a status update, or something else not covered above, however politely.',
            '- none: the message needs no categorization — a bare thanks, an emoji, a pleasantry closing the exchange.',
            '',
            'A short reply ("yes", "ok", "correct", "sounds good") means whatever OUR newest question or proposal was about — read it in that light:',
            '- we asked whether the work is complete → job_done',
            '- we proposed a visit day or time → appointment_confirmed',
            '- we asked for a go-ahead on a cost, repair, or vendor → approval',
            'Pick the single closest intent. When nothing fits confidently, use question for messages that ask or report something, and none only for messages that plainly need nothing.',
            '',
            'summary: one short plain sentence a coordinator can read at a glance, e.g. "Tenant asks to move the visit to Tuesday afternoon." Never invent details that are not in the messages.',
            '',
            'Schedule extraction — fill schedule_start ONLY when the newest message itself proposes or agrees to a CONCRETE day (and optionally time) for a visit:',
            '- Resolve relative days ("tomorrow", "next Tuesday") using the CURRENT DATE line in the prompt.',
            '- Format: "YYYY-MM-DD HH:MM" in the local timezone shown in the prompt. If only a day is given with no time, use 00:00. If they give a range ("between 2 and 4"), schedule_start is the range start and schedule_end the range end; otherwise schedule_end is null.',
            '- Vague availability ("any day next week", "call me to arrange") is NOT concrete — leave schedule_start null.',
            '- Never extract dates that are in the past relative to the CURRENT DATE line.',
            'Return refs exactly as given. Never invent a ref.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items(
                $schema->object([
                    'ref' => $schema->integer()->required(),
                    'intent' => $schema->string()->required(),
                    'summary' => $schema->string()->required(),
                    'schedule_start' => $schema->string()->nullable()->required(),
                    'schedule_end' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
        ];
    }
}
