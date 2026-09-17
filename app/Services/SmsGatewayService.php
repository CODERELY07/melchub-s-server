<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends SMS via "SMS Gateway for Android" (capcom6) — either the cloud relay
 * (api.sms-gate.app) or a self-hosted/local instance, both of which speak the
 * same REST API. See docs/sms-and-payments.md for setup.
 */
class SmsGatewayService
{
    public function send(string $phoneNumber, string $text): void
    {
        $baseUrl = rtrim((string) config('services.sms_gateway.base_url'), '/');
        $username = config('services.sms_gateway.username');
        $password = config('services.sms_gateway.password');

        if (! $baseUrl || ! $username || ! $password) {
            throw new RuntimeException('SMS gateway is not configured. Set SMS_GATEWAY_* in your .env.');
        }

        $payload = [
            'phoneNumbers' => [$this->normalizePhoneNumber($phoneNumber)],
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
            throw new RuntimeException(
                "SMS gateway request failed ({$response->status()}): {$response->body()}"
            );
        }
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
