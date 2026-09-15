<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Reads the vendor's own invoice number out of an uploaded invoice file.
 *
 * A PDF made by software (Jobber, an invoicing app, a browser's print-to-PDF)
 * carries its text, so the number is read from that text layer on our own
 * server at no cost. A photo, or a PDF that is really a scanned picture, has
 * no text; those go to the vision model when it is configured. Nothing here
 * blocks an upload: any failure yields null and the box stays blank for the
 * office to type.
 */
class InvoiceNumberExtractor
{
    /** Below this many characters of real text, treat the PDF as a scan. */
    private const SCANNED_TEXT_THRESHOLD = 40;

    /**
     * How many lines past an "Invoice #" label to look for the value. Invoice
     * layouts often put the labels in one column and the values in the next,
     * which the text layer flattens into label lines followed by value lines.
     */
    private const LOOKAHEAD_LINES = 8;

    /**
     * The label the number sits beside: "Invoice #", "Invoice No.", "Invoice
     * Number", "Inv #", "Invoice ID" or "Invoice:". A bare "INVOICE" heading
     * does not count, or the first digits on the page would be taken.
     */
    private const LABEL = '/\b(?:invoice|inv)\.?\s*(?:#|n[or]\.?|number|num\.?|nbr\.?|id)\s*[:#\-]?\s*|\binvoice\s*:\s*#?\s*/i';

    /**
     * A number as printed on an invoice: digits, maybe a short prefix, dashes
     * or slashes, and nothing else. Anything with spaces is a sentence, a
     * date or an address, never the number.
     */
    private const VALUE = '/^#?([A-Za-z]{0,4}[-]?\d[\w\-\/\.]{0,29})$/';

    /** "INV-2024-118" style references stand on their own without a label. */
    private const INV_TOKEN = '/\bINV[-\s]?\d[\w\-]{0,29}/i';

    public function __construct(
        private readonly InvoiceNumberVisionExtractor $vision = new InvoiceNumberVisionExtractor,
    ) {}

    /**
     * @param  string  $contents  raw PDF or image bytes
     * @param  string  $mime  the file's MIME type (application/pdf or image/*)
     */
    public function extract(string $contents, string $mime): ?string
    {
        if (! str_starts_with($mime, 'image/')) {
            $text = $this->pdfText($contents);

            // A PDF with a text layer is read here or not at all: sending it
            // on to the vision model would only pay to read the same words.
            if (mb_strlen($text) >= self::SCANNED_TEXT_THRESHOLD) {
                return $this->fromText($text);
            }
        }

        return $this->clean($this->vision->extract($contents, $mime));
    }

    /**
     * The invoice number in already-extracted text, or null when no labelled
     * number is found.
     */
    public function fromText(string $text): ?string
    {
        $lines = $this->lines($text);

        foreach ($lines as $index => $line) {
            if (! preg_match(self::LABEL, $line, $label, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // Same line, right after the label: "Invoice # 6624".
            $after = trim(substr($line, $label[0][1] + strlen($label[0][0])));
            $candidate = $this->valueAtStart($after);

            if ($candidate !== null) {
                return $candidate;
            }

            // Column layout: the value sits on one of the next few lines, past
            // the other labels ("Date", "Balance", "Due On" carry no digits).
            for ($ahead = 1; $ahead <= self::LOOKAHEAD_LINES; $ahead++) {
                $next = $lines[$index + $ahead] ?? null;

                if ($next === null) {
                    break;
                }

                $candidate = $this->valueAtStart($next);

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        if (preg_match(self::INV_TOKEN, $text, $inv)) {
            return $this->clean($inv[0]);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        $text = str_replace(["\u{00A0}", "\t"], ' ', $text);

        return array_values(array_filter(
            array_map(fn (string $line) => trim(preg_replace('/ {2,}/', ' ', $line) ?? ''), preg_split('/\R/', $text) ?: []),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * The value when the text starts with one, or null. Only the first word
     * is considered so a sentence or an address can never be mistaken for it.
     */
    private function valueAtStart(string $text): ?string
    {
        $token = preg_split('/\s+/', trim($text))[0] ?? '';
        $token = rtrim($token, '.,;:');

        if ($token === '' || ! preg_match(self::VALUE, $token, $match)) {
            return null;
        }

        return $this->isNoise($match[1]) ? null : $this->clean($match[1]);
    }

    /**
     * Dates, amounts and phone numbers also sit near the label and are made
     * of digits; none of them is the number accounting files the bill under.
     */
    private function isNoise(string $token): bool
    {
        return (bool) preg_match('/^\d{1,4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,4}$/', $token)   // 09/15/2026, 2026-09-15
            || (bool) preg_match('/^\d+\.\d{2}$/', $token)                            // 638.00
            || (bool) preg_match('/^\d{3}-\d{3}-\d{4}$/', $token);                   // 346-787-1295
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $value = trim($value, ' #.,;:');

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        return mb_substr($value, 0, 100);
    }

    private function pdfText(string $pdfContents): string
    {
        try {
            $document = (new Parser)->parseContent($pdfContents);
            $pages = $document->getPages();

            // The number is on the first page; later pages are line items.
            $text = $pages === [] ? (string) $document->getText() : (string) $pages[0]->getText();

            return trim($text);
        } catch (\Throwable $exception) {
            Log::warning('Invoice PDF text extraction failed.', [
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }
}
