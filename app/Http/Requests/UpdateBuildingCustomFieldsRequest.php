<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBuildingCustomFieldsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'propertywareData' => 'required|array',
            'propertywareData.entityId' => 'required|integer',
            'propertywareData.fieldSetDTOS' => 'required|array',
            'propertywareData.fieldSetDTOS.*.name' => 'required|string',
            'propertywareData.fieldSetDTOS.*.value' => 'required|string',
            'formData' => 'required|array',
            'signature' => 'required|string',
            'maintenanceNotice' => 'nullable|string',

            // W-9 Form Validation
            'formData.w9_entity_name' => 'required|string|max:255',
            'formData.w9_business_name' => 'nullable|string|max:255',
            'formData.w9_tax_class' => 'required|string',
            'formData.w9_tax_class1' => 'nullable|string', // Field 3b - Optional
            'formData.w9_llc_tax_class' => 'nullable|string|max:1',
            'formData.w9_other_tax_class' => 'nullable|string|max:255',
            'formData.w9_exempt_payee_code' => 'nullable|string|max:50', // Field 4 - Optional
            'formData.w9_exempt_reporting_code' => 'nullable|string|max:50', // Field 4 - Optional
            'formData.w9_address' => 'required|string|max:255',
            'formData.w9_address2' => 'required|string|max:255',
            'formData.w9_account_list' => 'nullable|string|max:255', // Field 7 - Optional
            'formData.w9_requester_name_and_address' => 'nullable|string|max:500',

            // Either SSN or EIN is required
            'formData.w9_ssn' => 'required_without:formData.w9_ein|nullable|string|size:9',
            'formData.w9_ein' => 'required_without:formData.w9_ssn|nullable|string|size:9',

            // HVAC & Home Warranty Validation
            'formData.hvacMaintenancePlan' => 'required|string|in:Yes,No',
            'formData.installFloatSwitch' => 'required|string|in:Yes,No',

            // Gate Access (stored in the "Gated Community? Gate Code?" custom field)
            'formData.gatedCommunity' => 'required|string|in:Yes,No',
            'formData.gateCode' => 'required_if:formData.gatedCommunity,Yes|nullable|string|max:255',

            // Sprinkler / Irrigation System (Yes/No stored in the "Yard Features" picklist; details on the PDF only)
            'formData.sprinklerSystem' => 'required|string|in:Yes,No',
            'formData.sprinklerControllerLocation' => 'required_if:formData.sprinklerSystem,Yes|nullable|string|max:255',
            'formData.sprinklerNotes' => 'nullable|string|max:500',

            // Critical Location Information
            'formData.gasShutoffValveLocation' => 'required|string|max:255',
            'formData.breakerBoxLocation' => 'required|string|max:255',
            'formData.hvacFilterLocation1' => 'required|string|max:255',
            'formData.hvacFilterSize1' => 'required|string|max:50',

            // Optional HVAC Filter Information (2-4)
            'formData.hvacFilterLocation2' => 'nullable|string|max:255',
            'formData.hvacFilterSize2' => 'nullable|string|max:50',
            'formData.hvacFilterLocation3' => 'nullable|string|max:255',
            'formData.hvacFilterSize3' => 'nullable|string|max:50',
            'formData.hvacFilterLocation4' => 'nullable|string|max:255',
            'formData.hvacFilterSize4' => 'nullable|string|max:50',

            // Home Warranty (Optional)
            'formData.homeWarranty' => 'nullable|string|in:Yes,No',
            'formData.homeWarrantyCompanyName' => 'nullable|string|max:255',
            'formData.homeWarrantyServiceNumber' => 'nullable|string|max:100',
            'formData.homeWarrantyContactNumber' => 'nullable|string|max:20',
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // W-9 Form Messages
            'formData.w9_entity_name.required' => 'Entity/Individual name is required.',
            'formData.w9_tax_class.required' => 'Please select a tax classification.',
            'formData.w9_address.required' => 'Address is required.',
            'formData.w9_address2.required' => 'City, state, and ZIP code are required.',
            'formData.w9_ssn.required_without' => 'Either Social Security Number or Employer Identification Number is required.',
            'formData.w9_ein.required_without' => 'Either Social Security Number or Employer Identification Number is required.',
            'formData.w9_ssn.size' => 'Social Security Number must be exactly 9 digits.',
            'formData.w9_ein.size' => 'Employer Identification Number must be exactly 9 digits.',

            // HVAC & Home Warranty Messages
            'formData.hvacMaintenancePlan.required' => 'HVAC Maintenance Plan selection is required.',
            'formData.installFloatSwitch.required' => 'Install Float Switch selection is required.',
            'formData.gasShutoffValveLocation.required' => 'Gas Shut Off Valve Location is required.',
            'formData.breakerBoxLocation.required' => 'Breaker Box Location is required.',
            'formData.hvacFilterLocation1.required' => 'HVAC Filter Location Information 1 is required.',
            'formData.hvacFilterSize1.required' => 'HVAC Filter Size 1 is required.',

            // Gate Access Messages
            'formData.gatedCommunity.required' => 'Please tell us whether the property is gated.',
            'formData.gatedCommunity.in' => 'Please tell us whether the property is gated.',
            'formData.gateCode.required_if' => 'Gate Code is required when the property is gated.',
            'formData.gateCode.max' => 'Gate Code must be 255 characters or fewer.',

            // Sprinkler / Irrigation System Messages
            'formData.sprinklerSystem.required' => 'Please tell us whether the property has a sprinkler or irrigation system.',
            'formData.sprinklerSystem.in' => 'Please tell us whether the property has a sprinkler or irrigation system.',
            'formData.sprinklerControllerLocation.required_if' => 'Sprinkler Controller Location is required when there is a sprinkler system.',
            'formData.sprinklerControllerLocation.max' => 'Sprinkler Controller Location must be 255 characters or fewer.',
            'formData.sprinklerNotes.max' => 'Sprinkler Notes must be 500 characters or fewer.',
        ];
    }
}
