<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\BillingClock;
use App\Services\PaymentReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales tax and payment reports (client, Joel, 2026-10-16): what was collected by month, with the sales tax inside it, and a
 * download of every payment for the books. See PaymentReport for exactly what is counted.
 */
class ReportController extends Controller
{
    public function salesTax(Request $request, PaymentReport $report)
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $report->summary($from, $to) + [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]]);
    }

    public function paymentsCsv(Request $request, PaymentReport $report): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $rows = $report->rows($from, $to);
        $columns = [
            'paid_date' => 'Paid on',
            'customer' => 'Customer',
            'customer_email' => 'Email',
            'lease_id' => 'Lease',
            'type' => 'Type',
            'method' => 'Method',
            'amount' => 'Amount (bank price)',
            'taxable_sales' => 'Taxable sales',
            'sales_tax' => 'Sales tax',
            'tracking_fees' => 'Tracking fee',
            'card_fees' => 'Card fee',
            'total_collected' => 'Total collected',
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($columns));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($key) => $this->safeCell($row[$key] ?? ''), array_keys($columns)));
            }
            fclose($out);
        }, "payments-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * From and to dates (inclusive, in the business's own time zone); a whole year by default. A customer name that starts
     * with =, +, - or @ would be run as a formula by a spreadsheet, so a text cell never starts with one of those.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = BillingClock::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $today->copy()->startOfYear();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $today->copy();

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
