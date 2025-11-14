<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'display_name',
        'description',
        'language',
        'category',
        'header',
        'body',
        'footer',
        'buttons',
        'components',
        'variables',
        'sample_data',
        'template_id',
        'facebook_template_id',
        'status',
        'facebook_status',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'quality_score',
        'metadata',
        'cost_per_message',
        'usage_count',
        'last_used_at',
        'is_active',
        'allow_variables',
        'max_variables',
        'media_file_size',
    ];

    protected $casts = [
        'header' => 'array',
        'footer' => 'array',
        'buttons' => 'array',
        'components' => 'array',
        'variables' => 'array',
        'sample_data' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'quality_score' => 'array',
        'metadata' => 'array',
        'cost_per_message' => 'decimal:2',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
        'allow_variables' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeByLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Check if template is approved and active.
     */
    public function isReady(): bool
    {
        return $this->status === 'approved' && $this->is_active;
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


