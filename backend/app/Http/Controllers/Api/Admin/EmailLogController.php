<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff view of the email log (client, Joel, 2026-10-13): what the system
 * sent, to whom, and whether the email service accepted it.
 */
class EmailLogController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([EmailLog::STATUS_SENDING, EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED])],
            // What the mailbox provider did with a message the email service accepted (see SesEventProcessor).
            'delivery' => ['nullable', Rule::in([EmailLog::DELIVERY_DELIVERED, EmailLog::DELIVERY_BOUNCED, EmailLog::DELIVERY_COMPLAINT, EmailLog::DELIVERY_DELAYED, EmailLog::DELIVERY_REJECTED])],
        ]);

        $query = EmailLog::query()->with('user:id,name,role')->latest('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['delivery'])) {
            $query->where('delivery_status', $data['delivery']);
        }

        if (! empty($data['search'])) {
            // The typed text is data, not a pattern: a "%" or "_" in it must match itself.
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->where('to_address', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        $page = $query->paginate(self::PAGE_SIZE);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Everything sent to one customer, including mail that went to an address they have since corrected. */
    public function forCustomer(User $customer)
    {
        abort_unless($customer->isCustomer(), 404);

        $logs = EmailLog::query()
            ->where(fn ($q) => $q->where('user_id', $customer->id)->orWhere('to_address', $customer->email))
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $logs]);
    }
}
