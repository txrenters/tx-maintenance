<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the invoice number off a SCANNED invoice — a photo (JPG/PNG/WebP) or
 * a PDF with no text layer — by sending it to OpenAI's vision-capable
 * Responses API, the same way scanned HOA notices are read. This is the
 * fallback for the one upload in a thousand that is a picture rather than a
 * generated PDF, and it is off whenever no OpenAI key is configured.
 *
 * A direct HTTP call (not the AI gateway) because the gateway omits the
 * `filename` OpenAI requires on base64 PDF file inputs.
 */
class InvoiceNumberVisionExtractor
{
    /**
     * @param  string  $contents  raw PDF or image bytes
     * @param  string  $mime  the file's MIME type (application/pdf or image/*)
     * @return string|null the number as printed, or null when none was read
     */
    public function extract(string $contents, string $mime): ?string
    {
        if (! config('services.invoices.number_vision_enabled')) {
            return null;
        }

        $key = (string) config('ai.providers.openai.key');
        $model = (string) config('services.invoices.number_vision_model');
        $baseUrl = rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');

        if ($key === '' || $model === '') {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(60)
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
                    // Reading one printed number is not a reasoning task.
                    'reasoning' => ['effort' => 'low'],
                ]);

            if ($response->failed()) {
                Log::warning('Invoice number vision read failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $this->parseNumber($response->json());
        } catch (\Throwable $exception) {
            Log::warning('Invoice number vision read errored.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * The document content part: images go as `input_image`, PDFs as
     * `input_file` with the filename OpenAI requires. Detail is pinned to
     * "high", which caps what a photo costs; the default on newer models is
     * "original", several times more tokens for no better read of a number.
     *
     * @return array<string, string>
     */
    private function fileContentPart(string $contents, string $mime): array
    {
        if (str_starts_with($mime, 'image/')) {
            return [
                'type' => 'input_image',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode($contents),
                'detail' => 'high',
            ];
        }

        return [
            'type' => 'input_file',
            'filename' => 'invoice.pdf',
            'file_data' => 'data:application/pdf;base64,'.base64_encode($contents),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function parseNumber(?array $payload): ?string
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
        $number = is_array($decoded) ? data_get($decoded, 'invoice_number') : null;

        return is_string($number) && trim($number) !== '' ? trim($number) : null;
    }

    private function instructions(): string
    {
        return implode("\n", [
            'You read the invoice number off a vendor invoice for a property management company. The attached file is a photo or a scanned invoice.',
            'invoice_number: the value printed beside "Invoice #", "Invoice No.", "Invoice Number" or similar, exactly as printed (e.g. "6624", "INV-2024-118", "00123").',
            'It is NOT the date, the amount, the balance, the account number, the work order number, the PO number, the customer number or a phone number.',
            'If no invoice number is printed, return null. Never guess or invent one.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'invoice_number',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['invoice_number'],
                'properties' => [
                    'invoice_number' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }
}
