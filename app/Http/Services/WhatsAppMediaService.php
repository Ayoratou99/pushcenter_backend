<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class WhatsAppMediaService
{
    protected $accessToken;
    protected $baseUrl;
    protected $storageDisk;

    public function __construct()
    {
        $this->accessToken = config('services.whatsapp.access_token');
        $this->baseUrl = config('services.whatsapp.base_url', 'https://graph.facebook.com/v23.0');
        $this->storageDisk = config('services.whatsapp.storage_disk', 'public');
    }

    /**
     * Set credentials for multi-tenant support
     */
    public function setCredentials(string $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * Get media URL from WhatsApp API
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media#retrieve-media-url
     */
    public function getMediaUrl(string $mediaId): string
    {
        try {
            Log::info('Requesting media URL from Facebook', [
                'media_id' => $mediaId,
                'base_url' => $this->baseUrl
            ]);

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get("{$this->baseUrl}/{$mediaId}");

            if ($response->failed()) {
                Log::error('Failed to get media URL from Facebook', [
                    'media_id' => $mediaId,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Failed to get media URL: ' . $response->body());
            }

            $data = $response->json();
            $mediaUrl = $data['url'] ?? '';
            
            if (!$mediaUrl) {
                Log::error('No media URL in Facebook response', [
                    'media_id' => $mediaId,
                    'response_data' => $data
                ]);
                throw new \Exception('No media URL found in Facebook response');
            }

            Log::info('Media URL retrieved successfully from Facebook', [
                'media_id' => $mediaId,
                'media_url' => $mediaUrl,
                'mime_type' => $data['mime_type'] ?? 'unknown',
                'file_size' => $data['file_size'] ?? 'unknown'
            ]);

            return $mediaUrl;

        } catch (\Exception $e) {
            Log::error('Error getting media URL from Facebook', [
                'media_id' => $mediaId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Download media from WhatsApp and store locally
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media#download-media
     */
    public function downloadMedia(string $mediaUrl, string $mediaId, string $mediaType = 'unknown', ?string $conversationUuid = null): string
    {
        try {
            Log::info('Starting media download from Facebook', [
                'media_id' => $mediaId,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType
            ]);

            // Download the media file using the URL (valid for 5 minutes)
            // According to Facebook docs: GET {media-url} with Authorization header
            $response = Http::withToken($this->accessToken)
                ->timeout(60) // Increased timeout for large files
                ->get($mediaUrl);

            if ($response->failed()) {
                Log::error('Failed to download media from Facebook', [
                    'media_id' => $mediaId,
                    'media_url' => $mediaUrl,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Failed to download media: HTTP ' . $response->status());
            }

            // Get file extension from content type
            $contentType = $response->header('Content-Type');
            $extension = $this->getExtensionFromContentType($contentType, $mediaType);
            
            // Generate file path with conversation UUID for better organization
            if ($conversationUuid) {
                $directory = 'whatsapp/conversations/' . $conversationUuid . '/' . date('Y/m');
            } else {
                $directory = 'whatsapp/incoming/' . date('Y/m');
            }
            $filename = $mediaId . '_' . time() . '.' . $extension;
            $path = $directory . '/' . $filename;

            // Get file content
            $fileContent = $response->body();
            $fileSize = strlen($fileContent);

            // Validate file size (Facebook has limits)
            if ($fileSize === 0) {
                Log::error('Downloaded file is empty', [
                    'media_id' => $mediaId,
                    'media_url' => $mediaUrl
                ]);
                throw new \Exception('Downloaded file is empty');
            }

            // Store the file
            Storage::disk($this->storageDisk)->put($path, $fileContent);

            // Verify file was stored correctly
            if (!Storage::disk($this->storageDisk)->exists($path)) {
                Log::error('File was not stored correctly', [
                    'media_id' => $mediaId,
                    'path' => $path
                ]);
                throw new \Exception('File storage failed');
            }

            Log::info('Media downloaded and stored successfully', [
                'media_id' => $mediaId,
                'media_type' => $mediaType,
                'path' => $path,
                'file_size' => $fileSize,
                'content_type' => $contentType,
                'extension' => $extension
            ]);

            return $path;

        } catch (\Exception $e) {
            Log::error('Failed to download media', [
                'media_id' => $mediaId,
                'media_url' => $mediaUrl,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Get file extension from content type and media type
     */
    protected function getExtensionFromContentType(?string $contentType, string $mediaType): string
    {
        // Map of content types to extensions
        $contentTypeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/amr' => 'amr',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
        ];

        if ($contentType && isset($contentTypeMap[$contentType])) {
            return $contentTypeMap[$contentType];
        }

        // Fallback based on media type
        $mediaTypeMap = [
            'image' => 'jpg',
            'video' => 'mp4',
            'audio' => 'mp3',
            'document' => 'pdf',
            'sticker' => 'webp',
        ];

        return $mediaTypeMap[$mediaType] ?? 'bin';
    }

    /**
     * Get public URL for downloaded media
     */
    public function getPublicUrl(string $path): string
    {
        return \Illuminate\Support\Facades\Storage::disk($this->storageDisk)->url($path);
    }

    /**
     * Delete media file
     */
    public function deleteMedia(string $path): bool
    {
        try {
            return Storage::disk($this->storageDisk)->delete($path);
        } catch (\Exception $e) {
            Log::error('Failed to delete media', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if media file exists
     */
    public function mediaExists(string $path): bool
    {
        return Storage::disk($this->storageDisk)->exists($path);
    }

    /**
     * Get media file size
     */
    public function getMediaSize(string $path): int
    {
        try {
            return Storage::disk($this->storageDisk)->size($path);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Clean up old media files (older than specified days)
     */
    public function cleanupOldMedia(int $days = 30): int
    {
        $deletedCount = 0;
        $cutoffDate = now()->subDays($days);
        
        try {
            $files = Storage::disk($this->storageDisk)->allFiles('whatsapp');
            
            foreach ($files as $file) {
                $lastModified = Storage::disk($this->storageDisk)->lastModified($file);
                
                if ($lastModified < $cutoffDate->timestamp) {
                    if (Storage::disk($this->storageDisk)->delete($file)) {
                        $deletedCount++;
                    }
                }
            }
            
            Log::info('Media cleanup completed', [
                'deleted_count' => $deletedCount,
                'days' => $days
            ]);
            
        } catch (\Exception $e) {
            Log::error('Media cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $deletedCount;
    }
}