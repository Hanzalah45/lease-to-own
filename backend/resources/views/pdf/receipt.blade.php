<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #171717; }
    .brand { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #737373; }
    h1 { font-size: 20px; text-transform: uppercase; margin: 2px 0 2px; }
    .number { font-size: 12px; color: #dc2626; font-weight: bold; }
    .head { border-bottom: 2px solid #171717; padding-bottom: 10px; margin-bottom: 14px; }
    table.meta { width: 100%; border-collapse: collapse; }
    table.meta td { padding: 4px 0; vertical-align: top; font-size: 10.5px; }
    table.meta td.label { width: 28%; color: #737373; }
    table.meta td.value { font-weight: bold; }
    h2 { font-size: 11px; text-transform: uppercase; color: #737373; margin: 18px 0 6px; letter-spacing: 1px; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines td { padding: 6px 0; border-bottom: 1px solid #eeeeee; font-size: 10.5px; }
    table.lines td.amount { text-align: right; }
    table.lines tr.total td { border-bottom: 0; border-top: 2px solid #171717; font-size: 13px; font-weight: bold; padding-top: 8px; }
    .balance { margin-top: 14px; background: #f5f5f5; padding: 8px 10px; font-size: 10.5px; }
    .balance .full { font-weight: bold; color: #166534; }
    .balance .left { font-weight: bold; }
    .foot { margin-top: 28px; border-top: 1px solid #d4d4d4; padding-top: 8px; font-size: 9px; color: #737373; line-height: 1.5; }
    @page { margin: 30px 30px 40px 30px; }
</style>
</head>
<body>
@php
    $refund = $receipt->kind === 'refund';
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $issued = \App\Services\PaymentReceipts::issuedDate($receipt);
    $paidOn = \Illuminate\Support\Carbon::parse($d['paid_on'])->format('F j, Y');
    $b = $d['balance'] ?? [];
@endphp

<div class="head">
    <div class="brand">Prostart Leasing</div>
    <h1>{{ $refund ? 'Refund receipt' : 'Payment receipt' }}</h1>
    <div class="number">{{ $receipt->receipt_number }}</div>
</div>

<table class="meta">
    <tr><td class="label">Issued</td><td class="value">{{ $issued->format('F j, Y g:i A T') }}</td></tr>
    <tr><td class="label">{{ $refund ? 'Refunded on' : 'Paid on' }}</td><td class="value">{{ $paidOn }}</td></tr>
    <tr><td class="label">Customer</td><td class="value">{{ $d['customer']['name'] }}@if(!empty($d['customer']['email'])) &middot; {{ $d['customer']['email'] }}@endif</td></tr>
    <tr><td class="label">Lease</td><td class="value">#{{ $d['lease_id'] }}@if(!empty($d['equipment'])) &middot; {{ $d['equipment'] }}@endif</td></tr>
    <tr><td class="label">{{ $refund ? 'Refund of' : 'For' }}</td><td class="value">{{ $d['description'] }}</td></tr>
    <tr><td class="label">{{ $refund ? 'Returned to' : 'Paid by' }}</td><td class="value">{{ $d['method_label'] }}</td></tr>
</table>

<h2>{{ $refund ? 'What was refunded' : 'What this payment covers' }}</h2>
<table class="lines">
    @foreach ($d['lines'] as $line)
        <tr><td>{{ $line['label'] }}</td><td class="amount">{{ $money($line['amount']) }}</td></tr>
    @endforeach
    <tr class="total"><td>{{ $refund ? 'Total refunded' : 'Total paid' }}</td><td class="amount">{{ $money($d['total']) }}</td></tr>
</table>

@if ($refund)
    <div class="balance">
        Original payment: {{ $money($b['price'] ?? 0) }}. Refunded so far: {{ $money($b['refunded_to_date'] ?? 0) }}.<br>
        A refund can take 5 to 10 business days to show on your statement.
    </div>
@else
    <div class="balance">
        @if (!empty($b['paid_in_full']))
            <span class="full">This payment is paid in full.</span>
        @else
            Paid toward this payment so far: {{ $money($b['paid_to_date'] ?? 0) }} of {{ $money($b['price'] ?? 0) }}.
            <span class="left">Still due: {{ $money($b['remaining'] ?? 0) }}.</span>
        @endif
    </div>
@endif

<div class="foot">
    Keep this receipt for your records.
    @if ($companyAddress) Prostart Leasing, {{ $companyAddress }}.@endif
    @if ($companyPhone) Questions? Call {{ $companyPhone }}.@endif
</div>
</body>
</html>
