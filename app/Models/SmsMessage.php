<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SmsMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'message_id',
        'template_id',
        'is_template',
        'sms_phone_number_id',
        'recipient_number',
        'recipient_name',
        'content',
        'template_variables',
        'message_count',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'template_variables' => 'array',
        'message_count' => 'integer',
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
