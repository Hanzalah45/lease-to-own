<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #171717; }
    h1 { font-size: 18px; text-transform: uppercase; margin: 0 0 4px; }
    h2 { font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #171717; padding-bottom: 6px; margin: 18px 0 8px; page-break-after: avoid; }
    .sub { background: #f5f5f5; padding: 5px 8px; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #737373; margin-top: 10px; page-break-after: avoid; }
    table.rows { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.rows tr { page-break-inside: avoid; }
    table.rows td { padding: 5px 0; border-bottom: 1px solid #f0f0f0; font-size: 10.5px; vertical-align: top; }
    table.rows td.label { color: #737373; width: 60%; }
    table.rows td.value { font-weight: bold; text-align: right; }
    p.terms { font-size: 9px; font-style: italic; color: #a3a3a3; line-height: 1.5; margin-top: 10px; page-break-inside: avoid; }
    .meta { color: #737373; font-size: 10px; margin-bottom: 12px; }
    .signature-block { margin-top: 22px; border-top: 2px solid #171717; padding-top: 10px; page-break-inside: avoid; }
    .signature-block table { width: 100%; }
    .signature-block td { width: 33%; vertical-align: top; }
    .signature-block .head { font-size: 9px; text-transform: uppercase; color: #a3a3a3; font-weight: bold; }
    .signature-block .val { font-size: 11px; font-weight: bold; margin-top: 2px; }
    .initial-line { margin-top: 14px; text-align: right; font-size: 9px; color: #a3a3a3; border-top: 1px solid #f0f0f0; padding-top: 6px; page-break-inside: avoid; }
    .initial-line .stamp { font-weight: bold; color: #171717; }
    .keep-together { page-break-inside: avoid; }
    .page-start { page-break-before: always; }
    table.ldw { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 8.5px; }
    table.ldw th { background: #f5f5f5; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; color: #737373; border-bottom: 1px solid #d4d4d4; }
    table.ldw td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    table.ldw tr { page-break-inside: avoid; }
    table.ldw td.covered-yes { color: #166534; font-weight: bold; }
    table.ldw td.covered-no { color: #991b1b; font-weight: bold; }
    @page { margin: 25px 25px 45px 25px; }
</style>
</head>
<body>

@php
    $initials = collect(explode(' ', trim($contract->signer_name ?? $customerName)))
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $signedDate = $contract->signed_at?->format('M j, Y');
@endphp

@php
    $renderInitials = function () use ($initials, $signedDate) {
        echo '<div class="initial-line">Initials: <span class="stamp">'.$initials.'</span> &middot; '.$signedDate.'</div>';
    };
@endphp

@php
    $contactUsAnswer = 'By mail'
        .($companyAddress ? ' at '.$companyAddress : '')
        .($companyPhone ? ', or by phone at '.$companyPhone : '')
        .'. You can call us or use certified mail to confirm receipt.';
@endphp

<h1>Lease Purchase Agreement</h1>
<p class="meta">{{ $customerName }} &middot; Prostart Leasing &middot; Contract #{{ $contract->id }} (v{{ $contract->version }})</p>

<h2>Customer information &amp; authorization</h2>
<table class="rows">
    <tr><td class="label">Renter Name</td><td class="value">{{ $customerName }}</td></tr>
    <tr><td class="label">Mailing Address</td><td class="value">{{ $customerAddress ?: '—' }}</td></tr>
</table>

@if ($profile)
<div class="keep-together">
<div class="sub">Physical location of leased property &amp; source of income</div>
<table class="rows">
    <tr><td class="label">Physical Address</td><td class="value">{{ $customerAddress ?: '—' }}</td></tr>
    <tr><td class="label">How Long at Residence</td><td class="value">{{ $profile->years_at_residence ?: '—' }}</td></tr>
    <tr><td class="label">Own / Rent</td><td class="value">{{ $profile->residence_type ? str($profile->residence_type)->replace('_', ' ')->title() : '—' }}</td></tr>
    @if (str($profile->residence_type ?? '')->contains('rent'))
    <tr><td class="label">Monthly Rent Payment</td><td class="value">{{ $profile->monthly_rent ? '$'.number_format($profile->monthly_rent, 2) : '—' }}</td></tr>
    <tr><td class="label">Landlord Name</td><td class="value">{{ $profile->landlord_name ?: '—' }}</td></tr>
    <tr><td class="label">Landlord Phone</td><td class="value">{{ $profile->landlord_phone ?: '—' }}</td></tr>
    @else
    <tr><td class="label">Mortgage Payment</td><td class="value">{{ $profile->mortgage_amount ? '$'.number_format($profile->mortgage_amount, 2) : '—' }}</td></tr>
    @endif
    <tr><td class="label">Income Source</td><td class="value">{{ $profile->employment_status ? str($profile->employment_status)->replace('_', ' ')->title() : '—' }}</td></tr>
    <tr><td class="label">Monthly Income</td><td class="value">{{ $profile->monthly_income ? '$'.number_format($profile->monthly_income, 2) : '—' }}</td></tr>
    <tr><td class="label">Employer / Business Name</td><td class="value">{{ $profile->employer_name ?: '—' }}</td></tr>
    <tr><td class="label">Employer / Business Phone</td><td class="value">{{ $profile->employer_phone ?: '—' }}</td></tr>
</table>
</div>
@endif

<div class="keep-together">
<div class="sub">Marketing &amp; communications consent</div>
<p class="terms">
    <strong>Account Transaction Calls.</strong> By signing below, you authorize Prostart Leasing to contact you at the phone
    number(s) and email you provided, including your cell phone, using auto-dialers, prerecorded voice messages, and text
    messages, for account information, payment reminders, and collection efforts related to this Agreement. You may
    withdraw this consent at any time by notifying us in writing.
</p>
<p class="terms">
    <strong>Marketing Calls &amp; Texts.</strong> By signing below, you also authorize Prostart Leasing to contact you with
    marketing and telemarketing calls and text messages at the number(s) provided, using an automatic telephone dialing
    system or prerecorded messages. Consenting to marketing contact is not required to obtain a lease from us. Message and
    data rates may apply. You may opt out of marketing contact at any time by notifying us in writing, without affecting
    the rest of this Agreement.
</p>
<p class="terms">
    <strong>Email &amp; Verification.</strong> You authorize us to communicate with you via phone, mail, and email, and you
    authorize us to check and verify the information on this Agreement, including your income, employment history, and to
    obtain a background check. You certify that everything shown on this Agreement is true and correct.
</p>
{{ $renderInitials() }}
</div>

<h2 class="page-start">Lease information summary</h2>
<div class="sub">Description of leased property</div>
<table class="rows">
    <tr><td class="label">Cash Price / Retail</td><td class="value">${{ number_format($cashPrice, 2) }}</td></tr>
    <tr><td class="label">Make</td><td class="value">{{ $equipment->model ?? '—' }}</td></tr>
    <tr><td class="label">Serial # / VIN</td><td class="value">{{ $equipment->serial_number ?? '—' }}</td></tr>
    <tr><td class="label">Description or Damage to Property</td><td class="value">{{ $equipment?->condition_notes ?: 'None noted' }}</td></tr>
</table>

<div class="sub">Lease details</div>
<table class="rows">
    <tr><td class="label">Months to Ownership</td><td class="value">{{ $lease->term_months }}</td></tr>
    <tr><td class="label">Payment Due Day</td><td class="value">{{ $lease->payment_due_day ?? '—' }}</td></tr>
    <tr><td class="label">Rental Payment</td><td class="value">${{ number_format($monthlyRental, 2) }}</td></tr>
    <tr>
        <td class="label">{{ $lease->ldw_selected ? 'LDW (monthly)' : 'No-LDW Surcharge (monthly)' }}</td>
        <td class="value">${{ number_format($lease->ldwMonthlyAmount(), 2) }} / mo</td>
    </tr>
    <tr><td class="label">Sales Tax</td><td class="value">${{ number_format($salesTax, 2) }}</td></tr>
    <tr><td class="label">Total Monthly Payment</td><td class="value">${{ number_format($totalMonthly, 2) }}</td></tr>
    <tr><td class="label">Security Deposit</td><td class="value">${{ number_format($securityDeposit, 2) }}</td></tr>
    <tr><td class="label">Tracking Device Fee</td><td class="value">${{ number_format($trackingDeviceFee, 2) }}</td></tr>
    <tr><td class="label">TOTAL DUE TODAY</td><td class="value">${{ number_format($totalDueToday, 2) }}</td></tr>
    <tr><td class="label">AutoPay</td><td class="value">{{ $lease->autopay_enabled ? 'Yes' : 'No' }}</td></tr>
    <tr><td class="label">Total Rental-Purchase Price</td><td class="value">${{ number_format($totalRentalPurchasePrice, 2) }}</td></tr>
</table>

<p class="terms">
    <strong>Security Deposit &amp; Unit Hold.</strong> Your Security Deposit of ${{ number_format($securityDeposit, 2) }}
    is non-refundable and holds the Property exclusively for you for thirty (30) days from the date of this
    Agreement. If you do not pick up the Property and complete your first rental payment within that time, the
    Security Deposit will be forfeited and the reservation cancelled.
</p>
<p class="terms">
    <strong>2. Lease Term &amp; Payment Schedule.</strong> This Agreement is for one month. The rental term begins on
    the date you pick up the Property and expires one month later. You can renew the Agreement for additional
    one-month terms at your option by making a monthly rental renewal payment on or before the expiration date. The
    Agreement will also renew if you continue to possess the Property until you notify us that you want to end the
    rental and make the Property available for pickup.
</p>
<p class="terms">
    <strong>3. Rental-Purchase Ownership.</strong> If you renew this Agreement for {{ $lease->term_months }} months in
    a row, you will have paid the Total Rental-Purchase Price of ${{ number_format($totalRentalPurchasePrice, 2) }},
    not including taxes or fees, and you will obtain ownership of the Property after the final payment. Or, you can
    exercise an early purchase option ("EPO"). Any time within 90 days of the date the rental term begins (the date
    you pick up the Property), your EPO price will be the Cash Price less all Rental Payments paid to date (excludes
    taxes and fees). After that time,
    your EPO price will be the Cash Price less 50% of Rental Payments scheduled to date, plus any Rental Payments
    still owed and any additional funds. You will not own the Property unless you pay the Total Rental-Purchase Price
    or exercise an EPO. The Total Rental-Purchase Price does not include other charges such as late fees, disclosed
    below. Taxes are also due at the time of exercising an EPO.
</p>
<p class="terms">
    <strong>4. Maintenance, Repairs, and Loss of or Damage to the Property.</strong> During this Agreement, you are
    fully responsible for maintaining the Property in working order and usable condition. You are fully responsible
    for its condition and safety until it is returned to us. You are fully liable for all loss of, damage to or
    destruction of the Property from all causes, including, but not limited to, theft, vandalism, malicious mischief,
    or mysterious disappearance. If this Property is damaged, you must pay us promptly for the costs of repairs, not
    to exceed the EPO at the time the Property is returned to us. If the Property is lost or destroyed, you must pay
    us the amount of the EPO on the date of loss or the Cash Price, whichever is less.
</p>

<div class="sub page-start">4A. Loss damage waiver (LDW) coverage</div>
<p class="terms">
    Loss Damage Waiver (&ldquo;LDW&rdquo;) is an optional protection that reduces your financial
    responsibility for accidental physical damage to the Property. LDW is not insurance and is not a
    warranty. LDW does not replace or modify any manufacturer&rsquo;s warranty. If you elect LDW and remain
    current on all Rental Payments, we will waive our right to require you to pay for accidental damage to
    the Property, subject to the terms below.
</p>
<p class="terms">
    <strong>LDW Interaction With Section 4.</strong> If LDW applies, your liability for accidental damage is
    waived up to the EPO amount. If LDW does not apply, you remain fully responsible for all loss of, damage
    to, or destruction of the Property as stated in Section 4, including liability up to the EPO or Cash
    Price, whichever is less.
</p>
<p class="terms">
    Any repeated or above-average failures, breakdowns, or damage will be presumed to result from misuse,
    neglect, or abuse unless proven otherwise. LDW coverage does not apply in these circumstances.
</p>
<table class="ldw">
    <tr><th>Category</th><th>Description</th><th>Covered by LDW?</th><th>Notes / Customer Responsibility</th></tr>
    <tr><td>Accidental Mechanical Failure</td><td>Engine, drivetrain, pumps, clutches, PTO, transmission, internal mechanical components.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Electrical Failure</td><td>Starter, solenoid, wiring harness, switches, ignition components.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Hydraulic Failure</td><td>Hoses, pumps, valves, cylinders not damaged due to neglect.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Structural Damage</td><td>Deck, spindles, housings, linkages, brackets, non-frame components.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Impact Damage</td><td>Striking a stump, curb, root, rock, or other object.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Breakage</td><td>Breakage of parts or assemblies during normal residential use.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Cosmetic Damage</td><td>Scratches, dents, cracked plastic, non-functional cosmetic components.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Tire / Wheel / Blade / Deck Damage</td><td>Damage caused by accidental contact or residential hazards.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Transport Damage</td><td>Damage occurring while properly secured during towing or transport.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Accidental Third-Party Damage</td><td>Damage caused unintentionally by another person.</td><td class="covered-yes">Yes</td><td>LDW waives repair costs up to the EPO amount.</td></tr>
    <tr><td>Manufacturer Defects</td><td>Defects covered under OEM warranty.</td><td class="covered-no">No</td><td>Manufacturer warranty applies; LDW does not.</td></tr>
    <tr><td>Theft (Any Kind)</td><td>Theft of the Property, with or without a police report.</td><td class="covered-no">No</td><td>Theft of any kind is not covered under the LDW program. If the Property is stolen, you must pay the EPO amount. We recommend speaking with your insurance provider to ensure you have proper coverage for theft, fire, flood, and any other loss events related to the mower.</td></tr>
    <tr><td>Misuse / Abuse</td><td>Overloading, unsafe operation, improper use.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Commercial Use of Residential Equipment</td><td>Using residential equipment for business or income.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Improper Maintenance / Neglect</td><td>Failure to perform required service intervals.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Unauthorized Repairs</td><td>Repairs performed without approval.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Hour-Meter Tampering</td><td>Resetting, disabling, altering the hour meter.</td><td class="covered-no">No</td><td>LDW void; customer owes repair costs or EPO amount.</td></tr>
    <tr><td>GPS Tampering</td><td>Removing, disabling, or altering the GPS device.</td><td class="covered-no">No</td><td>LDW void; customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Environmental Damage</td><td>Fire, rollover, flooding, weather damage caused by negligence.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Damage While Past Due</td><td>Damage occurring while account is late or in default.</td><td class="covered-no">No</td><td>LDW void; customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Unauthorized User Operation</td><td>Damage caused by someone not permitted to operate the Property.</td><td class="covered-no">No</td><td>Customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Removal From Approved Address</td><td>Property moved without written permission.</td><td class="covered-no">No</td><td>LDW void; customer owes repair costs or EPO amount.</td></tr>
    <tr><td>Disappearance / Non-Return</td><td>Property not returned or made available for pickup.</td><td class="covered-no">No</td><td>Customer owes EPO or Cash Price, whichever is less.</td></tr>
</table>
{{ $renderInitials() }}

<p class="terms">
    <strong>5. Reinstatement.</strong> If you fail to make a timely renewal payment, you should contact us to arrange
    the return of the item and the Agreement will expire. You will incur Rental Payments until (1) the Property is
    returned to us or (2) you notify us that you want your rental to cease and make the item available to us for
    pickup. You can reinstate it without losing any rights or options previously acquired by making all payments due
    within 16 days of the renewal date. Or, if you return the Property to us within that time, then you will have 30
    days from the date of return to reinstate by making all payments due. If you reinstate, we will furnish you with
    the same Property or property of comparable quality and condition.
</p>
<p class="terms">
    <strong>6. Termination.</strong> You may terminate this Agreement at any time by returning the Property to us or
    by making arrangements with us for its return.
</p>
{{ $renderInitials() }}

<div class="sub page-start">7. Service, maintenance, hour-meter monitoring &amp; commercial-use restrictions</div>
<p class="terms">
    <strong>Service, Maintenance, and Proper Use Requirements.</strong> You agree to operate and maintain the
    Equipment in accordance with the manufacturer's recommended service schedule, including oil changes, filter
    replacements, belt adjustments, blade maintenance, lubrication, and general cleaning. Failure to properly service
    the Equipment as required is a violation of this Agreement. Any damage, excessive wear, mechanical failure, or
    unsafe operation caused by improper or neglected maintenance will be your financial responsibility.
</p>
<p class="terms">
    If the Equipment becomes unusable due to lack of maintenance, misuse, or failure to follow manufacturer
    guidelines, you must pay the lesser of the Early Purchase Option amount or the Cash Price. All repair costs
    resulting from improper maintenance must be paid before the Agreement can be renewed.
</p>
<p class="terms">
    <strong>Hour-Meter Monitoring &amp; Usage Verification.</strong> All Equipment leased under this Agreement is
    equipped with an hour meter or similar usage-tracking device. Prostart Leasing may review hour-meter readings at
    any time to verify proper maintenance intervals, confirm appropriate use, and ensure the Equipment is being
    operated within its intended duty rating.
</p>
<p class="terms">
    The hour meter is considered the official record of use. Excessive hours, abnormal usage patterns, or readings
    inconsistent with residential use may indicate commercial operation or misuse. Any damage, failure, or excessive
    wear resulting from over-use or abusive operation will carry the same penalties as improper maintenance.
</p>
<p class="terms">
    Tampering with, disabling, resetting, or altering the hour meter is strictly prohibited and will be treated as
    intentional misuse. Tampering triggers the same penalties listed above, including responsibility for repair
    costs, immediate EPO liability if the Equipment is damaged beyond repair, and possible termination of the
    Agreement.
</p>
<p class="terms">
    <strong>Commercial-Use Restrictions &amp; Residential-Equipment Limitations.</strong> Prostart Leasing provides
    Equipment rated for specific types of use. Commercial use is permitted only when the Equipment leased is
    classified as commercial-grade. Using residential-grade or homeowner-grade Equipment for commercial, industrial,
    or income-producing purposes is strictly prohibited and constitutes misuse and a violation of this Agreement.
</p>
<p class="terms">
    If residential Equipment is used for commercial purposes, or if commercial Equipment is used beyond its intended
    duty rating, any resulting damage, mechanical failure, excessive wear, or loss will carry the same penalties as
    improper maintenance, including: full responsibility for all repair costs; immediate payment of the Early
    Purchase Option amount if the Equipment is damaged beyond repair; liability for the Cash Price if the Equipment
    is lost, destroyed, or not returned; and possible termination of the Agreement and repossession of the Equipment.
</p>
<p class="terms">
    You understand that misuse caused by operating Equipment outside its intended commercial or residential
    classification voids any warranty coverage and places full financial responsibility on you.
</p>

<div class="keep-together">
<p class="terms">
    <strong>8. Other Charges.</strong> Any charge in addition to periodic payments must be reasonably related to the
    service performed.
</p>
<p class="terms">
    <strong>Late Fee.</strong> You authorize us to initiate payments from your verified payment method on or after
    each Payment Due Date in the amount described in this Agreement, including any accrued but unpaid rental
    charges. If your Rental Payment is not made within ten (10) days of the scheduled Payment Due Date, you
    authorize the assessment of a one-time late fee equal to ten percent (10%) of the Rental Payment, subject to a
    maximum charge of $30.00 and a minimum charge of $5.00, which will be added to the amount required to continue
    using the Property. This late fee will be charged only once per billing cycle and will not recur. You will
    receive notice at least ten (10) days before any payment is deducted if the total amount to be withdrawn
    exceeds your regularly scheduled payment by more than $30.00.
</p>
<p class="terms">
    <strong>Returned Check Charge.</strong> When allowed by law, if your check is returned to us for any reason, you
    must pay us a $30.00 returned check charge to cover our costs in processing your payment. If you bounce a check
    to us, you must make any future rental renewal or other payments to us from guaranteed funds, cashier's check, or
    money order, and not by personal check.
</p>
</div>
<p class="terms">
    <strong>Security Deposit Return.</strong> Your Security Deposit is non-refundable. If you believe a refund is
    warranted, you may contact us, and we will review your request on a case-by-case basis.
</p>
{{ $renderInitials() }}

<p class="terms">
    <strong>9. Your Use of and the Nature of the Property.</strong> During this Agreement, you must use the Property
    in a safe, careful and proper manner. You cannot allow the Property to be used in violation of any applicable
    federal, state or local statute or other regulation. You must use the Property in accordance with any applicable
    vendor's or manufacturer's manuals or instructions, including any safety instructions. You must reimburse us for
    any damage to the Property caused by your misuse of the Property. You also agree that you will not alter the
    Property without our prior written permission, and you cannot permit the Property to be affixed in such a manner
    that it cannot be removed without damage. You represent that you are knowledgeable regarding the proper and safe
    use of the Property, including how to load and unload the Property, and how to transport the Property safely.
</p>
<p class="terms">
    <strong>10. Default, Death, or Return.</strong> We may terminate this Agreement if you fail to fulfill your
    obligation to us under this Agreement. We may notify you of termination by any means. Upon your death or
    incarceration, at our option, we may elect to terminate this Agreement and arrange for return of the Property.
    Unless we notify you, your estate, or your representative otherwise, receipt of each timely payment will renew
    the Agreement for additional one-month terms. Unless we send notice of our termination, the transaction will also
    renew until the Property is returned to us or the Property is made available to us for pickup. You agree to pay
    us the lesser of the EPO or the fair market value of the Property if you fail to return it to us as provided for
    in this Agreement. In addition to other charges that have otherwise accrued, you agree to pay us for all
    reasonable costs we incur in getting the Property back.
</p>
<p class="terms">
    <strong>11. Other Persons, and Property.</strong> You are responsible for use of the Property in a safe manner.
    You fully assume the risk and agree to hold us harmless of loss, damage, injury, or death of any person and any
    property arising out of any use of the Property.
</p>
<p class="terms">
    <strong>12. Property Insurance.</strong> You agree to maintain physical damage insurance covering loss or damage
    to the Property written on an "all-risk" form. You agree to provide us proof of such insurance either through
    the policy itself or a certificate of insurance. The policy shall name us as a loss payee and shall include a
    waiver of subrogation in our favor.
</p>
<p class="terms">
    If you elected Loss Damage Waiver ("LDW") coverage under this Agreement, you understand and agree that LDW is
    not insurance and does not replace or satisfy your obligation to maintain physical damage insurance. LDW only
    waives certain accidental damage charges as described in Section 4A. You still must obtain and maintain the
    required insurance through a provider of your choice, and you acknowledge that you have the right to obtain
    such insurance from any person or company that is reasonable to us.
</p>
<p class="terms">
    <strong>13. Equity.</strong> You understand that we own the Property until you buy it or obtain ownership as
    stated in this Agreement. During the rental term, you do not have any ownership interest in this Property, and
    you do not have the right to a refund of any Rental Payments when this Agreement is terminated.
</p>
{{ $renderInitials() }}

<p class="terms page-start">
    <strong>14. Location of Property and Inspection.</strong> You agree to keep this Property at the address shown
    above. If you remove this Property without our written permission, we have the right to terminate this
    Agreement. You agree that we have the right to inspect the Property with reasonable notice. In inspecting the
    Property, it is our policy that neither we nor our representative will commit a breach of the peace.
</p>
<p class="terms">
    <strong>15. Access Easement.</strong> For as long as you are in possession of the Property and until you obtain
    ownership, you grant us an access easement at the address where the Property is located, including any
    driveway, yard, gate, enclosure, garage, shed, or other area where the Property is stored or kept. This
    easement allows us to deliver the Property, inspect it, verify its location, and retrieve it when this
    Agreement terminates or if you are in default.
</p>
<p class="terms">
    You agree to ensure that the Property remains accessible for recovery at all times. You are fully responsible
    for preventing obstructions, including locked gates, blocked driveways, enclosed structures, animals, vehicles,
    or any other impediments that restrict access to the Property. You agree to remove any such obstructions
    immediately upon request.
</p>
<p class="terms">
    If you fail to make the Property reasonably accessible, you authorize us to enter the area where the Property
    is located for the limited purpose of recovering it, provided we do so without breaching the peace. You fully
    assume the risk of harm or damage that may arise if we must move or remove any obstruction to recover the
    Property. Failure to provide access may be treated as concealment or refusal to surrender the Property.
</p>
<p class="terms">
    <strong>16. Assignment.</strong> We may sell, transfer, or assign this Agreement. However, you have no right to
    sell, transfer, assign, pawn, or sub-lease the Property.
</p>
<p class="terms">
    <strong>17. Warranty.</strong> To the extent allowed by law, we disclaim any Warranty of Merchantability or
    Fitness for a Particular Purpose, either express or implied, on the Property. You are renting the Property
    "as-is" and "with all faults," and you understand that we do not provide any warranty on the Property during
    the rental term. Any manufacturer's warranty that may exist applies only to defects covered by the
    manufacturer and does not apply to rental use, misuse, improper maintenance, commercial use of residential
    equipment, or any damage described in Sections 4, 4A, or 7 of this Agreement.
</p>
<p class="terms">
    If you elected Loss Damage Waiver ("LDW"), you understand that LDW is not a warranty and does not provide
    defect coverage. LDW only waives certain accidental damage charges as described in Section 4A and does not
    replace or modify any manufacturer's warranty.
</p>
<p class="terms">
    If you obtain ownership of the Property, we will transfer to you any unexpired manufacturer's warranty on the
    Property, if permitted by the terms of the warranty. No manufacturer's warranty is transferred or available to
    you until ownership is obtained.
</p>
<p class="terms">
    <strong>18. Miscellaneous Provisions.</strong> You understand that no changes may be made to this Agreement
    except in writing. Time is of the essence in this Agreement. This Agreement is not effective until you sign it
    and we receive the required Security Deposit, which holds the Property exclusively for you for thirty (30)
    days. The Security Deposit does not activate the rental term. The rental term begins only when you pick up the
    Property, at which time your first monthly Rental Payment will be collected. You acknowledge that you received
    a completed copy of this Agreement for review before signing, with no blank terms to be filled in.
</p>
<p class="terms">
    <strong>19. Additional Funds Received.</strong> If you pay us any amounts in addition to your Rental Payment
    ("Additional Funds"), the Additional Funds will automatically be applied to any amounts previously accrued (if
    applicable). If no other amounts are accrued, then the Additional Funds will be applied to your EPO unless we are
    otherwise instructed by you. If you do not elect to purchase the Property, and it is returned to us, we will
    refund any remaining Additional Funds within 30 days, upon your request, or otherwise within a reasonable time.
</p>
<p class="terms">
    <strong>20. Written Receipts.</strong> We will give you a written receipt for any payments made by cash or money
    order. In the event you do not receive a receipt from us, please contact us immediately and we will promptly
    provide one.
</p>
{{ $renderInitials() }}

<h2 class="page-start">21. Jury trial waiver &amp; arbitration clause</h2>
<p class="terms">By signing below (including if electronically), you agree to this Jury Trial Waiver &amp; Arbitration Clause ("Clause").</p>
<table class="rows">
    <tr><td class="label" style="width:30%">What is arbitration?</td><td class="value" style="text-align:left; font-weight:normal;">An alternative to court. In arbitration, a third-party arbitrator ("Arbiter") solves Disputes in a hearing. You, related third parties, and we, waive the right to go to court, other than small-claims court. Such "parties" forgo jury trials.</td></tr>
    <tr><td class="label">Is it different from court and jury trials?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. The hearing is private and less formal than court. Arbiters may limit pre-hearing fact finding, called "discovery." The decision is final. Courts rarely overturn Arbiters.</td></tr>
    <tr><td class="label">Who does the Clause cover?</td><td class="value" style="text-align:left; font-weight:normal;">You, Us, and Others. This Clause governs the parties, their heirs, successors, assigns, and third parties related to any Dispute.</td></tr>
    <tr><td class="label">Which Disputes are covered?</td><td class="value" style="text-align:left; font-weight:normal;">All Disputes. This includes all claims even indirectly related to your application and agreements with us, including nonpayment, reclaiming rented items, privacy, customer information, and claims about this Clause's arbitrability, validity, and scope.</td></tr>
    <tr><td class="label">Are you waiving rights?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. You waive your rights to: (1) have juries solve Disputes; (2) have courts, other than small-claims courts, solve Disputes; (3) serve as a private attorney general or in a representative capacity; (4) be in a class action.</td></tr>
    <tr><td class="label">Are you waiving class action rights?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. You agree that courts and arbiters won't allow class actions in resolving disputes between you and us. Only individual arbitration, or small-claims courts, will solve Disputes. Unless reversed on appeal, if a court invalidates the class-action waiver, then the parties agree not to arbitrate disputes, and only a judge, not a jury, will resolve the dispute.</td></tr>
    <tr><td class="label">What law applies?</td><td class="value" style="text-align:left; font-weight:normal;">The Federal Arbitration Act ("FAA"). This transaction involves interstate commerce. If a court finds the FAA doesn't apply, and the finding can't be appealed, then your state's law governs.</td></tr>
    <tr><td class="label">Can the parties try to solve Disputes first?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. We can try to solve Disputes if you call us. If this doesn't solve the Dispute, mail us written notice within 100 days of the Dispute date. If we make a written offer ("Settlement Offer"), you can reject it and arbitrate.</td></tr>
</table>
{{ $renderInitials() }}

<table class="rows" style="margin-top:12px;">
    <tr><td class="label" style="width:30%">How should you contact us?</td><td class="value" style="text-align:left; font-weight:normal;">{{ $contactUsAnswer }}</td></tr>
    <tr><td class="label">Can small-claims court solve some Disputes?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. Each party has the right to arbitrate, or to go to small-claims court if it has the power to hear the Dispute.</td></tr>
    <tr><td class="label">Do other options exist?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. Both parties may use lawful self-help remedies, including set-off or repossession or reclaiming of rented items.</td></tr>
    <tr><td class="label">Will this Clause continue to govern?</td><td class="value" style="text-align:left; font-weight:normal;">Yes, unless otherwise agreed. The Clause governs if you rescind, cancel, terminate, default, renew, prepay, or pay, or if your contract is discharged through bankruptcy.</td></tr>
    <tr><td class="label">How does arbitration start?</td><td class="value" style="text-align:left; font-weight:normal;">Mailing a notice. Either party may mail the other a request to arbitrate, even if a lawsuit has been filed. The receiving party must mail a response within 20 days.</td></tr>
    <tr><td class="label">Who arbitrates?</td><td class="value" style="text-align:left; font-weight:normal;">AAA, JAMS, or an agreed Arbiter. You may select the American Arbitration Association ("AAA") or JAMS. The Arbiter must arbitrate under AAA or JAMS consumer rules.</td></tr>
    <tr><td class="label">Will the hearing be held nearby?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. The Arbiter will order the hearing within 30 miles of your home or where the transaction occurred.</td></tr>
    <tr><td class="label">What about appeals?</td><td class="value" style="text-align:left; font-weight:normal;">Appeals are limited. The Arbiter's decision is final. If the amount in controversy exceeds $10,000.00, a party may appeal to a three-Arbiter panel, decided "de novo" by majority vote. The Appealing party bears appeal costs regardless of outcome.</td></tr>
</table>

<table class="rows" style="margin-top:12px;">
    <tr><td class="label" style="width:30%">Will we advance Arbitration Fees?</td><td class="value" style="text-align:left; font-weight:normal;">Yes, but you may pay costs. We advance filing, administrative, hearing, and Arbiter's fees. You pay your own attorney fees and other expenses.</td></tr>
    <tr><td class="label">Are damages and attorney fees possible?</td><td class="value" style="text-align:left; font-weight:normal;">Yes, if allowed. The Arbiter may award the same damages as a court, plus reasonable attorney fees and expenses, if allowed by law.</td></tr>
    <tr><td class="label">Will you pay Arbitration Fees if you win?</td><td class="value" style="text-align:left; font-weight:normal;">No. If the Arbiter awards you funds, you don't reimburse us the Arbitration Fees.</td></tr>
    <tr><td class="label">Will you ever pay Arbitration Fees?</td><td class="value" style="text-align:left; font-weight:normal;">In some cases. If the Arbiter doesn't award you funds, you may need to repay the Arbitration Fees, though the amount won't exceed state court costs.</td></tr>
    <tr><td class="label">What happens if you win?</td><td class="value" style="text-align:left; font-weight:normal;">You could get more than the Arbiter awarded. If your award exceeds our last Settlement Offer, we pay the greater of the award or $2,500.00 (the "bonus payment"), plus your reasonable attorney fees actually incurred plus 25% (the "attorney premium"), plus reasonable expert witness costs and other costs if the Arbiter orders it (the "cost premium").</td></tr>
    <tr><td class="label">Can an award be explained?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. A party may request details from the Arbiter within 14 days of the ruling.</td></tr>
    <tr><td class="label">If you don't want to arbitrate, can you still get a transaction?</td><td class="value" style="text-align:left; font-weight:normal;">Yes. You can get our services and decide not to arbitrate: (1) informal dispute resolution by contacting us; (2) small-claims court within state law limits; (3) get a rental transaction without the Clause by writing to ask for a contract without it; (4) opt out of arbitration by signing and then timely opting out.</td></tr>
    <tr><td class="label">Can you opt out of the Clause?</td><td class="value" style="text-align:left; font-weight:normal;">Yes, within 60 days. Write us within 60 calendar days of signing to opt out of the Clause for this agreement, listing your name, address, account number, date, and that you "opt out."</td></tr>
</table>

<h2 class="page-start">Product info &amp; early purchase option</h2>
<table class="rows">
    <tr><td class="label">Dealer</td><td class="value">Prostart Leasing</td></tr>
    <tr><td class="label">Cash Price</td><td class="value">${{ number_format($cashPrice, 2) }}</td></tr>
    <tr><td class="label">Tax Rate</td><td class="value">{{ number_format($lease->sales_tax_rate * 100, 2) }}%</td></tr>
    <tr><td class="label">LDW</td><td class="value">{{ $lease->ldw_selected ? 'Yes' : 'No' }}</td></tr>
    <tr><td class="label">Total Paying Today</td><td class="value">${{ number_format($totalDueToday, 2) }}</td></tr>
    <tr><td class="label">Promo</td><td class="value">{{ $lease->promo_code ?? '—' }}</td></tr>
</table>

<div class="signature-block keep-together">
    <table>
        <tr>
            <td>
                <div class="head">Signed by</div>
                <div class="val">{{ $contract->signer_name ?? $customerName }}</div>
            </td>
            <td>
                <div class="head">Timestamp</div>
                <div class="val">{{ $contract->signed_at?->format('M j, Y g:i A') }}</div>
            </td>
            <td>
                <div class="head">Status</div>
                <div class="val">Signed &amp; legally valid</div>
            </td>
        </tr>
    </table>
    <p class="terms" style="margin-top:10px;">
        By signing above, you agree to the Lease Purchase Agreement in full, including the Jury Trial Waiver &amp;
        Arbitration Clause above.
    </p>
</div>

@if ($lease->autopay_enabled)
<h2 class="page-start">Autopay lease payment authorization</h2>
<div class="keep-together">
<p class="terms">
    By choosing to sign below, you elect to make your monthly lease payments automatically as set forth in this
    Agreement, using the bank account or payment method you verified and connected during your application.
</p>
<table class="rows">
    <tr><td class="label">Payment Amount</td><td class="value">${{ number_format($totalMonthly, 2) }}</td></tr>
    <tr><td class="label">Payment Due Day</td><td class="value">{{ $lease->payment_due_day ?? '—' }}</td></tr>
    <tr><td class="label">Payment Frequency</td><td class="value">Monthly</td></tr>
</table>
</div>
<p class="terms">
    <strong>Payment Dates &amp; Notice of Variation.</strong> We will initiate payments from your verified payment
    method on or after each Payment Due Date, in the amount described above, plus any accrued but unpaid rental
    charges, up to a maximum of $30.00 more than your regularly-scheduled payment amount. You will receive notice at
    least 10 days before a payment is deducted if it falls outside that range.
</p>
<p class="terms">
    <strong>Revocation.</strong> This Payment Authorization applies until you revoke it. You may revoke it by
    notifying us in writing at least 3 business days before a scheduled payment. Revoking this authorization does not
    relieve you of your obligation to make Rental Payments by another method.
</p>
<div class="keep-together">
<p class="terms">
    <strong>Acknowledgment.</strong> By signing below, you agree to the terms of this Payment Authorization and
    acknowledge that you were not required to sign it to obtain this lease; you may arrange payment via an
    alternative method at any time.
</p>
<div class="signature-block">
    <table>
        <tr>
            <td>
                <div class="head">Signed by</div>
                <div class="val">{{ $contract->signer_name ?? $customerName }}</div>
            </td>
            <td>
                <div class="head">Timestamp</div>
                <div class="val">{{ $contract->signed_at?->format('M j, Y g:i A') }}</div>
            </td>
            <td>
                <div class="head">Status</div>
                <div class="val">Autopay authorized</div>
            </td>
        </tr>
    </table>
</div>
</div>
@endif

@if ($equipment?->gps_device_id)
<h2 class="page-start">GPS disclosure statement &amp; agreement</h2>
<p class="terms">
    The Property you are leasing is equipped with a GPS device (the &ldquo;Device&rdquo;). You acknowledge that you
    are free to lease or purchase a property from another dealer, or obtain a property through another source, that
    does not require installation of a GPS device instead of leasing this Property from us.
</p>
<p class="terms">
    You understand that if you fail to make your renewal payments or fail to return the Property by the required
    date, you will be considered in default under this Agreement. In that event, we may use the Device to locate the
    Property for repossession and any other purpose not prohibited by law. The GPS will not be used to monitor your
    habits or practices, but may be activated to confirm the Device is functioning and that the Property has not been
    moved from the address you provided or otherwise approved by us. We will not provide access to the Device's
    tracking record unless required by law or to enforce our rights under this Agreement.
</p>
<div class="keep-together">
<p class="terms">
    If you alter, tamper with, disconnect, or remove the Device, you will be in default under this Agreement. You
    may be liable for the cost to repair or replace the Device, unless prohibited by law, and you agree to give us
    access to the Property if maintenance or repairs to the Device are necessary. By signing below, you acknowledge
    that you have read, accept, and understand the terms of this GPS Disclosure Statement, and that it is
    incorporated into and becomes part of this Agreement.
</p>
<div class="signature-block">
    <table>
        <tr>
            <td>
                <div class="head">Signed by</div>
                <div class="val">{{ $contract->signer_name ?? $customerName }}</div>
            </td>
            <td>
                <div class="head">Timestamp</div>
                <div class="val">{{ $contract->signed_at?->format('M j, Y g:i A') }}</div>
            </td>
            <td>
                <div class="head">Status</div>
                <div class="val">GPS disclosure acknowledged</div>
            </td>
        </tr>
    </table>
</div>
</div>
@endif

</body>
</html>
