<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The tiny live round-trip behind the Test button on the AI Settings page.
 * Deliberately a STRUCTURED agent: every production agent depends on
 * structured output, so a provider or model that cannot return schema JSON
 * should fail here, in front of the admin, not silently in a queue job.
 *
 * Not ConfigurableAiProvider — the Test button always passes the candidate
 * provider/model explicitly, independent of what is currently saved.
 */
class ConnectionTestAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a connectivity check for a property management system. Respond with ok set to true.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ok' => $schema->boolean()->required(),
        ];
    }
}
