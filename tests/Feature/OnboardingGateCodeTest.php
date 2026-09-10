<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateBuildingCustomFieldsRequest;
use App\Jobs\GenerateOnboardingPdfJob;
use App\Jobs\GenerateW9PdfJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The owner onboarding form must capture whether the property is gated (with
 * the gate code), whether it has a sprinkler system, what
 * kind of fireplace it has and which utilities the HOA handles, and forward
 * the PropertyWare values untouched - the gate answer to the "Gated
 * Community? Gate Code?" text field, the sprinkler Yes/No to the "Yard
 * Features" picklist, the fireplace choice to the "Fireplace" text field and
 * the HOA utilities list (or "None") to the "Utilities Handled by HOA" text
 * field - so a vendor is never sent to a gate without a code, and the utility
 * companies, the listing and the tenant know about the sprinklers, the
 * fireplace and the HOA utilities.
 */
class OnboardingGateCodeTest extends TestCase
{
    use RefreshDatabase;

    private const BUILDING_ID = 4286939;

    private const GATE_FIELD = 'Gated Community? Gate Code?';

    private const SPRINKLER_FIELD = 'Yard Features';

    private const FIREPLACE_FIELD = 'Fireplace';

    private const HOA_UTILITIES_FIELD = 'Utilities Handled by HOA';

    private const ENDPOINT = '/api/buildings/'.self::BUILDING_ID.'/update-custom-fields';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateOnboardingPdfJob::class, GenerateW9PdfJob::class]);
        Http::preventStrayRequests();
        // The custom fields PUT is listed first: "buildings/*" would match it too.
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/buildings/customfields' => Http::response(['success' => true], 200),
            'api.propertyware.com/pw/api/rest/v1/buildings/*' => Http::response($this->buildingData(), 200),
        ]);
    }

    public function test_gate_and_sprinkler_answers_reach_propertyware_unchanged(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated_fields', [self::GATE_FIELD, self::SPRINKLER_FIELD, self::FIREPLACE_FIELD, self::HOA_UTILITIES_FIELD]);

        Http::assertSent(function (Request $request) {
            if (! $this->isCustomFieldsPut($request)) {
                return false;
            }

            $fields = collect($request->data()['fieldSetDTOS']);

            // sanitizeCustomFieldValue() must leave the canonical values alone
            $this->assertSame('Yes - Gate code: #4321', $fields->firstWhere('name', self::GATE_FIELD)['value']);
            $this->assertSame('Sprinkler System', $fields->firstWhere('name', self::SPRINKLER_FIELD)['value']);

            return true;
        });

        Bus::assertDispatched(GenerateOnboardingPdfJob::class, function (GenerateOnboardingPdfJob $job) {
            return ($job->formData['gatedCommunity'] ?? null) === 'Yes'
                && ($job->formData['gateCode'] ?? null) === '#4321'
                && ($job->formData['sprinklerSystem'] ?? null) === 'Sprinkler System';
        });
    }

    public function test_missing_gated_answer_is_rejected_with_a_friendly_message(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['gatedCommunity' => '', 'gateCode' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.gatedCommunity' => 'Please tell us whether the property is gated.',
            ]);

        Http::assertNothingSent();
    }

    public function test_gated_yes_without_a_code_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['gatedCommunity' => 'Yes', 'gateCode' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.gateCode' => 'Gate Code is required when the property is gated.',
            ]);

        Http::assertNothingSent();
    }

    public function test_gated_no_without_a_code_is_accepted(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['gatedCommunity' => 'No', 'gateCode' => ''],
            [['name' => self::GATE_FIELD, 'value' => 'No gate']],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::GATE_FIELD && $field['value'] === 'No gate'
            ));
    }

    public function test_missing_sprinkler_answer_is_rejected_with_a_friendly_message(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['sprinklerSystem' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.sprinklerSystem' => 'Please tell us whether the property has a sprinkler or irrigation system.',
            ]);

        Http::assertNothingSent();
    }

    public function test_an_answer_outside_the_yard_features_picklist_is_rejected_before_propertyware_sees_it(): void
    {
        // "No Sprinkler System" is not one of the picklist's options; sending it made
        // Propertyware reject the whole submission, so no owner could finish the form.
        $this->postJson(self::ENDPOINT, $this->validPayload(['sprinklerSystem' => 'No Sprinkler System']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.sprinklerSystem' => 'Please tell us whether the property has a sprinkler or irrigation system.',
            ]);

        Http::assertNothingSent();
    }

    public function test_sprinkler_no_is_saved_as_the_picklists_not_applicable_option(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['sprinklerSystem' => 'Not Applicable'],
            [['name' => self::SPRINKLER_FIELD, 'value' => 'Not Applicable']],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::SPRINKLER_FIELD && $field['value'] === 'Not Applicable'
            ));
    }

    public function test_lawn_irrigation_is_saved_as_the_picklists_own_option(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['sprinklerSystem' => 'Lawn Irrigation'],
            [['name' => self::SPRINKLER_FIELD, 'value' => 'Lawn Irrigation']],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::SPRINKLER_FIELD && $field['value'] === 'Lawn Irrigation'
            ));
    }

    public function test_fireplace_answer_reaches_propertyware_unchanged(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['fireplace' => 'Wood Burning Fireplace'],
            [['name' => self::FIREPLACE_FIELD, 'value' => 'Wood Burning Fireplace']],
        ))
            ->assertOk()
            ->assertJsonPath('updated_fields', [self::FIREPLACE_FIELD]);

        Http::assertSent(function (Request $request) {
            if (! $this->isCustomFieldsPut($request)) {
                return false;
            }

            $fields = collect($request->data()['fieldSetDTOS']);

            $this->assertSame('Wood Burning Fireplace', $fields->firstWhere('name', self::FIREPLACE_FIELD)['value']);

            return true;
        });

        Bus::assertDispatched(GenerateOnboardingPdfJob::class, function (GenerateOnboardingPdfJob $job) {
            return ($job->formData['fireplace'] ?? null) === 'Wood Burning Fireplace';
        });
    }

    public function test_no_fireplace_is_written_to_propertyware_as_text(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['fireplace' => 'No Fireplace'],
            [['name' => self::FIREPLACE_FIELD, 'value' => 'No Fireplace']],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::FIREPLACE_FIELD && $field['value'] === 'No Fireplace'
            ));
    }

    public function test_every_fireplace_choice_is_accepted(): void
    {
        foreach (UpdateBuildingCustomFieldsRequest::FIREPLACE_OPTIONS as $option) {
            $this->postJson(self::ENDPOINT, $this->validPayload(
                ['fireplace' => $option],
                [['name' => self::FIREPLACE_FIELD, 'value' => $option]],
            ))->assertOk();
        }
    }

    public function test_missing_fireplace_answer_is_rejected_with_a_friendly_message(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['fireplace' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.fireplace' => 'Please tell us what kind of fireplace the property has, or choose No Fireplace.',
            ]);

        Http::assertNothingSent();
    }

    public function test_unknown_fireplace_type_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['fireplace' => 'Pizza Oven']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.fireplace' => 'Please tell us what kind of fireplace the property has, or choose No Fireplace.',
            ]);

        Http::assertNothingSent();
    }

    public function test_hoa_utilities_list_reaches_propertyware_unchanged(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['utilitiesHandledByHoa' => true, 'hoaUtilities' => 'Water, Trash'],
            [['name' => self::HOA_UTILITIES_FIELD, 'value' => 'Water, Trash']],
        ))
            ->assertOk()
            ->assertJsonPath('updated_fields', [self::HOA_UTILITIES_FIELD]);

        Http::assertSent(function (Request $request) {
            if (! $this->isCustomFieldsPut($request)) {
                return false;
            }

            $fields = collect($request->data()['fieldSetDTOS']);

            $this->assertSame('Water, Trash', $fields->firstWhere('name', self::HOA_UTILITIES_FIELD)['value']);

            return true;
        });

        Bus::assertDispatched(GenerateOnboardingPdfJob::class, function (GenerateOnboardingPdfJob $job) {
            return ($job->formData['utilitiesHandledByHoa'] ?? null) === true
                && ($job->formData['hoaUtilities'] ?? null) === 'Water, Trash';
        });
    }

    public function test_no_hoa_utilities_is_written_to_propertyware_as_none(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['utilitiesHandledByHoa' => false, 'hoaUtilities' => ''],
            [['name' => self::HOA_UTILITIES_FIELD, 'value' => 'None']],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::HOA_UTILITIES_FIELD && $field['value'] === 'None'
            ));
    }

    public function test_hoa_utilities_ticked_without_a_list_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['utilitiesHandledByHoa' => true, 'hoaUtilities' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.hoaUtilities' => 'Please list the utilities the HOA handles.',
            ]);

        Http::assertNothingSent();
    }

    public function test_missing_hoa_utilities_answer_is_rejected_with_a_friendly_message(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(['utilitiesHandledByHoa' => null, 'hoaUtilities' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.utilitiesHandledByHoa' => 'Please tell us whether the HOA handles any utilities.',
            ]);

        $this->postJson(self::ENDPOINT, $this->validPayload(['utilitiesHandledByHoa' => 'Yes', 'hoaUtilities' => 'Water']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'formData.utilitiesHandledByHoa' => 'Please tell us whether the HOA handles any utilities.',
            ]);

        Http::assertNothingSent();
    }

    public function test_pasted_punctuation_in_the_hoa_utilities_list_is_flattened_for_propertyware(): void
    {
        $pasted = "Water \u{2013} Trash \u{201C}sewer\u{201D}";

        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['utilitiesHandledByHoa' => true, 'hoaUtilities' => $pasted],
            [['name' => self::HOA_UTILITIES_FIELD, 'value' => $pasted]],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::HOA_UTILITIES_FIELD && $field['value'] === 'Water - Trash "sewer"'
            ));
    }

    public function test_hoa_utilities_listed_one_per_line_keep_their_line_breaks_for_propertyware(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload(
            ['utilitiesHandledByHoa' => true, 'hoaUtilities' => "Water\nTrash\nSewer"],
            [['name' => self::HOA_UTILITIES_FIELD, 'value' => "Water\nTrash\nSewer"]],
        ))->assertOk();

        Http::assertSent(fn (Request $request) => $this->isCustomFieldsPut($request)
            && collect($request->data()['fieldSetDTOS'])->contains(
                fn (array $field) => $field['name'] === self::HOA_UTILITIES_FIELD && $field['value'] === "Water\nTrash\nSewer"
            ));
    }

    public function test_onboarding_pdf_prints_the_hoa_utilities(): void
    {
        $html = $this->renderOnboardingPdf([
            'utilitiesHandledByHoa' => true,
            'hoaUtilities' => 'Water, Trash',
        ]);

        $this->assertMatchesRegularExpression('#<td>Utilities Handled by HOA</td>\s*<td>Yes</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>HOA-Covered Utilities</td>\s*<td>Water, Trash</td>#', $html);
    }

    public function test_onboarding_pdf_prints_hoa_utilities_listed_one_per_line_on_separate_lines(): void
    {
        $html = $this->renderOnboardingPdf([
            'utilitiesHandledByHoa' => true,
            'hoaUtilities' => "Water\nTrash & recycling\n<b>Sewer</b>",
        ]);

        $this->assertMatchesRegularExpression(
            '#<td>HOA-Covered Utilities</td>\s*<td>Water<br />\s*Trash &amp; recycling<br />\s*&lt;b&gt;Sewer&lt;/b&gt;</td>#',
            $html,
        );
    }

    public function test_onboarding_pdf_omits_the_hoa_utilities_list_when_there_are_none(): void
    {
        $html = $this->renderOnboardingPdf([
            'utilitiesHandledByHoa' => false,
            'hoaUtilities' => '',
        ]);

        $this->assertMatchesRegularExpression('#<td>Utilities Handled by HOA</td>\s*<td>No</td>#', $html);
        $this->assertStringNotContainsString('<td>HOA-Covered Utilities</td>', $html);
    }

    public function test_onboarding_pdf_prints_the_gate_code_and_sprinkler_details(): void
    {
        $html = $this->renderOnboardingPdf([
            'gatedCommunity' => 'Yes',
            'gateCode' => '#4321',
            'sprinklerSystem' => 'Sprinkler System',
            'fireplace' => 'Wood Burning Fireplace',
        ]);

        $this->assertMatchesRegularExpression('#<td>Gated Community</td>\s*<td>Yes</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Gate Code</td>\s*<td>\#4321</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Sprinkler / Irrigation System</td>\s*<td>Sprinkler System</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Fireplace</td>\s*<td>Wood Burning Fireplace</td>#', $html);
    }

    public function test_onboarding_pdf_omits_the_gate_code_and_sprinkler_details_when_there_are_none(): void
    {
        $html = $this->renderOnboardingPdf([
            'gatedCommunity' => 'No',
            'gateCode' => '',
            'sprinklerSystem' => 'Not Applicable',
            'fireplace' => 'No Fireplace',
        ]);

        $this->assertMatchesRegularExpression('#<td>Gated Community</td>\s*<td>No</td>#', $html);
        $this->assertStringNotContainsString('<td>Gate Code</td>', $html);
        $this->assertMatchesRegularExpression('#<td>Sprinkler / Irrigation System</td>\s*<td>Not Applicable</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Fireplace</td>\s*<td>No Fireplace</td>#', $html);
    }

    private function isCustomFieldsPut(Request $request): bool
    {
        return $request->method() === 'PUT'
            && str_ends_with($request->url(), '/buildings/customfields');
    }

    /**
     * Render the view exactly the way GenerateOnboardingPdfJob::handle() feeds it.
     *
     * @param  array<string, mixed>  $formOverrides
     */
    private function renderOnboardingPdf(array $formOverrides): string
    {
        return view('onboarding_process', [
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
            'formData' => $this->validFormData($formOverrides),
            'buildingData' => $this->buildingData(),
            'propertywareData' => ['entityId' => self::BUILDING_ID, 'fieldSetDTOS' => []],
            'ownerName' => 'Jane Owner',
            'generated_at' => '2026-08-27 10:00 AM',
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildingData(): array
    {
        return [
            'id' => self::BUILDING_ID,
            'name' => '5200 Weslayan Street Unit #A201',
            'abbreviation' => 'WES',
            'countUnit' => 1,
            'propertyType' => 'Condo',
            'type' => 'RESIDENTIAL',
            'rentable' => true,
            'address' => [
                'address' => '5200 Weslayan St',
                'addressCont' => 'Unit A201',
                'city' => 'Houston',
                'stateRegion' => 'TX',
                'postalCode' => '77005',
                'country' => 'United States',
            ],
        ];
    }

    /**
     * Every field the Form Request requires, with the gate and sprinkler answers filled in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validFormData(array $overrides = []): array
    {
        return array_merge([
            // Gate access
            'gatedCommunity' => 'Yes',
            'gateCode' => '#4321',
            // Garage Access & Mailbox (form/PDF only)
            'garageDoorOpener' => 'Yes',
            'garageDoorRemote' => '2',
            'lockboxCode' => '1111',
            'mailboxKeyNo' => '2',
            'mailboxLocation' => 'Front door',
            // Sprinkler / Irrigation System
            'sprinklerSystem' => 'Sprinkler System',
            // Utilities handled by the HOA
            'utilitiesHandledByHoa' => true,
            'hoaUtilities' => 'Water, Trash',
            // Fireplace
            'fireplace' => 'Gas Connections',
            // Pets (read by updatePetFields)
            'dogsAllowed' => 'No',
            'catsAllowed' => 'No',
            'petOtherAllowed' => 'No',
            // HVAC & Home Warranty
            'hvacMaintenancePlan' => 'Yes',
            'installFloatSwitch' => 'Yes',
            'homeWarranty' => 'No',
            'homeWarrantyCompanyName' => '',
            'homeWarrantyServiceNumber' => '',
            'homeWarrantyContactNumber' => '',
            // Critical locations
            'gasShutoffValveLocation' => 'Left side of house',
            'breakerBoxLocation' => 'Garage',
            'hvacFilterLocation1' => 'Hallway ceiling',
            'hvacFilterSize1' => '20x25x1',
            'hvacFilterLocation2' => '',
            'hvacFilterSize2' => '',
            'hvacFilterLocation3' => '',
            'hvacFilterSize3' => '',
            'hvacFilterLocation4' => '',
            'hvacFilterSize4' => '',
            // W-9
            'w9_entity_name' => 'Jane Owner',
            'w9_business_name' => '',
            'w9_tax_class' => 'Individual/sole proprietor',
            'w9_tax_class1' => '',
            'w9_llc_tax_class' => '',
            'w9_other_tax_class' => '',
            'w9_exempt_payee_code' => '',
            'w9_exempt_reporting_code' => '',
            'w9_address' => '100 Main St',
            'w9_address2' => 'Houston, TX 77005',
            'w9_account_list' => '',
            'w9_requester_name_and_address' => '',
            'w9_ssn' => '123456789',
            'w9_ein' => '',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $formOverrides
     * @param  array<int, array{name: string, value: string}>|null  $fieldSetDTOS
     * @return array<string, mixed>
     */
    private function validPayload(array $formOverrides = [], ?array $fieldSetDTOS = null): array
    {
        return [
            'propertywareData' => [
                'entityId' => self::BUILDING_ID,
                'fieldSetDTOS' => $fieldSetDTOS ?? [
                    ['name' => self::GATE_FIELD, 'value' => 'Yes - Gate code: #4321'],
                    ['name' => self::SPRINKLER_FIELD, 'value' => 'Sprinkler System'],
                    ['name' => self::FIREPLACE_FIELD, 'value' => 'Gas Connections'],
                    ['name' => self::HOA_UTILITIES_FIELD, 'value' => 'Water, Trash'],
                ],
            ],
            'formData' => $this->validFormData($formOverrides),
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
            'ownerName' => 'Jane Owner',
            'maintenanceNotice' => null,
        ];
    }
}
