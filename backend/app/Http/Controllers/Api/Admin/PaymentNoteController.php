<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PaymentNote;
use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * What staff write about one payment, and the history of everything that was done to it (client, Joel, 2026-10-19 checklist,
 * section 9). Notes are never shown to customers.
 */
class PaymentNoteController extends Controller
{
    /** The notes and the audit trail of one payment, newest first. */
    public function history(Payment $payment)
    {
        return response()->json(['data' => [
            'notes' => $payment->notes()->with('author:id,name')->get()->sortByDesc('id')->values(),
            'audit' => PaymentAuditLog::where('payment_id', $payment->id)->orderByDesc('id')->limit(200)->get(),
        ]]);
    }

    public function store(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PaymentNote::KINDS))],
            // Same length as a note on an application (a short paragraph).
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $note = $payment->notes()->create([
            'kind' => $data['kind'],
            'body' => trim($data['body']),
            'author_user_id' => Auth::id(),
        ]);
        AuditLog::record('note.added', $data['kind'] === PaymentNote::KIND_GENERAL ? 'A note was added.' : sprintf('A %s note was added.', strtolower(PaymentNote::KINDS[$data['kind']])), ['payment' => $payment, 'details' => ['note_id' => $note->id, 'kind' => $data['kind']]]);

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }
}
