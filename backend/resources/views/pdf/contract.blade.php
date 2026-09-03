<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #171717; }
    h1 { font-size: 18px; text-transform: uppercase; margin: 0 0 4px; }
    h2 { font-size: 13px; text-transform: uppercase; border-bottom: 2px solid #171717; padding-bottom: 6px; margin: 18px 0 8px; }
    .sub { background: #f5f5f5; padding: 5px 8px; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #737373; margin-top: 10px; }
    table.rows { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.rows td { padding: 5px 0; border-bottom: 1px solid #f0f0f0; font-size: 10.5px; }
    table.rows td.label { color: #737373; width: 60%; }
    table.rows td.value { font-weight: bold; text-align: right; }
    p.terms { font-size: 9px; font-style: italic; color: #a3a3a3; line-height: 1.5; margin-top: 10px; }
    .meta { color: #737373; font-size: 10px; margin-bottom: 12px; }
    .signature-block { margin-top: 22px; border-top: 2px solid #171717; padding-top: 10px; }
    .signature-block table { width: 100%; }
    .signature-block td { width: 33%; vertical-align: top; }
    .signature-block .head { font-size: 9px; text-transform: uppercase; color: #a3a3a3; font-weight: bold; }
    .signature-block .val { font-size: 11px; font-weight: bold; margin-top: 2px; }
</style>
</head>
<body>

<h1>Lease Purchase Agreement</h1>
<p class="meta">{{ $customerName }} &middot; Outdoor Fix &middot; Contract #{{ $contract->id }} (v{{ $contract->version }})</p>

<h2>Customer information &amp; authorization</h2>
<table class="rows">
    <tr><td class="label">Renter Name</td><td class="value">{{ $customerName }}</td></tr>
    <tr><td class="label">Mailing Address</td><td class="value">{{ $customerAddress ?: '—' }}</td></tr>
</table>

<h2>Lease information summary</h2>
<div class="sub">Description of leased property</div>
<table class="rows">
    <tr><td class="label">Cash Price / Retail</td><td class="value">${{ number_format($cashPrice, 2) }}</td></tr>
    <tr><td class="label">Make</td><td class="value">{{ $equipment->model ?? '—' }}</td></tr>
    <tr><td class="label">Serial # / VIN</td><td class="value">{{ $equipment->serial_number ?? '—' }}</td></tr>
    <tr><td class="label">Description or Damage to Property</td><td class="value">{{ $equipment->condition_notes ?: 'None noted' }}</td></tr>
</table>

<div class="sub">Lease details</div>
<table class="rows">
    <tr><td class="label">Months to Ownership</td><td class="value">{{ $lease->term_months }}</td></tr>
    <tr><td class="label">Payment Due Day</td><td class="value">{{ $lease->payment_due_day ?? '—' }}</td></tr>
    <tr><td class="label">Rental Payment</td><td class="value">${{ number_format($monthlyRental, 2) }}</td></tr>
    <tr><td class="label">Sales Tax</td><td class="value">${{ number_format($salesTax, 2) }}</td></tr>
    <tr><td class="label">Total Monthly Payment</td><td class="value">${{ number_format($totalMonthly, 2) }}</td></tr>
    <tr><td class="label">Security Deposit</td><td class="value">${{ number_format($securityDeposit, 2) }}</td></tr>
    <tr><td class="label">TOTAL DUE TODAY</td><td class="value">${{ number_format($totalDueToday, 2) }}</td></tr>
    <tr><td class="label">AutoPay</td><td class="value">{{ $lease->autopay_enabled ? 'Yes' : 'No' }}</td></tr>
    <tr><td class="label">Total Rental-Purchase Price</td><td class="value">${{ number_format($totalRentalPurchasePrice, 2) }}</td></tr>
</table>

<p class="terms">
    <strong>2. Lease Term &amp; Payment Schedule.</strong> This Agreement is for one month. It begins on the effective
    date of this Agreement and expires one month later. You can renew the Agreement for additional one-month terms at
    your option by making a monthly rental renewal payment on or before the expiration date. The Agreement will also
    renew if you continue to possess the Property until you notify us that you want to end the rental and make the
    Property available for pickup.
</p>
<p class="terms">
    <strong>3. Rental-Purchase Ownership.</strong> If you renew this Agreement for {{ $lease->term_months }} months in
    a row, you will have paid the Total Rental-Purchase Price of ${{ number_format($totalRentalPurchasePrice, 2) }},
    not including taxes or fees, and you will obtain ownership of the Property after the final payment. Or, you can
    exercise an early purchase option ("EPO"). Any time within 90 days of the effective date of this Agreement, your
    EPO price will be the Cash Price less all Rental Payments paid to date (excludes taxes and fees). After that time,
    your EPO price will be the Cash Price less 50% of Rental Payments scheduled to date, plus any Rental Payments
    still owed and any additional funds. You will not own the Property unless you pay the Total Rental-Purchase Price
    or exercise an EPO. The Total Rental-Purchase Price does not include other charges such as late fees, disclosed
    below. Taxes are also due at the time of exercising an EPO.
</p>

<h2>Product info &amp; early purchase option</h2>
<table class="rows">
    <tr><td class="label">Dealer</td><td class="value">Outdoor Fix</td></tr>
    <tr><td class="label">Cash Price</td><td class="value">${{ number_format($cashPrice, 2) }}</td></tr>
    <tr><td class="label">Tax Rate</td><td class="value">{{ number_format($lease->sales_tax_rate * 100, 2) }}%</td></tr>
    <tr><td class="label">LDW</td><td class="value">{{ $lease->ldw_selected ? 'Yes' : 'No' }}</td></tr>
    <tr><td class="label">Total Paying Today</td><td class="value">${{ number_format($totalDueToday, 2) }}</td></tr>
    <tr><td class="label">Promo</td><td class="value">{{ $lease->promo_code ?? '—' }}</td></tr>
</table>

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
                <div class="val">Signed &amp; legally valid</div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>
