<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends SMS via "SMS Gateway for Android" (capcom6) — either the cloud relay
 * (api.sms-gate.app) or a self-hosted/local instance, both of which speak the
 * same REST API. See docs/sms-and-payments.md for setup.
 */
class SmsGatewayService
{
    /**
     * @param  int|null  $loanId  The loan this SMS is about, for the audit
     *                            trail (SmsLog) — null for sends that aren't
     *                            tied to a specific borrower, e.g. the
     *                            new-payment-proof alert sent to the admin.
     */
    public function send(string $phoneNumber, string $text, ?int $loanId = null): void
    {
        $normalized = $this->normalizePhoneNumber($phoneNumber);
        $baseUrl = rtrim((string) config('services.sms_gateway.base_url'), '/');
        $username = config('services.sms_gateway.username');
        $password = config('services.sms_gateway.password');

        if (! $baseUrl || ! $username || ! $password) {
            $error = 'SMS gateway is not configured. Set SMS_GATEWAY_* in your .env.';
            $this->log($loanId, $normalized, $text, false, $error);
            throw new RuntimeException($error);
        }

        $payload = [
            'phoneNumbers' => [$normalized],
            'textMessage' => ['text' => $text],
            'withDeliveryReport' => false,
        ];

        if ($simNumber = config('services.sms_gateway.sim_number')) {
            $payload['simNumber'] = (int) $simNumber;
        }

        $response = Http::withBasicAuth($username, $password)
            ->timeout(15)
            ->post("{$baseUrl}/messages", $payload);

        if ($response->failed()) {
            $error = "SMS gateway request failed ({$response->status()}): {$response->body()}";
            $this->log($loanId, $normalized, $text, false, $error);

            throw new RuntimeException($error);
        }

        $this->log($loanId, $normalized, $text, true, null);
    }

    /**
     * Every outgoing SMS attempt, success or failure, in one auditable table
     * — see docs/sms-and-payments.md for why (answering "did we actually
     * text them?" during a payment dispute, without needing gateway-side
     * delivery logs this app doesn't otherwise have access to).
     */
    private function log(?int $loanId, string $phone, string $message, bool $success, ?string $error): void
    {
        SmsLog::create([
            'loan_id' => $loanId,
            'phone' => $phone,
            'message' => $message,
            'success' => $success,
            'error' => $error,
        ]);
    }

    /**
     * Every phone number in this app is entered by staff as a plain local PH
     * mobile number (e.g. "09937538849" — see Loan::$fillable's unvalidated
     * `phone` field), but the gateway's cloud relay rejects anything that
     * isn't full E.164 with a country code ("invalid phone number"). Rather
     * than force every admin to remember to type +63 in a free-text field,
     * this normalizes at the one place all outgoing SMS already pass through.
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phoneNumber);

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '63')) {
            return "+{$digits}";
        }

        if (str_starts_with($digits, '0')) {
            return '+63'.substr($digits, 1);
        }

        return "+63{$digits}";
    }
}
