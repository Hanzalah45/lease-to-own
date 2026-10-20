<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Additional Funds ledger (client, Joel, 2026-10-19 checklist, section 3: "show full Additional Funds history", "apply Additional
     * Funds to repairs", "refund Additional Funds if the unit is returned"; contract Section 19).
     *
     * A lease's additional_funds is the balance, this table is every movement of it: money added by an extra payment, taken off again
     * by a refund, applied to repairs, or corrected by hand. The balance is always the sum of the entries, and the entries are only ever
     * added. A repairs entry also carries the QuickBooks columns, because applying funds to repairs turns money held for the customer
     * into income and the books have to follow.
     */
    public function up(): void
    {
        Schema::create('additional_funds_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_refund_id')->nullable()->constrained()->nullOnDelete();
            // added (an extra payment was paid), reversed (one stopped being paid), refunded (money went back to the customer),
            // repairs (applied to the cost of repairs) or adjusted (corrected by hand).
            $table->string('kind', 12);
            // Signed: money in is positive, money out is negative.
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2);
            $table->string('description', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('quickbooks_record_id')->nullable();
            $table->string('quickbooks_sync_status', 16)->nullable();
            $table->timestamp('quickbooks_synced_at')->nullable();
            $table->text('quickbooks_sync_error')->nullable();
            $table->unsignedTinyInteger('quickbooks_sync_attempts')->default(0);
            $table->timestamps();
            $table->index(['lease_agreement_id', 'id']);
        });

        $this->backfill();
    }

    /**
     * Gives every lease that already holds Additional Funds its history, so the balance and the entries agree from the start: one
     * entry for each extra payment that was paid (and one taking it off again when it was refunded), then a correction for whatever
     * is left over (a balance somebody set by hand).
     */
    private function backfill(): void
    {
        $leaseIds = DB::table('payments')->where('type', 'extra_payment')->whereIn('status', ['paid', 'refunded'])->distinct()->pluck('lease_agreement_id')
            ->merge(DB::table('lease_agreements')->where('additional_funds', '>', 0)->pluck('id'))
            ->unique();

        foreach ($leaseIds as $leaseId) {
            $balance = 0.0;
            $now = now();

            $payments = DB::table('payments')->where('lease_agreement_id', $leaseId)->where('type', 'extra_payment')->whereIn('status', ['paid', 'refunded'])->orderBy('id')->get();
            foreach ($payments as $payment) {
                $balance = round($balance + (float) $payment->amount, 2);
                DB::table('additional_funds_entries')->insert([
                    'lease_agreement_id' => $leaseId, 'payment_id' => $payment->id, 'kind' => 'added', 'amount' => $payment->amount,
                    'balance_after' => $balance, 'description' => 'Extra payment received.', 'created_at' => $payment->paid_date ?? $now, 'updated_at' => $now,
                ]);
                if ($payment->status === 'refunded') {
                    $balance = round(max(0.0, $balance - (float) $payment->amount), 2);
                    DB::table('additional_funds_entries')->insert([
                        'lease_agreement_id' => $leaseId, 'payment_id' => $payment->id, 'kind' => 'reversed', 'amount' => -1 * (float) $payment->amount,
                        'balance_after' => $balance, 'description' => 'The extra payment was refunded.', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            $current = round((float) DB::table('lease_agreements')->where('id', $leaseId)->value('additional_funds'), 2);
            if (abs($current - $balance) >= 0.005) {
                DB::table('additional_funds_entries')->insert([
                    'lease_agreement_id' => $leaseId, 'payment_id' => null, 'kind' => 'adjusted', 'amount' => round($current - $balance, 2),
                    'balance_after' => $current, 'description' => 'Balance carried over from before the history was kept.', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_funds_entries');
    }
};
