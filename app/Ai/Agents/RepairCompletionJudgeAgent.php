<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the THMP technician's Jobber notes and says whether the repair is
 * really finished. The crew taps Complete on a visit for reasons that are
 * not "done" — a temporary fix, a return trip with parts, a diagnosis-only
 * stop — so a completed visit alone must not tick "Have you Completed the
 * Repair" (Earl, 2026-10-09). Only a confident yes from this agent does.
 *
 * A wrong yes moves the work order to Service Completed and starts the
 * tenant follow-up while the repair is still open, so when in doubt it
 * says no and a coordinator decides.
 */
class RepairCompletionJudgeAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You review maintenance work orders at a Texas property management company. The in-house crew logs visits in the Jobber app and writes notes there.',
            'You are given one work order, the Jobber completion facts, and the technician\'s notes, newest first. Decide whether the REPAIR THE WORK ORDER ASKED FOR is finished.',
            '',
            'Rules:',
            '- Say completed only when the notes plainly say the problem is fixed, tested or working, with nothing left to do on it.',
            '- Say not completed when the notes mention a return visit, a follow-up, a temporary or partial fix, parts or a quote on order, waiting on the owner or an approval, a different problem found, a no-show or no access, or when the notes only describe a diagnosis or an estimate.',
            '- Say not completed when the newest note does not say what was done. A tapped "complete" on the visit is not proof on its own.',
            '- Notes about an unrelated job or a different unit do not count.',
            '- When in doubt, say not completed: a wrong yes closes out a repair that is still open.',
            '',
            'completed: true only when the repair is finished.',
            'confidence: 0-100, how sure you are of your decision.',
            'reason: one short plain sentence a coordinator can read, naming what the notes say was done or what is still owed.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'completed' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
