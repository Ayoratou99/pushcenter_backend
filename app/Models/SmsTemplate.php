<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'message',
        'message_length',
        'segments_count',
        'variables',
        'sample_data',
        'category',
        'status',
        'is_active',
        'sender_id',
        'cost_per_message',
        'usage_count',
        'last_used_at',
        'metadata',
    ];

    protected $casts = [
        'variables' => 'array',
        'sample_data' => 'array',
        'is_active' => 'boolean',
        'cost_per_message' => 'decimal:2',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Calculate message length and segments.
     */
    public function calculateSegments(): void
    {
        $length = mb_strlen($this->message);
        $this->message_length = $length;
        
        // SMS standard: 160 chars per segment for GSM-7, 70 for UCS-2
        $segmentLength = 160;
        $this->segments_count = ceil($length / $segmentLength);
    }

    /**
     * Increment usage count and update last used timestamp.
     */
    public function markAsUsed(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }
}


