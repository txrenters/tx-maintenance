<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads HOA violation notices out of a SCANNED document — a PDF with no text
 * layer, or a photo/screenshot (JPG/PNG/WebP) — by sending it straight to
 * OpenAI's vision-capable Responses API. This is the fallback for the common
 * real-world case (HOAs mail scanned letters; staff snap photos) where
 * pdfparser has no text to give the text-based extractor.
 *
 * A direct HTTP call (not the AI gateway) because the gateway omits the
 * `filename` OpenAI requires on base64 PDF file inputs.
 */
class HoaNoticeVisionExtractor
{
    /**
     * @param  string  $contents  raw PDF or image bytes
     * @param  string  $mime  the file's MIME type (application/pdf or image/*)
     * @return array<int, array<string, mixed>>|null the raw notice rows, or null on failure
     */
    public function extract(string $contents, string $mime = 'application/pdf'): ?array
    {
        $key = (string) config('ai.providers.openai.key');
        $model = (string) config('ai.providers.openai.model');
        $baseUrl = rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');

        if ($key === '' || $model === '') {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(120)
                ->post($baseUrl.'/responses', [
                    'model' => $model,
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $this->instructions()],
                            $this->fileContentPart($contents, $mime),
                        ],
                    ]],
                    'text' => ['format' => $this->responseFormat()],
                    // Reading a notice is simple structured extraction, not a
                    // reasoning task — keep effort low so scans return faster.
                    'reasoning' => ['effort' => 'low'],
                ]);

            if ($response->failed()) {
                Log::warning('HOA vision extraction request failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $this->parseNotices($response->json());
        } catch (\Throwable $exception) {
            Log::warning('HOA vision extraction errored.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * The document content part: images go as `input_image`, PDFs (and anything
     * else) as `input_file` with the filename OpenAI requires.
     *
     * @return array<string, string>
     */
    private function fileContentPart(string $contents, string $mime): array
    {
        if (str_starts_with($mime, 'image/')) {
            return [
                'type' => 'input_image',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode($contents),
            ];
        }

        return [
            'type' => 'input_file',
            'filename' => 'hoa-notice.pdf',
            'file_data' => 'data:application/pdf;base64,'.base64_encode($contents),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function parseNotices(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        // The structured JSON is the text of the message's output_text part.
        $text = null;

        foreach (data_get($payload, 'output', []) as $item) {
            foreach (data_get($item, 'content', []) as $part) {
                if (data_get($part, 'type') === 'output_text') {
                    $text = data_get($part, 'text');
                    break 2;
                }
            }
        }

        if (! is_string($text) || $text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? (data_get($decoded, 'notices') ?: []) : null;
    }

    private function instructions(): string
    {
        return implode("\n", [
            'You read HOA (homeowners association) violation notices for a property management company from the attached PDF, which is often a scanned letter.',
            'The document may contain MULTIPLE separate notices — different properties, associations, and deadlines. Return one entry in "notices" for EACH distinct property notice. A single-notice document yields exactly one entry.',
            'page: the 1-based page the notice appears on.',
            'property_address: the street address the notice is about (the "Property:" line), e.g. "10107 Mariposa Green Ct". NOT the mailing/recipient address. Null if none.',
            'description: a short, plain-language summary of what the tenant must correct, e.g. "Store the trash bins out of view on non-trash days."',
            'violation_items: each individual item to fix, listed separately.',
            'hoa_name: the association or management company that issued the notice; null if unclear.',
            'notice_date: the date printed on the notice (YYYY-MM-DD); null if none.',
            'deadline_date: an explicit "remedy by / resolve by" calendar date if stated (YYYY-MM-DD); null otherwise.',
            'deadline_days: if it says to fix within a number of days (e.g. "within 10 days"), the integer; null otherwise.',
            'Ground everything strictly in the document; never invent properties, violations, or dates.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        $noticeProperties = [
            'page' => ['type' => 'integer'],
            'property_address' => ['type' => ['string', 'null']],
            'description' => ['type' => 'string'],
            'violation_items' => ['type' => 'array', 'items' => ['type' => 'string']],
            'hoa_name' => ['type' => ['string', 'null']],
            'notice_date' => ['type' => ['string', 'null']],
            'deadline_date' => ['type' => ['string', 'null']],
            'deadline_days' => ['type' => ['integer', 'null']],
        ];

        return [
            'type' => 'json_schema',
            'name' => 'hoa_notices',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['notices'],
                'properties' => [
                    'notices' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => array_keys($noticeProperties),
                            'properties' => $noticeProperties,
                        ],
                    ],
                ],
            ],
        ];
    }
}
