<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Uploads borrower-submitted GCash payment screenshots to a Supabase Storage
 * bucket via its REST API, using the project's service-role key (bypasses
 * Row Level Security, so no per-admin login is needed). This app already
 * uses Supabase for its Postgres database (see docs/supabase.md); this reuses
 * the same project instead of a separate Google Cloud project. See
 * docs/sms-and-payments.md for the full setup tutorial.
 */
class SupabaseStorageService
{
    /**
     * Credentials are resolved lazily, on first actual upload — not in the
     * constructor. This service is injected into PaymentProofController for
     * every route on it, including ones (listing, approving, rejecting) that
     * never touch storage at all; those must keep working even before
     * Supabase Storage has been configured.
     */
    private function config(): array
    {
        $url = rtrim((string) config('services.supabase_storage.url'), '/');
        $key = config('services.supabase_storage.service_role_key');
        $bucket = config('services.supabase_storage.bucket');

        if (! $url || ! $key || ! $bucket) {
            throw new RuntimeException(
                'Supabase Storage is not configured. Set SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, and SUPABASE_STORAGE_BUCKET in your .env.'
            );
        }

        return [$url, $key, $bucket];
    }

    /**
     * Uploads a file to the configured bucket and returns a path + a public
     * URL. The bucket must be public (see the setup tutorial) — that's what
     * lets the admin open the screenshot straight from a link with no
     * separate Supabase login, the same way the file was made link-shareable
     * under the previous Google Drive integration.
     *
     * @return array{id: string, url: string}
     */
    public function upload(UploadedFile $file, string $name): array
    {
        [$url, $key, $bucket] = $this->config();

        $path = $name;
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        $response = Http::withToken($key)
            ->withHeaders(['apikey' => $key])
            ->withBody(file_get_contents($file->getRealPath()), $mimeType)
            ->post("{$url}/storage/v1/object/{$bucket}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Supabase Storage upload failed ({$response->status()}): {$response->body()}"
            );
        }

        return [
            'id' => $path,
            'url' => "{$url}/storage/v1/object/public/{$bucket}/{$path}",
        ];
    }
}
