<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\LeaseAgreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Renders a signed Contract's lease terms to a PDF and stores it on the
 * private "local" disk — never publicly exposed, only served through the
 * authenticated download endpoints (Customer\ContractController::download,
 * Admin\ContractController::download).
 *
 * The rendered HTML is captured once, at first generation (effectively at
 * signing time), and reused for every future regeneration. Without this, a
 * PDF that later needs rebuilding (e.g. the file went missing) would render
 * from whatever the Blade template and lease data say *today* — which could
 * differ from what the customer actually saw and agreed to when they signed.
 */
class ContractPdfService
{
    public static function generate(Contract $contract): string
    {
        $html = $contract->document_html ?? self::renderHtml($contract);

        if (! $contract->document_html) {
            $contract->update(['document_html' => $html]);
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->render();
        self::addPageNumbers($pdf->getDomPDF());

        $path = "contracts/{$contract->id}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    private static function renderHtml(Contract $contract): string
    {
        $contract->loadMissing(['leaseAgreement.equipmentUnit', 'leaseAgreement.customer.customerProfile']);
        $lease = $contract->leaseAgreement;
        $customer = $lease->customer;
        $profile = $customer->customerProfile;
        $address = collect([$profile?->address_line_1, $profile?->city, $profile?->state, $profile?->zip])
            ->filter()
            ->implode(', ');

        return View::make('pdf.contract', [
            'lease' => $lease,
            'contract' => $contract,
            'equipment' => $lease->equipmentUnit,
            'customerName' => $customer->name,
            'customerAddress' => $address,
            'profile' => $profile,
            'companyPhone' => config('company.phone'),
            'companyAddress' => config('company.address'),
            'cashPrice' => (float) $lease->cash_price,
            'monthlyRental' => (float) $lease->monthly_rental_payment,
            'salesTax' => $lease->salesTaxAmount(),
            'totalMonthly' => $lease->totalMonthlyPayment(),
            'securityDeposit' => (float) $lease->security_deposit,
            'trackingDeviceFee' => LeaseAgreement::TRACKING_DEVICE_FEE,
            'totalDueToday' => (float) $lease->security_deposit + LeaseAgreement::TRACKING_DEVICE_FEE + $lease->totalMonthlyPayment(),
            'totalRentalPurchasePrice' => (float) $lease->total_rental_purchase_price,
        ])->render();
    }

    /**
     * Stamps "Page X of Y" on every page's footer using DomPDF's native Canvas
     * text API. Deliberately avoids the `<script type="text/php">` trick some
     * DomPDF guides use for this, since that requires flipping the `enable_php`
     * config on (off by default for security) — calling the canvas directly
     * from our own PHP needs no such change.
     */
    private static function addPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $size = 8;
        $text = 'Prostart Leasing  ·  Lease Purchase Agreement  ·  Page {PAGE_NUM} of {PAGE_COUNT}';
        $width = $fontMetrics->getTextWidth($text, $font, $size);
        $x = ($canvas->get_width() - $width) / 2;
        $y = $canvas->get_height() - 28;

        $canvas->page_text($x, $y, $text, $font, $size, [0.64, 0.64, 0.64]);
    }

    /** Generates the PDF only if it doesn't already exist on disk, returning the stored path either way. */
    public static function ensure(Contract $contract): string
    {
        if ($contract->file_path && Storage::disk('local')->exists($contract->file_path)) {
            return $contract->file_path;
        }

        $path = self::generate($contract);
        $contract->update(['file_path' => $path]);

        return $path;
    }
}
