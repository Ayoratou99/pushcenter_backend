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
}
