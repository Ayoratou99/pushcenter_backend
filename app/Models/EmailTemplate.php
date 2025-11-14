<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'subject',
        'description',
        'design', // Unlayer JSON design
        'html',
        'plain_text',
        'variables',
        'sample_data',
        'category',
        'status',
        'is_active',
        'usage_count',
        'last_used_at',
        'metadata',
    ];

    protected $casts = [
        'design' => 'array', // Unlayer design structure
        'variables' => 'array',
        'sample_data' => 'array',
        'is_active' => 'boolean',
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
     * Increment usage count and update last used timestamp.
     */
    public function markAsUsed(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }
}

