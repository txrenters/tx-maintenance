<?php

namespace App\Services;

use App\Ai\Agents\HoaNoticeExtractionAgent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Promptable;
use Smalot\PdfParser\Parser;

/**
 * Turns an uploaded HOA violation notice PDF into a work order description:
 * extract the text with pdfparser, then have the AI summarize the violations.
 * Every failure degrades gracefully to a generic description so intake never
 * blocks on a bad PDF or an AI outage.
 */
class HoaNoticeExtractor
{
    public const FALLBACK_DESCRIPTION = 'HOA violation notice received — see the attached notice for details.';

    /**
     * @return array{
     *     description: string,
     *     violation_items: array<int, string>,
     *     notice_date: ?Carbon,
     *     hoa_name: ?string,
     *     source: string
     * }
     */
    public function extract(string $pdfContents): array
    {
        $text = $this->extractText($pdfContents);

        if ($text !== '' && $this->aiReady()) {
            $extracted = $this->extractWithAi($text);

            if ($extracted !== null) {
                return $extracted;
            }
        }

        return [
            'description' => self::FALLBACK_DESCRIPTION,
            'violation_items' => [],
            'notice_date' => null,
            'hoa_name' => null,
            'source' => 'fallback',
        ];
    }

    private function extractText(string $pdfContents): string
    {
        try {
            $text = (new Parser)->parseContent($pdfContents)->getText();

            return trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
        } catch (\Throwable $exception) {
            Log::warning('HOA notice PDF text extraction failed.', [
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Mirrors WorkOrderRecommendationService::aiStatus() — AI is optional and
     * the heuristic fallback must always work without it.
     */
    private function aiReady(): bool
    {
        if (! trait_exists(Promptable::class)) {
            return false;
        }

        $provider = (string) config('ai.default');
        $providerConfig = config("ai.providers.{$provider}", []);

        $ready = filled(data_get($providerConfig, 'driver')) && filled(data_get($providerConfig, 'key'));

        if ($provider === 'azure') {
            $ready = $ready && filled(data_get($providerConfig, 'url')) && filled(data_get($providerConfig, 'deployment'));
        }

        return $ready;
    }

    /**
     * @return array{description: string, violation_items: array<int, string>, notice_date: ?Carbon, hoa_name: ?string, source: string}|null
     */
    private function extractWithAi(string $text): ?array
    {
        try {
            $response = (new HoaNoticeExtractionAgent)->prompt(
                "HOA violation notice text:\n\n".Str::limit($text, 12000)
            );

            $description = trim((string) data_get($response, 'description', ''));

            if ($description === '') {
                return null;
            }

            return [
                'description' => $description,
                'violation_items' => collect(data_get($response, 'violation_items', []))
                    ->filter(fn ($item) => filled($item))
                    ->map(fn ($item) => trim((string) $item))
                    ->values()
                    ->all(),
                'notice_date' => $this->parseDate(data_get($response, 'notice_date')),
                'hoa_name' => filled(data_get($response, 'hoa_name')) ? trim((string) data_get($response, 'hoa_name')) : null,
                'source' => 'ai',
            ];
        } catch (\Throwable $exception) {
            Log::warning('HOA notice AI extraction failed; using fallback description.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            $date = Carbon::parse((string) $value);

            // Guard against hallucinated or mis-parsed dates far from today.
            return $date->between(now()->subYear(), now()->addMonth()) ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
