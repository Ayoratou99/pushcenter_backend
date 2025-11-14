<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WhatsAppMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'message_id',
        'template_id',
        'is_template',
        'whatsapp_phone_number_id',
        'recipient_number',
        'recipient_name',
        'content',
        'media_url',
        'button_url',
        'button_text',
        'template_variables',
        'metadata',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'template_variables' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}
