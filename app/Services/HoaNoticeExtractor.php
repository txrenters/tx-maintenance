<?php

namespace App\Services;

use App\Ai\Agents\HoaNoticeExtractionAgent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/**
 * Turns an uploaded HOA violation PDF into one or more structured notices.
 *
 * A single upload may hold several notices — different properties, HOAs, and
 * deadlines — so extraction returns a list. The text is read with pdfparser
 * (page by page, so each notice can be traced back to its page) and the AI
 * segments and summarizes it. Every failure degrades gracefully to a single
 * generic notice so intake never blocks on a bad PDF or an AI outage.
 */
class HoaNoticeExtractor
{
    public const FALLBACK_DESCRIPTION = 'HOA violation notice received — see the attached notice for details.';

    /** Below this many characters of real text, treat the PDF as a scan. */
    private const SCANNED_TEXT_THRESHOLD = 40;

    public function __construct(
        private readonly HoaNoticeVisionExtractor $vision = new HoaNoticeVisionExtractor,
    ) {}

    /**
     * @return array<int, array{
     *     page: int,
     *     property_address: ?string,
     *     description: string,
     *     violation_items: array<int, string>,
     *     hoa_name: ?string,
     *     notice_date: ?Carbon,
     *     deadline_date: ?Carbon,
     *     deadline_days: ?int,
     *     source: string
     * }>
     */
    public function extractNotices(string $contents, string $mime = 'application/pdf'): array
    {
        $isPdf = ! str_starts_with($mime, 'image/');

        // Images have no text layer; only PDFs are worth parsing for text.
        [$fullText, $pageCount] = $isPdf ? $this->extractText($contents) : ['', 1];

        if ($this->aiReady()) {
            // Digital PDF: read the text layer (cheap, no image tokens).
            if ($isPdf && $this->hasMeaningfulText($fullText)) {
                $notices = $this->extractFromText($fullText, $pageCount);

                if ($notices !== null && $notices !== []) {
                    return $notices;
                }
            }

            // Scanned PDF or an uploaded image: send the document itself to the
            // vision model.
            $notices = $this->extractFromDocument($contents, $pageCount, $mime);

            if ($notices !== null && $notices !== []) {
                return $notices;
            }
        }

        // Fallback: one generic notice for the whole document so intake can
        // still proceed (staff pick the property and edit the description).
        return [$this->fallbackNotice()];
    }

    /**
     * @return array{0: string, 1: int} the page-marked text and the page count
     */
    private function extractText(string $pdfContents): array
    {
        try {
            $document = (new Parser)->parseContent($pdfContents);
            $pages = $document->getPages();

            if ($pages === []) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $document->getText()) ?? '');

                return [$text, 1];
            }

            $marked = [];

            foreach ($pages as $index => $page) {
                $pageText = trim(preg_replace('/\s+/', ' ', (string) $page->getText()) ?? '');
                $marked[] = '===== PAGE '.($index + 1)." =====\n".$pageText;
            }

            return [trim(implode("\n\n", $marked)), count($pages)];
        } catch (\Throwable $exception) {
            Log::warning('HOA notice PDF text extraction failed.', [
                'error' => $exception->getMessage(),
            ]);

            return ['', 1];
        }
    }

    /**
     * AI is optional and the fallback must always work without it.
     */
    private function aiReady(): bool
    {
        return AiSettings::ready();
    }

    /**
     * True once the PDF carries enough real text (ignoring the page markers) to
     * read from the text layer; below the threshold it is treated as a scan.
     */
    private function hasMeaningfulText(string $markedText): bool
    {
        $withoutMarkers = trim(preg_replace('/=+ PAGE \d+ =+/', '', $markedText) ?? '');

        return mb_strlen($withoutMarkers) >= self::SCANNED_TEXT_THRESHOLD;
    }

    /**
     * Digital PDF path: read the text layer with the structured agent.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function extractFromText(string $text, int $pageCount): ?array
    {
        try {
            $response = (new HoaNoticeExtractionAgent)->prompt(
                "HOA violation document text (multiple notices possible):\n\n".Str::limit($text, 30000)
            );

            $rawNotices = data_get($response, 'notices', []);

            return is_iterable($rawNotices) ? $this->normalizeNotices($rawNotices, $pageCount, 'ai') : null;
        } catch (\Throwable $exception) {
            Log::warning('HOA notice text extraction failed; will try the document vision path.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Scanned PDF path: send the document itself to the vision model.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function extractFromDocument(string $contents, int $pageCount, string $mime): ?array
    {
        $rawNotices = $this->vision->extract($contents, $mime);

        return $rawNotices === null ? null : $this->normalizeNotices($rawNotices, $pageCount, 'vision');
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rawNotices
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNotices(iterable $rawNotices, int $pageCount, string $source): array
    {
        $notices = [];

        foreach ($rawNotices as $raw) {
            $description = trim((string) data_get($raw, 'description', ''));

            if ($description === '') {
                continue;
            }

            $page = (int) data_get($raw, 'page', 1);

            $notices[] = [
                'page' => $page >= 1 && $page <= $pageCount ? $page : 1,
                'property_address' => $this->cleanString(data_get($raw, 'property_address')),
                'description' => $description,
                'violation_items' => collect(data_get($raw, 'violation_items', []))
                    ->filter(fn ($item) => filled($item))
                    ->map(fn ($item) => trim((string) $item))
                    ->values()
                    ->all(),
                'hoa_name' => $this->cleanString(data_get($raw, 'hoa_name')),
                'notice_date' => $this->parseDate(data_get($raw, 'notice_date')),
                'deadline_date' => $this->parseDate(data_get($raw, 'deadline_date'), monthsAhead: 6),
                'deadline_days' => $this->parseDays(data_get($raw, 'deadline_days')),
                'source' => $source,
            ];
        }

        return $notices;
    }

    /**
     * @return array{page: int, property_address: null, description: string, violation_items: array<int, string>, hoa_name: null, notice_date: null, deadline_date: null, deadline_days: null, source: string}
     */
    private function fallbackNotice(): array
    {
        return [
            'page' => 1,
            'property_address' => null,
            'description' => self::FALLBACK_DESCRIPTION,
            'violation_items' => [],
            'hoa_name' => null,
            'notice_date' => null,
            'deadline_date' => null,
            'deadline_days' => null,
            'source' => 'fallback',
        ];
    }

    private function cleanString(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }

    private function parseDays(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        $days = (int) $value;

        return $days > 0 && $days <= 365 ? $days : null;
    }

    private function parseDate(mixed $value, int $monthsAhead = 1): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            $date = Carbon::parse((string) $value);

            // Guard against hallucinated or mis-parsed dates far from today.
            return $date->between(now()->subYear(), now()->addMonths($monthsAhead)) ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
