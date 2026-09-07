<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The client's 2026-09-04 status redesign replaced the 9-status enum with a
 * new 6-stage flow (Application::ALL_STATUSES). Best-effort mapping for any
 * rows written under the old names — there's no production data yet, this is
 * purely for local/dev/test data hygiene.
 */
return new class extends Migration
{
    private const MAP = [
        'submitted' => 'waiting_review',
        'under_review' => 'waiting_review',
        // needs_info is unchanged — still a valid status name.
        'approved' => 'in_verification',
        'completed' => 'waiting_deposit',
        'processed' => 'waiting_delivery',
        'funded_paid' => 'finished',
        // declined / withdrawn are unchanged.
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('applications')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        // Not reversible: several old statuses collapse onto the same new
        // one (submitted/under_review both -> waiting_review), so the
        // original distinction can't be recovered.
    }
};
