<?php

namespace App\Services;

use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\TelegramTemplate;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Export a template to a portable document, and import it back into any
 * application. The payload carries the template content only: ids, business,
 * usage counters and approval state are never transferred.
 */
class TemplateTransferService
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_TXT = 'txt';

    public const VERSION = 1;

    /**
     * Template types and the model backing each of them.
     */
    public const TYPES = [
        'email' => EmailTemplate::class,
        'sms' => SmsTemplate::class,
        'whatsapp' => WhatsappTemplate::class,
        'telegram' => TelegramTemplate::class,
    ];

    /**
     * Fields carried over per type. Everything else is rebuilt on import.
     */
    private const PORTABLE_FIELDS = [
        'email' => [
            'name', 'subject', 'description', 'design', 'html', 'plain_text',
            'variables', 'sample_data', 'category', 'metadata',
        ],
        'sms' => [
            'name', 'description', 'message', 'message_length', 'segments_count',
            'variables', 'sample_data', 'category', 'cost_per_message', 'metadata',
        ],
        'whatsapp' => [
            'name', 'display_name', 'description', 'language', 'category',
            'header', 'body', 'footer', 'buttons', 'components', 'variables',
            'sample_data', 'cost_per_message', 'allow_variables', 'max_variables',
            'metadata',
        ],
        // A kept file is not carried in the document: attach it again after an import.
        'telegram' => [
            'name', 'description', 'category', 'body', 'parse_mode', 'buttons',
            'disable_web_page_preview', 'media_type', 'media_source', 'variables', 'sample_data', 'metadata',
        ],
    ];

    public function modelFor(string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            throw ValidationException::withMessages([
                'type' => ["Unsupported template type \"{$type}\". Expected one of: " . implode(', ', array_keys(self::TYPES)) . '.'],
            ]);
        }

        return self::TYPES[$type];
    }

    /**
     * Build the portable document for one template.
     *
     * @return array<string, mixed>
     */
    public function export(Model $template, string $type): array
    {
        $fields = self::PORTABLE_FIELDS[$type];

        return [
            'format' => 'aninfpush.template',
            'version' => self::VERSION,
            'type' => $type,
            'exported_at' => now()->toIso8601String(),
            'source' => [
                'template_id' => $template->getKey(),
                'business_id' => $template->business_id,
                'business_name' => $template->business?->name,
            ],
            'template' => collect($template->only($fields))
                ->map(fn ($value) => $value instanceof \Illuminate\Support\Carbon ? $value->toIso8601String() : $value)
                ->all(),
        ];
    }

    /**
     * Bundle several templates into one document.
     *
     * @param  iterable<Model>  $templates
     * @return array<string, mixed>
     */
    public function exportMany(iterable $templates, string $type): array
    {
        $items = [];

        foreach ($templates as $template) {
            $items[] = $this->export($template, $type)['template'];
        }

        return [
            'format' => 'aninfpush.template',
            'version' => self::VERSION,
            'type' => $type,
            'exported_at' => now()->toIso8601String(),
            'templates' => $items,
        ];
    }

    /**
     * Serialise a document in the requested format.
     *
     * JSON is the canonical format; TXT is the very same JSON wrapped in a short
     * human readable header, so a .txt export can be re-imported as-is.
     *
     * @param  array<string, mixed>  $document
     */
    public function serialize(array $document, string $format): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($format === self::FORMAT_TXT) {
            return implode(PHP_EOL, [
                '# AninfPush template export',
                '# type: ' . $document['type'],
                '# exported_at: ' . $document['exported_at'],
                '# Keep this file as-is, it can be imported back from the Templates page.',
                '',
                $json,
                '',
            ]);
        }

        return $json;
    }

    public function filename(string $name, string $type, string $format): string
    {
        $slug = Str::slug($name) ?: 'template';

        return sprintf('%s-%s-%s.%s', $type, $slug, now()->format('Ymd-His'), $format);
    }

    /**
     * Read back a document produced by serialize(), from JSON or TXT.
     *
     * @return array<string, mixed>
     */
    public function parse(string $contents): array
    {
        $contents = trim($contents);

        if ($contents === '') {
            throw ValidationException::withMessages([
                'file' => ['The import file is empty.'],
            ]);
        }

        // Strip the TXT header comments, then take everything from the first
        // opening brace so a copy/pasted file still imports.
        $body = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
        $start = strpos($body, '{');

        if ($start !== false) {
            $body = substr($body, $start);
        }

        $decoded = json_decode(trim($body), true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be read. Expected a JSON or TXT export produced by AninfPush.'],
            ]);
        }

        return $decoded;
    }

    /**
     * Normalise a parsed document into a list of template payloads.
     *
     * @param  array<string, mixed>  $document
     * @return array{type: string|null, templates: array<int, array<string, mixed>>}
     */
    public function extract(array $document): array
    {
        $type = $document['type'] ?? null;

        if (isset($document['templates']) && is_array($document['templates'])) {
            $templates = $document['templates'];
        } elseif (isset($document['template']) && is_array($document['template'])) {
            $templates = [$document['template']];
        } else {
            // Tolerate a bare template object (hand written or produced elsewhere).
            $templates = [$document];
        }

        $templates = array_values(array_filter($templates, 'is_array'));

        if ($templates === []) {
            throw ValidationException::withMessages([
                'file' => ['No template was found in this file.'],
            ]);
        }

        return ['type' => $type, 'templates' => $templates];
    }

    /**
     * Guess the type from the payload shape when the document does not say it.
     */
    public function guessType(array $template): ?string
    {
        if (array_key_exists('subject', $template) || array_key_exists('html', $template) || array_key_exists('design', $template)) {
            return 'email';
        }

        if (array_key_exists('message', $template)) {
            return 'sms';
        }

        if (array_key_exists('parse_mode', $template)) {
            return 'telegram';
        }

        if (array_key_exists('body', $template) || array_key_exists('components', $template)) {
            return 'whatsapp';
        }

        return null;
    }

    /**
     * Turn an imported payload into attributes ready for the model, attached to
     * the chosen application.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prepareAttributes(array $payload, string $type, Business $business, ?string $nameOverride = null): array
    {
        $attributes = collect($payload)
            ->only(self::PORTABLE_FIELDS[$type])
            ->all();

        $attributes['business_id'] = $business->getKey();
        $attributes['name'] = $this->uniqueName(
            $type,
            $business,
            $nameOverride ?: ($attributes['name'] ?? 'Imported template'),
            $attributes['language'] ?? null
        );

        // An imported template always lands as an inactive draft: it has to be
        // reviewed (and for WhatsApp, re-submitted to Meta) before being used.
        $attributes['status'] = 'draft';
        $attributes['is_active'] = false;
        $attributes['usage_count'] = 0;
        $attributes['last_used_at'] = null;

        $attributes = $this->applyTypeDefaults($attributes, $type);

        $attributes['metadata'] = array_merge(
            is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            ['imported_at' => now()->toIso8601String()],
        );

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyTypeDefaults(array $attributes, string $type): array
    {
        return match ($type) {
            'email' => array_merge([
                'subject' => $attributes['name'],
                'category' => 'marketing',
            ], array_filter($attributes, fn ($v) => $v !== null)),

            'sms' => array_merge([
                'message' => '',
                'category' => 'transactional',
            ], array_filter($attributes, fn ($v) => $v !== null) + [
                'message_length' => mb_strlen((string) ($attributes['message'] ?? '')),
                'segments_count' => max(1, (int) ceil(mb_strlen((string) ($attributes['message'] ?? '')) / 160)),
            ]),

            'whatsapp' => array_merge([
                'display_name' => $attributes['name'],
                'language' => 'fr',
                'category' => 'MARKETING',
                'body' => '',
            ], array_filter($attributes, fn ($v) => $v !== null)),

            'telegram' => array_merge([
                'category' => 'notification',
                'parse_mode' => 'HTML',
                'body' => '',
            ], array_filter($attributes, fn ($v) => $v !== null)),

            default => $attributes,
        };
    }

    /**
     * A copy in the same application, as an inactive draft: fix it, then
     * activate it (or, for WhatsApp, submit it to Meta).
     */
    public function duplicate(Model $template, string $type): Model
    {
        $model = $this->modelFor($type);
        $document = $this->export($template, $type);

        $attributes = $this->prepareAttributes(
            $document['template'],
            $type,
            $template->business()->withTrashed()->firstOrFail(),
            trim((string) ($template->name ?? 'Template')) . ($type === 'whatsapp' ? '_copy' : ' copy')
        );

        $metadata = $attributes['metadata'] ?? [];
        unset($metadata['imported_at']);
        $attributes['metadata'] = array_merge($metadata, [
            'duplicated_from' => $template->getKey(),
            'duplicated_at' => now()->toIso8601String(),
        ]);

        $copy = $model::create($attributes);

        // The copy gets its own file: deleting one template keeps the other's.
        if ($template instanceof TelegramTemplate && $template->media_path !== null) {
            $disk = TelegramTemplate::mediaDisk();
            $path = "telegram-media/{$copy->business_id}/" . Str::uuid() . '.' . pathinfo($template->media_path, PATHINFO_EXTENSION);

            if ($disk->exists($template->media_path) && $disk->copy($template->media_path, $path)) {
                $copy->forceFill([
                    'media_path' => $path,
                    'media_name' => $template->media_name,
                    'media_mime' => $template->media_mime,
                    'media_size' => $template->media_size,
                    // Same application, same bot: Telegram's ids still apply.
                    'media_file_ids' => $template->media_file_ids,
                ])->save();
            }
        }

        return $copy;
    }

    /**
     * Templates are unique per business (and per language for WhatsApp); suffix
     * the name until it is free instead of failing the import.
     */
    public function uniqueName(string $type, Business $business, string $name, ?string $language = null): string
    {
        $model = $this->modelFor($type);
        $base = Str::limit(trim($name), 200, '');

        // Meta names: lowercase letters, digits and underscores.
        if ($type === 'whatsapp') {
            $base = trim((string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower(Str::ascii($base))), '_') ?: 'template';
        }

        $candidate = $base;
        $counter = 1;

        while ($this->nameTaken($model, $type, $business->getKey(), $candidate, $language)) {
            $counter++;
            $candidate = $type === 'whatsapp' ? "{$base}_{$counter}" : "{$base} ({$counter})";
        }

        return $candidate;
    }

    private function nameTaken(string $model, string $type, int $businessId, string $name, ?string $language): bool
    {
        $query = $model::withTrashed()
            ->where('business_id', $businessId)
            ->where('name', $name);

        if ($type === 'whatsapp' && $language) {
            $query->where('language', $language);
        }

        return $query->exists();
    }
}
