<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use App\Services\InfoRequestResponder;
use App\Services\InfoRequestSigner;
use Illuminate\Http\Request;

/**
 * The signed-link counterpart to Customer\ApplicationController's info-request
 * endpoints — reached from ApplicationInfoRequestedNotification's emailed
 * link rather than an authenticated session, since a guest-originated
 * customer has no working login yet (see InfoRequestSigner). Every request
 * here re-validates the signature itself; nothing here trusts Sanctum.
 */
class PublicInfoRequestController extends Controller
{
    public function show(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $application = Application::with('infoRequests')->findOrFail($request->integer('application'));
        abort_unless($application->customer_id === $customer->id, 404);

        $openInfoRequest = $application->infoRequests()->whereNull('replied_at')->latest()->first();

        return response()->json(['data' => [
            'application_id' => $application->id,
            'status' => $application->status,
            'open_request_text' => $openInfoRequest?->request_text,
        ]]);
    }

    public function store(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $application = Application::findOrFail($request->integer('application'));
        abort_unless($application->customer_id === $customer->id, 404);
        abort_unless($application->status === Application::STATUS_NEEDS_INFO, 422, 'This application is not awaiting information.');

        $data = $request->validate([
            'reply_text' => ['required_without:id_document', 'nullable', 'string', 'max:1000'],
            'id_document' => ['required_without:reply_text', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $application = InfoRequestResponder::respond($application, $customer, $data['reply_text'] ?? null, $data['id_document'] ?? null);

        return response()->json(['data' => ['application_id' => $application->id, 'status' => $application->status]]);
    }

    private function resolveSignedCustomer(Request $request): User
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'application' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $customer = User::find($data['id']);
        $linkIsValid = $customer
            && $customer->isCustomer()
            && hash_equals(sha1($customer->email), $data['hash'])
            && InfoRequestSigner::isValid($data['id'], $data['application'], $data['hash'], $data['expires'], $data['signature']);

        abort_unless($linkIsValid, 422, 'This link is invalid or has expired.');

        return $customer;
    }
}
