<?php

namespace App\Services\Contracts;

interface MediaStorageInterface
{
    /**
     * Generate direct upload authorization details (Presigned URL for S3/R2, or secure direct token for local).
     *
     * @param string $workspaceId
     * @param string $mediaId
     * @param string $originalName
     * @param string $mimeType
     * @param int $sizeBytes
     * @return array{
     *     upload_url: string,
     *     method: string,
     *     headers: array<string, string>,
     *     storage_path: string,
     *     expires_at: string
     * }
     */
    public function generateUploadAuthorization(
        string $workspaceId,
        string $mediaId,
        string $originalName,
        string $mimeType,
        int $sizeBytes
    ): array;

    /**
     * Verify that the uploaded object exists in storage and matches size/type requirements.
     *
     * @param string $storagePath
     * @param int $expectedSizeBytes
     * @param string $expectedMimeType
     * @return array{
     *     valid: bool,
     *     actual_size: int,
     *     actual_mime: ?string,
     *     error: ?string
     * }
     */
    public function verifyUploadedObject(
        string $storagePath,
        int $expectedSizeBytes,
        string $expectedMimeType
    ): array;

    /**
     * Resolve a public or signed retrieval URL for playback and thumbnailing.
     *
     * @param string $storagePath
     * @return string
     */
    public function getUrl(string $storagePath): string;

    /**
     * Resolve a secure temporary signed URL for external crawler access (e.g. Meta Graph API).
     *
     * @param string $storagePath
     * @param \DateTimeInterface $expiration
     * @return string
     */
    public function getTemporaryUrl(string $storagePath, \DateTimeInterface $expiration): string;

    /**
     * Delete an object from storage.
     *
     * @param string $storagePath
     * @return bool
     */
    public function deleteObject(string $storagePath): bool;
}
