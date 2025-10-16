<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Onboarding Process</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 0 20px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #22c55e;
        }

        .header h1 {
            color: #22c55e;
            font-size: 24px;
            margin: 0;
        }

        .header p {
            color: #666;
            margin: 5px 0;
        }

        .section {
            margin-bottom: 25px;
        }

        .section-title {
            background-color: #22c55e;
            color: white;
            padding: 8px 12px;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .property-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #22c55e;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            font-weight: bold;
            display: inline-block;
            width: 200px;
            vertical-align: top;
        }

        .form-group .value {
            display: inline-block;
            margin-left: 10px;
        }

        .grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .grid-row {
            display: table-row;
        }

        .grid-cell {
            display: table-cell;
            width: 50%;
            padding: 5px;
            vertical-align: top;
        }

        .signature-section {
            margin-top: 30px;
            padding: 20px;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
        }

        .signature-image {
            max-width: 400px;
            max-height: 150px;
            border: 1px solid #ccc;
            margin-top: 10px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 10px;
        }

        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            background-color: #fff3cd;
        }

        .alert-info {
            border-left-color: #22c55e;
            background-color: #dcfce7;
        }

        .alert-warning {
            border-left-color: #ffc107;
            background-color: #fff3cd;
        }

        .responsibilities-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .responsibilities-table th,
        .responsibilities-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .responsibilities-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .page-break {
            page-break-before: always;
        }

        .logo-container {
            text-align: center;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .logo {
            max-width: 400px;
            height: auto;
            display: block;
            margin: 0 auto;
        }
    </style>
</head>

<body>
    <div class="logo-container">
        <img src="{{ public_path('tx-logo.webp') }}" alt="TexasRenters.com Logo" class="logo" />
    </div>
    <div class="header">
        <h1 style="color: #007bff; text-transform:uppercase">Management Onboarding Information Form</h1>
        <p style="font-size: 14px; margin: 15px auto; max-width: 600px;">We'll guide you through a simple process to set
            up your property for management. Your responses help us provide the best service for your investment.</p>
    </div>

    <!-- Property Information -->
    <div class="section">
        <div class="section-title">Property Information</div>

        <div class="alert alert-info">
            <strong>Important:</strong> Please ensure the information matches exactly with your Propertyware records.
        </div>

        <div class="property-info">
            @if (isset($ownerName))
                <div class="form-group">
                    <label>Property Owner:</label>
                    <span class="value">{{ $ownerName }}</span>
                </div>
            @endif

            @if (isset($buildingData['name']))
                <div class="form-group">
                    <label>Property Name:</label>
                    <span class="value">{{ $buildingData['name'] }}</span>
                </div>
            @endif

            @if (isset($buildingData['address']))
                <div class="form-group">
                    <label>Building Address:</label>
                    <span class="value">
                        {{ $buildingData['address']['address'] ?? '' }}
                        {{ $buildingData['address']['addressCont'] ?? '' }}<br>
                        {{ $buildingData['address']['city'] ?? '' }},
                        {{ $buildingData['address']['stateRegion'] ?? '' }}
                        {{ $buildingData['address']['postalCode'] ?? '' }}
                    </span>
                </div>
            @endif
        </div>
    </div>

    <!-- Property Preparation -->
    <div class="section">
        <div class="section-title">Getting your Home Rent Ready</div>
        <p style="margin-bottom: 15px;"><strong>Getting the Property Ready to Market:</strong> We want to make sure that
            your home is presentable during the marketing phase. There are a few key areas that need to be taken care of
            prior to placing your home on the market. A neat, clean home will help you to attract the best possible
            tenant.</p>

        <div class="alert alert-warning">
            <strong>Curb Appeal:</strong> The first thing that someone will see when they pull up to the home is the
            lawn. Your home will lease faster if the yard is cut and the bushes & trees are trimmed. Also, if the home
            has any mildew or mold it should be cleaned or pressure washed.
        </div>

        <div class="alert alert-warning">
            <strong>Flooring:</strong> The flooring should be free of obvious defects, large stains, and pet odors.
        </div>

        <div class="alert alert-warning">
            <strong>Walls/Paint:</strong> Neutral colors will appeal to the most people. Brighter colors may be
            acceptable in some areas of the home. While it is not a requirement that you repaint the property, you may
            want to consider it if you have numerous scuff marks, holes, or rooms that have colors that might not appeal
            to a large group of people. If you have the paint code for your wall, please include them in the space
            provided below.
        </div>

        <div class="alert alert-warning">
            <strong>Valuables:</strong> Crime can happen at any time. Please help to minimize the risk by putting away
            or removing valuables and fire arms from the property.
        </div>

        <div class="alert alert-warning">
            <strong>Insurance:</strong> Make sure to contact your insurance company to learn about your insurance
            policy. If you are moving out of the property, and it will be vacant for any period, let your insurance
            company know. Some policies do not cover vandalism when home is vacant.
        </div>

        <div class="alert alert-warning">
            <strong>Utilities:</strong> We require that utilities are turned on. If vacant, we ask that you shut off
            water at the main cut off valve to prevent water leaks that might otherwise go unnoticed before causing
            significant damage. It's Texas, and it's hot. Prospective tenant's viewing your home will turn down your AC
            to see if it blows cold air, and will then walk out without turning the thermostat back up or off. Make sure
            that you have programmable thermostat that automatically go back to a reasonable temperature, so that you
            don't receive a surprise electric bill for a vacant home.
        </div>

        <p style="margin: 15px 0;"><strong>For the above items, we are happy to arrange the services below. Please let
                us know by indicating in the appropriate box with your initials, if you would like to take care of the
                above items before the property goes on the market, or if you would like us to handle them for
                you.</strong></p>

        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Paint Required</td>
                    <td>{{ $formData['paint'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Paint Color</td>
                    <td>{{ $formData['paintColor'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Service Responsibilities -->
    <div class="section">
        <div class="section-title">Service Responsibilities</div>
        <p style="margin-bottom: 15px;"><strong>Utility providers and maintenance preferences</strong></p>

        <div class="alert alert-info">
            <strong>Initial Lease Term:</strong> The following items are only for the initial lease term. After the
            initial tenant moves out, if TexasRenters.com is still managing the property, will handle utilities, lawn
            care, carpet clearning and cleaning unless otherwise instructed at that tme, per the property management
            agreement.
        </div>

        <div class="alert alert-info">
            <strong>During Marketing Period:</strong> During the time the property is on the market: It is important
            that the lawn and home stay in good shape while the home is on the market. Please indicate below if you
            would like to be responsible for the below items, or if you would like us to handle it for you.
        </div>

        <div class="alert alert-info">
            <strong>Final Touches before Tenant Move in:</strong> We require that all homes be profesionally cleaned,
            and carpets thermostat are not new be professionally cleaned, a final lawn cut be done and pest control
            prior to move in. We expect that when a tenant moves out of the home, they will also have the home
            professionally cleaned and carpets steam cleaned. By having the home professionally cleaned, and being able
            to provide receipts, we can help assure that as the home owner, you will not be paying to clean up after
            tenants in the future. In most cases, this will be the only time that you need to pay for cleaning. If the
            carpets were not professionally cleaned prior to putting the home on the market, this is the time to do it.
            We do however, allow you to choose between us providing the service, and you arranging the service on your
            own. If you arrange the service, please provide receipts for our records. TexasRenters.com, LLC will arrange
            for and pay for, at the owner's expense, a final cleaning just prior to tenant move in. You may have the
            lawn and pest control done at your discretion. For standard treatment of roaches and bugs (excluding rodents
            and bee hives) the charge is $60.00 for pest control.
        </div>

        <h4>Prior to property photos the following items need to be completed.</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Responsibility</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Lawn Care</td>
                    <td>{{ $formData['goingOnTheMarketLawnCare'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Professional Cleaning</td>
                    <td>{{ $formData['goingOnTheMarketCleaning'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Debris Removal</td>
                    <td>{{ $formData['goingOnTheMarketDebrisRemoval'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Paint</td>
                    <td>{{ $formData['goingOnTheMarketPaint'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Carpet Cleaning</td>
                    <td>{{ $formData['goingOnTheMarketCarpetCleaning'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Carpet Replacement</td>
                    <td>{{ $formData['goingOnTheMarketCarpetReplacement'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Utilities</td>
                    <td>{{ $formData['goingOnTheMarketUtilities'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>While on Market:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Responsibility</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Lawn Care</td>
                    <td>{{ $formData['homeOnTheMarketLawnCare'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Cleaning</td>
                    <td>{{ $formData['homeOnTheMarketCleaning'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Utilities</td>
                    <td>{{ $formData['homeOnTheMarketUtilities'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Before Tenant Move-In:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Responsibility</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Final Lawn Care</td>
                    <td>{{ $formData['beforeTenantMoveInLawnCare'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Pest Control</td>
                    <td>{{ $formData['beforeTenantMoveInPestControl'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>After Tenant Move-In:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Ongoing Lawn Care</td>
                    <td>{{ $formData['afterTenantMoveInLawnCare'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Re-key and Code Work -->
    <div class="section">
        <div class="section-title">Re-Key and Code Work</div>

        <div class="alert alert-warning">
            <strong>Re-Key & Code Work:</strong> State law requires a working smoke detector in each bedroom and each
            hallway servicing a bedroom along with a peep hole and keyless locking device on each exterior door. Homes
            must be re-keyed between each tenant.
        </div>

        <div class="alert alert-warning">
            <strong>Liability Warning:</strong> This is a very large liability issue, therefore, if the items have not
            been completed prior to our move in inspection, the management company will automatically complete the
            items.
        </div>

        <h4>Alarm System:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Alarm System Present</td>
                    <td>{{ $formData['alarmSystem'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['alarmSystemCode']) && $formData['alarmSystemCode'])
                    <tr>
                        <td>Alarm System Code</td>
                        <td>{{ $formData['alarmSystemCode'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Included in Price</td>
                    <td>{{ $formData['alarmSystemIncludedInPrice'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Under Contract</td>
                    <td>{{ $formData['alarmSystemUnderContract'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Armed During Marketing</td>
                    <td>{{ $formData['alarmSystemBeArmDuringMarketing'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        @if (isset($buildingData['customFields']))
            @php
                $keyInfo = collect($buildingData['customFields'])->firstWhere(
                    'fieldName',
                    'Key Information - anything we need to know',
                );
            @endphp
            @if ($keyInfo && $keyInfo['value'] !== 'Not Completed')
                <h4>Key Information:</h4>
                <div class="alert alert-info">
                    <strong>Additional Property Information:</strong> {{ $keyInfo['value'] }}
                </div>
            @endif
        @endif

        <h4>Re-Key & Code Work:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Re-Key & Code Work Responsibility:</td>
                    <td>{{ $formData['reKey'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>

        </table>
    </div>

    <!-- Tenant Service Request -->
    <div class="section">
        <div class="section-title">Tenant Service Request Policy</div>
        <p style="margin-bottom: 15px;"><strong>Tenant Service Request Handling</strong></p>

        <div class="alert alert-info">
            <strong>Initial Repairs after move in:</strong> If there are non-cosmetics items that are found that need to
            be repaired upon move in, such as a garbage disposal that does not work, or leaking toilet, we will normally
            repair these items, and waive the $75.00 repair deductible for the tenant, as the item was not working when
            the tenant moved in. We will only do this for 1 beginning, and helps to reduce your turnover expense.
        </div>

        <div class="alert alert-info">
            <strong>Repairs during the Lease terms:</strong> Our leases require a $75.00 tenant co-payment for all
            repairs that are not related to the HVAC system, water heater or roof during the term of the lease. We
            jokingly call it the door stop and toilet flapper clause, because it keeps you from having a repair bill for
            nuisance items that a tenant can easily take case of themselves. However, we do like to use discretion on
            some items that it is obvious that the tenant did not cause, and waive the $75.00 co-payment. We find that
            this little bit of good helps to keep your tenant happy, and they are much more likely to renew when they
            are treated fairly.
        </div>

        <h4>Service Request Policy:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Policy</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Tenant Service Request Handling</td>
                    <td>{{ $formData['tenantServiceRequest'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pet Policies -->
    <div class="section">
        <div class="section-title">Pet Policies & Rules</div>
        <p style="margin-bottom: 15px;"><strong>Set your preferences for tenant policies</strong></p>

        <div class="alert alert-info">
            <strong>Pets:</strong> Many renters are choosing to live in a home versus an apartment because they have
            pets. This reality means that if you do not accept pets, it will take about twice as long to rent your home
            if you do not allow pets. However, this is of course your choice. Please let us know if you would accept
            pets, and what type below.
        </div>

        <h4>Pet Policy:</h4>

        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Pet Policy</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Dogs Allowed</td>
                    <td>{{ $formData['dogsAllowed'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Maximum Dog Weight</td>
                    <td>{{ $formData['dogsMaxWeight'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Cats Allowed</td>
                    <td>{{ $formData['catsAllowed'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Cat Restrictions</td>
                    <td>{{ $formData['catRestrictions'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['otherPetsRestriction']) && $formData['otherPetsRestriction'])
                    <tr>
                        <td>Other Pet Restrictions</td>
                        <td>{{ $formData['otherPetsRestriction'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

    </div>

    <!-- Amenities & Features -->
    <div class="section">
        <div class="section-title">Amenities & Features</div>
        <p style="margin-bottom: 15px;"><strong>Property features and neighborhood amenities</strong></p>

        <div class="alert alert-info">
            <strong>Property Information:</strong> We need to know more about your home so that we can properly market
            the home and give the new tenant information on how to take care of the home.
        </div>

        <h4>Swimming Pool:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Pool Information</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Swimming Pool Present</td>
                    <td>{{ $formData['swimmingPool'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Pool Service Included</td>
                    <td>{{ $formData['poolService'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['poolServiceName']) && $formData['poolServiceName'])
                    <tr>
                        <td>Pool Service Company</td>
                        <td>{{ $formData['poolServiceName'] }}</td>
                    </tr>
                    <tr>
                        <td>Pool Service Phone</td>
                        <td>{{ $formData['poolServiceNumber'] ?? 'Not specified' }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <h4>Neighborhood Amenities:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Amenity</th>
                    <th>Available</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Community Pool</td>
                    <td>{{ $formData['communityPool'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Nearby Park</td>
                    <td>{{ $formData['park'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Playground</td>
                    <td>{{ $formData['playGround'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Tennis Court</td>
                    <td>{{ $formData['tennisCourt'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Who the tenant should contact to access
                        Neighborhood Amenities?</td>
                    <td>{{ $formData['tenantToContactNeighborhoodAmenities'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Appliances:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Appliance/Equipment</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Refrigerator Included</td>
                    <td>{{ $formData['refrigerator'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Microwave Included</td>
                    <td>{{ $formData['microwave'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Washing Machine</td>
                    <td>{{ $formData['washingMachine'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['washingMachineHookups']) && $formData['washingMachineHookups'])
                    <tr>
                        <td>Washing Machine Hookups</td>
                        <td>{{ $formData['washingMachineHookups'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Dryer Included</td>
                    <td>{{ $formData['dryer'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['dryerHookups']) && $formData['dryerHookups'])
                    <tr>
                        <td>Dryer Hookups</td>
                        <td>{{ $formData['dryerHookups'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Water Softener</td>
                    <td>{{ $formData['waterSoftener'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['waterHeaterModelYear']) && $formData['waterHeaterModelYear'])
                    <tr>
                        <td>Water Heater Model Year</td>
                        <td>{{ $formData['waterHeaterModelYear'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['dishWasherModelYear']) && $formData['dishWasherModelYear'])
                    <tr>
                        <td>Dishwasher Model Year</td>
                        <td>{{ $formData['dishWasherModelYear'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td>HVAC Model Year</td>
                    <td>{{ $formData['hvacModelYear'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Garage Access & Mailbox:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Access Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Garage Door Opener</td>
                    <td>{{ $formData['garageDoorOpener'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Number of Remotes</td>
                    <td>{{ $formData['garageDoorRemote'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['lockboxCode']) && $formData['lockboxCode'])
                    <tr>
                        <td>Lockbox Code</td>
                        <td>{{ $formData['lockboxCode'] }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Mailbox Keys</td>
                    <td>{{ $formData['mailboxKeyNo'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Mailbox Location</td>
                    <td>{{ $formData['mailboxLocation'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Location Information:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Location/Details</th>
                </tr>
            </thead>
            <tbody>
                @if (isset($formData['gasShutoffValveLocation']) && $formData['gasShutoffValveLocation'])
                    <tr>
                        <td>Gas Shut-off Valve Location</td>
                        <td>{{ $formData['gasShutoffValveLocation'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['breakerBoxLocation']) && $formData['breakerBoxLocation'])
                    <tr>
                        <td>Breaker Box Location</td>
                        <td>{{ $formData['breakerBoxLocation'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterLocation1']) && $formData['hvacFilterLocation1'])
                    <tr>
                        <td>HVAC Filter Location 1</td>
                        <td>{{ $formData['hvacFilterLocation1'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterSize1']) && $formData['hvacFilterSize1'])
                    <tr>
                        <td>HVAC Filter Size 1</td>
                        <td>{{ $formData['hvacFilterSize1'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterSize2']) && $formData['hvacFilterSize2'])
                    <tr>
                        <td>HVAC Filter Size 2</td>
                        <td>{{ $formData['hvacFilterSize2'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterLocation2']) && $formData['hvacFilterLocation2'])
                    <tr>
                        <td>HVAC Filter Location 2</td>
                        <td>{{ $formData['hvacFilterLocation2'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterSize3']) && $formData['hvacFilterSize3'])
                    <tr>
                        <td>HVAC Filter Size 3</td>
                        <td>{{ $formData['hvacFilterSize3'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterLocation3']) && $formData['hvacFilterLocation3'])
                    <tr>
                        <td>HVAC Filter Location 3</td>
                        <td>{{ $formData['hvacFilterLocation3'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterSize4']) && $formData['hvacFilterSize4'])
                    <tr>
                        <td>HVAC Filter Size 4</td>
                        <td>{{ $formData['hvacFilterSize4'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['hvacFilterLocation4']) && $formData['hvacFilterLocation4'])
                    <tr>
                        <td>HVAC Filter Location 4</td>
                        <td>{{ $formData['hvacFilterLocation4'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <!-- HVAC & Home Warranty -->
    <div class="section">
        <div class="section-title">HVAC & Home Warranty</div>
        <p style="margin-bottom: 15px;"><strong>Set your participation in HVAC maintenance plan and home
                warranty</strong></p>

        <div class="alert alert-warning">
            <strong>HVAC Maintenance:</strong> The most common cause for damage to homes that we see is from the
            emergency drain line overflowing from the air-conditioning drain pan. This is caused from mold or other
            debris clogging up the primary and secondary drain lines. This can be prevented by doing 2 things. First,
            the HVAC system can be serviced every 6 months, which includes blowing out the drain lines, cleaning
            exterior coils, checking for carbon monoxide leaks, and changing your air filter (all of this also extends
            the life of and increases the efficiency of your HVAC system). Second, an emergency float switch will be
            installed in the pan, so that if water builds up in the pan, the HVAC systems will shut off and stop
            producing water. 2 HVAC inspection are $11.00 per month for the first HVAC and a additional $6.00 per month
            for each additional unit. The float switch will automatically be installed if not present at a cost of
            $89.00. Please note your HVAC warranty does not cover this type of maintenance.
        </div>

        <div class="alert alert-warning">
            <strong>Home Warranty:</strong> We generally do not recommend them, because the responsiveness, and quality
            of the service are not often up to par. They usually have 2 business days to respond to an air-conditioning
            service call (respond not complete), which means that if your tenant calls on Friday, they may not get a
            response until Tuesday. Also, if you have a plan or decide to get a plan, please read the exclusions and
            limitations very closely. Our experience is that many of the plans cap the most expensive such as the air
            air-conditioning at a set amount, and stick you with their vendor to install the new system, who usually
            charges 2x what we can get it done for. Also, a repair may be covered, but damage caused by the repair or
            malfunction, along with bringing older items up to code may not be covered. That being said, some would
            prefer the comfort of knowing that many cost can be covered. If you do not have a home warranty, get our
            advice on who to pick first. If you already have one, please include the information below, so that we can
            use the home warranty if your home needs service.
        </div>

        <h4>HVAC & Home Warranty:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>HVAC Service</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>HVAC Maintenance Plan</td>
                    <td>{{ $formData['hvacMaintenancePlan'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Install Float Switch</td>
                    <td>{{ $formData['installFloatSwitch'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>

        <h4>Home Warranty:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Warranty Information</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Home Warranty</td>
                    <td>{{ $formData['homeWarranty'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Warranty Company</td>
                    <td>{{ $formData['homeWarrantyCompanyName'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['homeWarrantyServiceNumber']) && $formData['homeWarrantyServiceNumber'])
                    <tr>
                        <td>Service Number</td>
                        <td>{{ $formData['homeWarrantyServiceNumber'] }}</td>
                    </tr>
                @endif
                @if (isset($formData['homeWarrantyContactNumber']) && $formData['homeWarrantyContactNumber'])
                    <tr>
                        <td>Contact Number</td>
                        <td>{{ $formData['homeWarrantyContactNumber'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <!-- Preferred Vendors -->
    <div class="section">
        <div class="section-title">Preferred Vendors</div>
        <p style="margin-bottom: 15px;"><strong>Your trusted service providers</strong></p>

        <div class="alert alert-info">
            <strong>Preferred Vendors:</strong> If you have a preferred vendor, we will use them for non-emergency
            repairs, and make a reasonable effor to tuse them for emergency repairs. If your preferred vendor cannot be
            reached in a situation where delay would cause damage to your property or harm to the tenant, we will select
            a vendor who can get the job completed as soon as possible.
        </div>

        <h4>Preferred Vendors:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Service Type</th>
                    <th>Company Name</th>
                    <th>Phone Number</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>HVAC</td>
                    <td>{{ $formData['hvacVendorName'] ?? 'Not specified' }}</td>
                    <td>{{ $formData['hvacVendorNumber'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Electrical</td>
                    <td>{{ $formData['electricVendorName'] ?? 'Not specified' }}</td>
                    <td>{{ $formData['electricVendorNumber'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Plumbing</td>
                    <td>{{ $formData['plumbingVendorName'] ?? 'Not specified' }}</td>
                    <td>{{ $formData['plumbingVendorNumber'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Pest Control</td>
                    <td>{{ $formData['pestControlVendorName'] ?? 'Not specified' }}</td>
                    <td>{{ $formData['pestControlVendorNumber'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Lawn Care</td>
                    <td>{{ $formData['lawnCareVendorName'] ?? 'Not specified' }}</td>
                    <td>{{ $formData['lawnCareVendorNumber'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['otherVendorName']) && $formData['otherVendorName'])
                    <tr>
                        <td>Other</td>
                        <td>{{ $formData['otherVendorName'] }}</td>
                        <td>{{ $formData['otherVendorNumber'] ?? 'Not specified' }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <h4>Utilities & Services:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>Service Type</th>
                    <th>Provider/Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Water Provider</td>
                    <td>{{ $formData['waterProvider'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Gas Provider</td>
                    <td>{{ $formData['gasProvider'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Trash Provider</td>
                    <td>{{ $formData['trashProvider'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Trash Pickup Days</td>
                    <td>{{ $formData['trashPickupDays'] ?? 'Not specified' }}</td>
                </tr>
                @if (isset($formData['recyclePickupDays']) && $formData['recyclePickupDays'])
                    <tr>
                        <td>Recycle Pickup Days</td>
                        <td>{{ $formData['recyclePickupDays'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

    </div>

    <!-- Property History -->
    <div class="section">
        <div class="section-title">Property History</div>
        <p style="margin-bottom: 15px;"><strong>Historical information about your property</strong></p>

        <h4>Property History:</h4>
        <table class="responsibilities-table">
            <thead>
                <tr>
                    <th>History Item</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Ever Flooded</td>
                    <td>{{ $formData['floodedProperty'] ?? 'Not specified' }}</td>
                </tr>
                <tr>
                    <td>Last Flood Date</td>
                    <td>{{ $formData['floodedPropertyDate'] ?? 'Not specified' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Additional Information -->
    @if (isset($formData['otherComments']) && $formData['otherComments'])
        <div class="section page-break">
            <div class="section-title">Additional Information</div>
            <div class="alert alert-info">
                <strong>Additional Information:</strong> If there is anything not Covered above, please let us know in
                the space below. This would also include any repairs or bids that you would like us to have completed
                for you.
            </div>
            <div class="form-group">
                <label>Additional Comments:</label>
                <div class="value">{{ $formData['otherComments'] }}</div>
            </div>
            <div class="form-group">
                <label>Owner Preferred Communication:</label>
                <div class="value">{{ $formData['preferredCommunication'] }}</div>
            </div>
            <div>
                <input type="checkbox" id="terms"
                    {{ isset($formData['e_1099_consent']) && $formData['e_1099_consent'] ? 'checked' : '' }}
                    style="margin: 0" />
                <label for="terms">
                    I hereby consent to receive my IRS Form
                    1099 electronically via the email
                    address provided to TexasRenters.com,
                    LLC. I understand that no paper copy
                    will be mailed unless I revoke this
                    consent in writing.
                </label>
            </div>
        </div>
    @endif

    <!-- Final Details & Signature -->
    <div class="section">
        <div class="section-title">Final Details & Signature</div>
        <div class="signature-section">
            <p><strong>Electronic Signature</strong></p>
            <p>Please sign below to authorize and verify the information provided.</p>
            @if ($signature)
                <div class="form-group">
                    <label>Electronic Signature:</label>
                    <br>
                    <img src="{{ $signature }}" alt="Property Owner Signature" class="signature-image">
                    <br>
                    @if (isset($ownerName))
                        <span class="value" style="font-weight: 900; font-size: 14px">{{ $ownerName }}</span>
                    @endif
                </div>
            @endif

            <div class="form-group">
                <label>Date Signed: {{ $generated_at }}</label>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>This document was generated electronically by TexasRenters.com Onboarding Portal.</p>
        <p>For questions about this onboarding process form, please contact us at 281-248-8018.</p>
        <p>Developed and maintained by Texas Renters IT Department.</p>
        <p>Generated on: {{ $generated_at }}</p>

    </div>
</body>

</html>
