<?php

namespace Tests\Unit;

use App\Models\WorkOrder;
use Tests\TestCase;

/**
 * PropertyWare's Source field is the only record of who raised a work order:
 * the tenant portal and the website are the tenant's channels, everything
 * else was typed in by our team.
 */
class WorkOrderStaffCreatedTest extends TestCase
{
    public function test_every_source_our_team_types_in_counts_as_staff_created(): void
    {
        $staffSources = [
            'None',
            'Telephone',
            'Inspection',
            'Internal',
            'Email',
            'In Person',
            'Property Marketing',
            // PropertyWare picklists carry trailing spaces.
            ' None ',
            // A value PropertyWare has not shown yet is still something staff picked.
            'Text Message',
        ];

        foreach ($staffSources as $source) {
            $this->assertTrue(
                (new WorkOrder(['source' => $source]))->isStaffCreated(),
                "Source [{$source}] should count as entered by our team.",
            );
        }
    }

    public function test_the_tenant_channels_and_an_unknown_source_do_not(): void
    {
        foreach (['Tenant Portal', ' Tenant Portal ', 'Website', null, '', '  '] as $source) {
            $this->assertFalse(
                (new WorkOrder(['source' => $source]))->isStaffCreated(),
                'Source ['.var_export($source, true).'] should keep the request-received wording.',
            );
        }
    }

    public function test_an_import_may_not_wipe_the_tenant_portal_stamp_with_none(): void
    {
        // PropertyWare reports the app's own API-created work orders as "None".
        $this->assertFalse(WorkOrder::importedSourceReplaces('Tenant Portal', 'None'));
        $this->assertFalse(WorkOrder::importedSourceReplaces('Tenant Portal', null));
        $this->assertFalse(WorkOrder::importedSourceReplaces('Tenant Portal', ''));

        // A real Source PropertyWare later shows still wins.
        $this->assertTrue(WorkOrder::importedSourceReplaces('Tenant Portal', 'Telephone'));

        // Nothing else on file is protected.
        $this->assertTrue(WorkOrder::importedSourceReplaces('Website', 'None'));
        $this->assertTrue(WorkOrder::importedSourceReplaces('None', 'Tenant Portal'));
        $this->assertTrue(WorkOrder::importedSourceReplaces(null, 'None'));
    }
}
