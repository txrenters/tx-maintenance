<?php

namespace Tests\Unit;

use App\Services\CrystalCreekCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The coordinator's fixed Category + Scope of Work lists for Crystal Creek
 * work orders (10-08).
 */
class CrystalCreekCatalogTest extends TestCase
{
    public function test_only_hvac_and_pest_control_are_offered(): void
    {
        $this->assertSame(['HVAC', 'Pest Control'], CrystalCreekCatalog::categories());
        $this->assertTrue(CrystalCreekCatalog::isCategory('hvac'));
        $this->assertTrue(CrystalCreekCatalog::isCategory(' Pest Control '));
        $this->assertFalse(CrystalCreekCatalog::isCategory('Plumbing'));
        $this->assertFalse(CrystalCreekCatalog::isCategory(null));
        $this->assertSame('Pest Control', CrystalCreekCatalog::canonicalCategory('PEST CONTROL'));
    }

    public function test_hvac_scopes_are_grouped_and_pest_control_is_one_flat_list(): void
    {
        $hvac = CrystalCreekCatalog::scopesFor('HVAC');
        $this->assertSame(
            ['Service', 'Refrigerant Services', 'Coil and Compressor Repairs', 'Airflow and Ductwork', 'Maintenance', 'Equipment Replacement', 'Miscellaneous'],
            array_keys($hvac)
        );
        foreach ($hvac as $group => $items) {
            $this->assertNotEmpty($items, "HVAC group {$group} is empty");
        }
        $this->assertContains('Capacitor Replacement', $hvac['Service']);
        $this->assertContains('R-410A Refrigerant', $hvac['Refrigerant Services']);
        $this->assertContains('Drain Pan Replacement', $hvac['Miscellaneous']);

        $pest = CrystalCreekCatalog::scopesFor('Pest Control');
        $this->assertSame([''], array_keys($pest));
        $this->assertCount(18, $pest['']);
        $this->assertContains('Quarterly Preventive Pest Service', $pest['']);

        $this->assertSame([], CrystalCreekCatalog::scopesFor('Plumbing'));
        $this->assertSame([], CrystalCreekCatalog::allScopesFor(null));
    }

    public function test_every_scope_is_unique_and_short_enough_for_the_column(): void
    {
        foreach (CrystalCreekCatalog::categories() as $category) {
            $all = CrystalCreekCatalog::allScopesFor($category);
            $this->assertSame($all, array_values(array_unique($all)), "{$category} repeats a scope");
            foreach ($all as $scope) {
                $this->assertLessThanOrEqual(120, mb_strlen($scope));
                $this->assertSame($scope, trim($scope));
            }
        }
    }

    public function test_a_scope_belongs_to_its_own_category_only(): void
    {
        $this->assertTrue(CrystalCreekCatalog::isScopeOf('HVAC', 'Capacitor Replacement'));
        $this->assertTrue(CrystalCreekCatalog::isScopeOf('hvac', ' Capacitor Replacement '));
        $this->assertTrue(CrystalCreekCatalog::isScopeOf('Pest Control', 'Ant Treatment'));
        $this->assertFalse(CrystalCreekCatalog::isScopeOf('HVAC', 'Ant Treatment'));
        $this->assertFalse(CrystalCreekCatalog::isScopeOf('Pest Control', 'Capacitor Replacement'));
        $this->assertFalse(CrystalCreekCatalog::isScopeOf('HVAC', 'Repair'));
        $this->assertFalse(CrystalCreekCatalog::isScopeOf('HVAC', null));
        $this->assertFalse(CrystalCreekCatalog::isScopeOf(null, 'Capacitor Replacement'));
    }

    public function test_the_form_shape_carries_the_groups_in_order(): void
    {
        $form = CrystalCreekCatalog::forForm();

        $this->assertSame(['HVAC', 'Pest Control'], $form['categories']);
        $this->assertSame('Service', $form['scopes']['HVAC'][0]['label']);
        $this->assertSame('Diagnostic / Service Call (Business Hours)', $form['scopes']['HVAC'][0]['items'][0]);
        $this->assertCount(7, $form['scopes']['HVAC']);
        $this->assertSame('', $form['scopes']['Pest Control'][0]['label']);
        $this->assertSame('Pest Control Inspection', $form['scopes']['Pest Control'][0]['items'][0]);
    }
}
