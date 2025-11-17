<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Template extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'template_identifier',
        'description',
        'type',
        'category',
        'status',
        'is_active',
        'usage_count',
        'last_used_at',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function whatsappMessages()
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function smsMessages()
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function emailMessages()
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Generate a unique template identifier based on the name.
     */
    public static function generateIdentifier(string $name, int $businessId, string $type): string
    {
        // Convert name to slug (lowercase, replace spaces with underscores, remove special chars)
        $baseIdentifier = strtolower(trim($name));
        $baseIdentifier = preg_replace('/[^a-z0-9]+/', '_', $baseIdentifier);
        $baseIdentifier = preg_replace('/_+/', '_', $baseIdentifier);
        $baseIdentifier = trim($baseIdentifier, '_');
        
        // Ensure it's not empty
        if (empty($baseIdentifier)) {
            $baseIdentifier = 'template';
        }
        
        $identifier = $baseIdentifier;
        $counter = 1;
        
        // Check if identifier already exists for this business and type
        while (static::where('business_id', $businessId)
            ->where('template_identifier', $identifier)
            ->where('type', $type)
            ->exists()) {
            $identifier = $baseIdentifier . '_' . $counter;
            $counter++;
        }
        
        return $identifier;
    }

    /**
     * Boot method to auto-generate identifier before creating.
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($template) {
            if (empty($template->template_identifier)) {
                $template->template_identifier = static::generateIdentifier(
                    $template->name,
                    $template->business_id,
                    $template->type
                );
            }
        });
    }
}
