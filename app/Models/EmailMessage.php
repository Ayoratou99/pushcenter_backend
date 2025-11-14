<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'message_id',
        'template_id',
        'is_template',
        'recipient_email',
        'recipient_name',
        'sender_email',
        'sender_name',
        'subject',
        'content',
        'template_variables',
        'attachments',
        'cc',
        'bcc',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'template_variables' => 'array',
        'attachments' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
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
