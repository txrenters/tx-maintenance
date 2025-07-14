<?php

require_once __DIR__ . '/vendor/autoload.php';

use Barryvdh\DomPDF\Facade\Pdf;

// Sample data for PDF generation
$sampleData = [
    'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
    'formData' => [
        'paint' => 'Yes',
        'paintColor' => '#ffffff',
        'goingOnTheMarketLawnCare' => 'Management',
        'goingOnTheMarketCleaning' => 'Management',
        'goingOnTheMarketDebrisRemoval' => 'Owner',
        'goingOnTheMarketPaint' => 'Management',
        'goingOnTheMarketCarpetCleaning' => 'Management',
        'goingOnTheMarketCarpetReplacement' => 'Owner',
        'goingOnTheMarketUtilities' => 'Owner',
        'homeOnTheMarketLawnCare' => 'Management',
        'homeOnTheMarketCleaning' => 'Management',
        'homeOnTheMarketUtilities' => 'Owner',
        'beforeTenantMoveInLawnCare' => 'Management',
        'beforeTenantMoveInPestControl' => 'Management',
        'afterTenantMoveInLawnCare' => 'Tenant',
        'reKey' => 'Management',
        'tenantServiceRequest' => 'Use discretion on $75 co-payment',
        'dogsAllowed' => 'Yes',
        'dogsMaxWeight' => '50',
        'catsAllowed' => 'Yes',
        'catRestrictions' => 'No declawing required',
        'otherPetsRestriction' => 'No exotic pets',
        'swimmingPool' => 'Yes',
        'poolService' => 'Yes',
        'poolServiceName' => 'Crystal Clear Pool Service',
        'poolServiceNumber' => '(555) 123-4567',
        'alarmSystem' => 'Yes',
        'alarmSystemIncludedInPrice' => 'No',
        'alarmSystemUnderContract' => 'Yes',
        'alarmSystemCode' => '1234',
        'alarmSystemBeArmDuringMarketing' => 'Yes',
        'communityPool' => 'Yes',
        'park' => 'Yes',
        'playGround' => 'Yes',
        'tennisCourt' => 'No',
        'refrigerator' => 'Yes',
        'microwave' => 'Yes',
        'washingMachine' => 'No',
        'dryer' => 'No',
        'waterSoftener' => 'No',
        'hvacModelYear' => '2018',
        'garageDoorOpener' => 'Yes',
        'garageDoorRemote' => '2',
        'mailboxKeyNo' => '2',
        'mailboxLocation' => 'Front of house',
        'hvacVendorName' => 'ABC HVAC Services',
        'hvacVendorNumber' => '(555) 234-5678',
        'electricVendorName' => 'Electric Pro',
        'electricVendorNumber' => '(555) 345-6789',
        'plumbingVendorName' => 'Plumber Plus',
        'plumbingVendorNumber' => '(555) 456-7890',
        'pestControlVendorName' => 'Pest Away',
        'pestControlVenodrNumber' => '(555) 567-8901',
        'lawnCareVendorName' => 'Green Lawn Care',
        'lawnCareVendorNumber' => '(555) 678-9012',
        'waterProvider' => 'City Water',
        'gasProvider' => 'Texas Gas Co',
        'trashProvider' => 'Waste Management',
        'trashPickupDays' => 'Monday & Thursday',
        'hvacMaintenancePlan' => 'Yes',
        'installFloatSwitch' => 'Yes',
        'homeWarranty' => 'Yes',
        'homeWarrantyCompanyName' => 'Home Shield',
        'floodedProperty' => 'No',
        'floodedPropertyDate' => '',
        'otherComments' => 'The property has recently been updated with new flooring throughout the main living areas. All appliances are in excellent working condition.'
    ],
    'buildingData' => [
        'name' => 'Sample Property - 123 Main Street',
        'id' => '12345',
        'address' => [
            'address' => '123 Main Street',
            'addressCont' => 'Unit A',
            'city' => 'Dallas',
            'stateRegion' => 'TX',
            'postalCode' => '75201'
        ]
    ],
    'propertywareData' => [
        'entityId' => 12345,
        'fieldSetDTOS' => [
            ['name' => 'paint', 'value' => 'Yes'],
            ['name' => 'paintColor', 'value' => '#ffffff'],
            ['name' => 'dogsAllowed', 'value' => 'Yes']
        ]
    ],
    'generated_at' => date('Y-m-d H:i:s')
];

try {
    // Generate PDF
    $pdf = Pdf::loadView('onboarding_process', $sampleData);
    
    // Save to file
    $filename = 'sample_property_onboarding_' . date('YmdHis') . '.pdf';
    $pdf->save($filename);
    
    echo "Sample PDF generated successfully: " . $filename . "\n";
    echo "File size: " . number_format(filesize($filename) / 1024, 2) . " KB\n";
    
} catch (Exception $e) {
    echo "Error generating PDF: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}