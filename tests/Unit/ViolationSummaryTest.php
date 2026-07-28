<?php

namespace Tests\Unit;

use App\Services\TenantPortalLinkService;
use PHPUnit\Framework\TestCase;

class ViolationSummaryTest extends TestCase
{
    public function test_propertyware_corrective_action_blocks_become_a_numbered_list(): void
    {
        $description = "Inspection Date: 07/15/2026\n"
            ."Corrective Action: [Artificial Flowers / Plants] - Please remove the artificial flowers/plants from your landscaping and replace them with live plant material.\n"
            ."\n"
            ."Inspection Date: 07/15/2026\n"
            .'Corrective Action: [Weed] - Please treat your lawn for weeds.';

        $summary = TenantPortalLinkService::violationSummaryFromDescription($description);

        $this->assertSame(
            '(1) Please remove the artificial flowers/plants from your landscaping and replace them with live plant material (2) Please treat your lawn for weeds',
            $summary
        );
    }

    public function test_single_corrective_action_has_no_numbering_or_date_noise(): void
    {
        $description = "Inspection Date: 07/15/2026\n"
            .'Corrective Action: [Trash Cans] - Please store your trash cans out of street view.';

        $summary = TenantPortalLinkService::violationSummaryFromDescription($description);

        $this->assertSame('Please store your trash cans out of street view', $summary);
        $this->assertStringNotContainsString('Inspection Date', $summary);
        $this->assertStringNotContainsString('[', $summary);
    }

    public function test_duplicate_corrective_actions_are_collapsed(): void
    {
        $description = "Inspection Date: 07/01/2026\n"
            ."Corrective Action: [Weed] - Please treat your lawn for weeds.\n"
            ."Inspection Date: 07/15/2026\n"
            .'Corrective Action: [Weed] - Please treat your lawn for weeds.';

        $this->assertSame(
            'Please treat your lawn for weeds',
            TenantPortalLinkService::violationSummaryFromDescription($description)
        );
    }

    public function test_items_to_correct_list_still_wins(): void
    {
        $description = "HOA notice about landscaping.\n"
            ."Items to correct:\n"
            ."- Mow the lawn\n"
            ."- Trim the hedges\n"
            .'Notice issued by: Demo HOA';

        $this->assertSame(
            'Mow the lawn; Trim the hedges',
            TenantPortalLinkService::violationSummaryFromDescription($description)
        );
    }

    public function test_long_summaries_are_not_cut_mid_word(): void
    {
        $long = 'Please remove the seasonal decorations from the front porch and store them away, '
            .'pressure-wash the driveway to remove oil staining, repaint the mailbox post in the approved color, '
            .'and replace the dead shrubs along the walkway with approved live plant material immediately';
        $description = "Inspection Date: 07/15/2026\nCorrective Action: [General] - {$long}.";

        $summary = TenantPortalLinkService::violationSummaryFromDescription($description);

        $this->assertLessThanOrEqual(264, strlen($summary));
        $this->assertStringEndsWith('...', $summary);
        // Word-safe truncation: the fragment right before the ellipsis is a whole word from the source.
        $lastWord = preg_replace('/^.*\s/s', '', substr($summary, 0, -3));
        $this->assertStringContainsString($lastWord.' ', $long.' ');
    }

    public function test_plain_description_falls_back_to_lead_sentence(): void
    {
        $this->assertSame(
            'Lawn needs mowing and edging.',
            TenantPortalLinkService::violationSummaryFromDescription('Lawn needs mowing and edging.')
        );
    }
}
