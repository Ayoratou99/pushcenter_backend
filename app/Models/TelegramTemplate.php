<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TelegramTemplate extends Model
{
    use HasFactory, SoftDeletes;

    /** What a message can carry besides its text (sendPhoto, sendVideo, sendDocument). */
    public const MEDIA_TYPES = ['photo', 'video', 'document'];

    /**
     * "file": kept with the template, the same for every message.
     * "url": a link the application gives with each message, downloaded by Telegram.
     */
    public const MEDIA_SOURCES = ['file', 'url'];

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'category',
        'body',
        'parse_mode',
        'buttons',
        'disable_web_page_preview',
        'media_type',
        'media_source',
        'variables',
        'sample_data',
        'status',
        'is_active',
        'usage_count',
        'last_used_at',
        'metadata',
    ];

    // Where the file sits and Telegram's ids for it are internal.
    protected $hidden = [
        'media_path',
        'media_file_ids',
    ];

    protected $appends = [
        'has_media_file',
    ];

    // The column defaults, so a template just created answers with them.
    protected $attributes = [
        'category' => 'notification',
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
        'status' => 'active',
        'is_active' => true,
        'usage_count' => 0,
    ];

    protected $casts = [
        'buttons' => 'array',
        'variables' => 'array',
        'sample_data' => 'array',
        'metadata' => 'array',
        'media_file_ids' => 'array',
        'media_size' => 'integer',
        'disable_web_page_preview' => 'boolean',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'active' && $this->is_active;
    }

    public function markAsUsed(): void
    {
        $this->increment('usage_count');
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * The disk template files are kept on: storage/app/private by default,
     * which must be a persistent volume in Docker.
     */
    public static function mediaDisk(): Filesystem
    {
        return Storage::disk((string) config('services.telegram.media_disk', 'local'));
    }

    public function getHasMediaFileAttribute(): bool
    {
        return $this->media_source === 'file' && $this->media_path !== null;
    }

    /**
     * A "file" template without its file cannot be sent.
     */
    public function isMissingItsFile(): bool
    {
        return $this->media_source === 'file'
            && ($this->media_path === null || ! self::mediaDisk()->exists($this->media_path));
    }

    /**
     * Drops the kept file (the attachment settings are left to the caller).
     */
    public function forgetMediaFile(): void
    {
        if ($this->media_path !== null) {
            self::mediaDisk()->delete($this->media_path);
        }

        $this->forceFill([
            'media_path' => null,
            'media_name' => null,
            'media_mime' => null,
            'media_size' => null,
            'media_file_ids' => null,
        ]);
    }
}
