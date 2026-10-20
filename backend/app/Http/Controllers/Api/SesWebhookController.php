<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SesEventProcessor;
use App\Services\SnsMessageVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Where Amazon SNS delivers what Amazon SES reports about each email (delivered, bounced, marked as spam, held up), so the email log
 * can say what happened after the email service accepted a message (client, Joel, 2026-10-15 and the 2026-10-19 checklist).
 *
 * Nothing in a message is believed until its signature is checked (SnsMessageVerifier) and it is seen to come from the one topic this
 * portal was set up with (SES_SNS_TOPIC_ARN): a valid message from some other topic is refused. The first message SNS sends is a request
 * to confirm the subscription, which is answered by opening the address it names (only when that is Amazon's own).
 */
class SesWebhookController extends Controller
{
    public function handle(Request $request, SnsMessageVerifier $verifier, SesEventProcessor $processor)
    {
        $topic = config('services.ses_events.topic_arn');
        if (! $topic) {
            Log::warning('An Amazon SNS message arrived before SES_SNS_TOPIC_ARN was configured, ignoring it.');

            return response()->json(['message' => 'Not configured.'], 503);
        }

        // SNS sends its JSON as text/plain, so the body is read as it came.
        $message = json_decode($request->getContent(), true);
        if (! is_array($message) || ! $verifier->isValid($message)) {
            Log::warning('Rejected an Amazon SNS message with an invalid payload or signature.');

            return response()->json(['message' => 'Invalid message.'], 400);
        }
        if (($message['TopicArn'] ?? null) !== $topic) {
            Log::warning('Rejected an Amazon SNS message from a topic that is not ours.', ['topic' => $message['TopicArn'] ?? null]);

            return response()->json(['message' => 'Unknown topic.'], 403);
        }

        switch ($message['Type']) {
            case 'SubscriptionConfirmation':
                $url = (string) ($message['SubscribeURL'] ?? '');
                if (SnsMessageVerifier::isSnsHost($url)) {
                    Http::timeout(10)->get($url);
                }
                break;

            case 'Notification':
                $event = json_decode((string) ($message['Message'] ?? ''), true);
                if (is_array($event)) {
                    $processor->apply($event);
                }
                break;
        }

        return response()->json(['message' => 'Processed.']);
    }
}
