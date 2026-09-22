<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram attachments: a photo, video or document sent with the text (which
 * becomes its caption). Either a file kept with the template, or a link the
 * application gives with each message, which Telegram downloads itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_templates', function (Blueprint $table) {
            $table->string('media_type', 16)->nullable()->after('buttons');     // photo | video | document
            $table->string('media_source', 16)->nullable()->after('media_type'); // file | url
            // The file kept with the template (source "file"), on the media disk.
            $table->string('media_path')->nullable()->after('media_source');
            $table->string('media_name')->nullable()->after('media_path');
            $table->string('media_mime', 128)->nullable()->after('media_name');
            $table->unsignedBigInteger('media_size')->nullable()->after('media_mime');
            // Telegram's file id of that file, per bot: uploaded once, then reused.
            $table->json('media_file_ids')->nullable()->after('media_size');
        });

        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->string('media_type', 16)->nullable()->after('reply_markup');
            $table->string('media_source', 16)->nullable()->after('media_type');
            // The link given by the application (source "url").
            $table->string('media_url', 2048)->nullable()->after('media_source');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'media_source', 'media_url']);
        });

        Schema::table('telegram_templates', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'media_source', 'media_path', 'media_name', 'media_mime', 'media_size', 'media_file_ids']);
        });
    }
};
