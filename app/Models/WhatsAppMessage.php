<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WhatsAppMessage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The class name would give "whats_app_messages"; the table is whatsapp_messages.
     */
    protected $table = 'whatsapp_messages';

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
        'whatsapp_template_id',
        'sender_phone_number_id',
        'provider_template_name',
        'provider_request_id',
        'provider_status',
        'provider_message_id',
        'status_checks',
        'provider_checked_at',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'template_variables' => 'array',
        'metadata' => 'array',
        'provider_request_id' => 'integer',
        'status_checks' => 'integer',
        'provider_checked_at' => 'datetime',
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

    /**
     * The approved template the message was sent with (kept when soft-deleted).
     */
    public function whatsappTemplate()
    {
        return $this->belongsTo(WhatsappTemplate::class)->withTrashed();
    }
}
