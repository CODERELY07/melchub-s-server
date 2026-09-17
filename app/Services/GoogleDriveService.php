<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Uploads borrower-submitted GCash payment screenshots to Google Drive using
 * a service account (no per-admin Google login required). See
 * docs/sms-and-payments.md for the full setup tutorial.
 */
class GoogleDriveService
{
    private ?Drive $drive = null;

    /**
     * Credentials are resolved lazily, on first actual upload — not in the
     * constructor. This service is injected into PaymentProofController for
     * every route on it, including ones (listing, approving, rejecting) that
     * never touch Drive at all; those must keep working even before Google
     * Drive has been configured.
     */
    private function drive(): Drive
    {
        if ($this->drive === null) {
            $client = new Client();
            $client->setAuthConfig($this->resolveCredentials());
            $client->addScope(Drive::DRIVE_FILE);

            $this->drive = new Drive($client);
        }

        return $this->drive;
    }

    private function resolveCredentials(): array
    {
        $json = config('services.google_drive.credentials_json');

        if ($json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS_JSON is not valid JSON.');
            }

            return $decoded;
        }

        $path = config('services.google_drive.credentials_path');

        if ($path && file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                throw new RuntimeException("GOOGLE_DRIVE_CREDENTIALS_PATH ({$path}) does not contain valid JSON.");
            }

            return $decoded;
        }

        throw new RuntimeException(
            'Google Drive is not configured. Set GOOGLE_DRIVE_CREDENTIALS_JSON or GOOGLE_DRIVE_CREDENTIALS_PATH in your .env.'
        );
    }

    /**
     * Uploads a file, makes it viewable by anyone with the link (so the admin
     * can open it without needing access to the service account itself), and
     * returns ['id' => ..., 'url' => ...].
     *
     * @return array{id: string, url: string}
     */
    public function upload(UploadedFile $file, string $name): array
    {
        $drive = $this->drive();
        $folderId = config('services.google_drive.folder_id') ?: null;

        $fileMetadata = new DriveFile([
            'name' => $name,
            'parents' => $folderId ? [$folderId] : [],
        ]);

        $uploaded = $drive->files->create($fileMetadata, [
            'data' => file_get_contents($file->getRealPath()),
            'mimeType' => $file->getMimeType() ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields' => 'id,webViewLink',
        ]);

        $drive->permissions->create($uploaded->getId(), new Permission([
            'type' => 'anyone',
            'role' => 'reader',
        ]));

        return [
            'id' => $uploaded->getId(),
            'url' => $uploaded->getWebViewLink() ?: "https://drive.google.com/file/d/{$uploaded->getId()}/view",
        ];
    }
}
