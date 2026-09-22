<?php

namespace App\Services\AyosPush;

use App\Models\Business;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp templates through AyosPush: what can be submitted, submission,
 * status synchronisation and import of templates created on AyosPush.
 *
 * The rules below are the ones AyosPush (and Meta behind it) enforce, checked
 * up front so the console gets a precise message instead of a Meta error.
 */
class AyosPushTemplateService
{
    /**
     * POST /v1/templates only accepts these.
     */
    public const LANGUAGES = ['fr', 'en'];

    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    public const HEADER_FORMATS = ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'];

    public const BUTTON_TYPES = ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'];

    /**
     * Fields that make up what Meta approves. Frozen while the template is
     * pending, approved or disabled on the provider side.
     */
    public const CONTENT_FIELDS = [
        'name', 'language', 'category', 'header', 'body', 'footer', 'buttons',
        'components', 'variables', 'sample_data',
    ];

    /**
     * Body shown for AUTHENTICATION templates, whose text Meta generates.
     */
    public const AUTHENTICATION_BODIES = [
        'fr' => '{{1}} est votre code de vérification.',
        'en' => '{{1}} is your verification code.',
    ];

    /* --------------------------------------------------------------------- */
    /* Content normalisation                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Bring the content to one shape whatever sent it (the console, an import,
     * an older client): header {format, text | media_url}, footer {text},
     * buttons [{type, text, url | phone_number}], upper-case category, and
     * variables derived from the body.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeContent(array $input): array
    {
        if (array_key_exists('header', $input)) {
            $input['header'] = self::normalizeHeader($input['header']);
        }

        if (array_key_exists('footer', $input)) {
            $input['footer'] = self::normalizeFooter($input['footer']);
        }

        if (array_key_exists('buttons', $input)) {
            $input['buttons'] = self::normalizeButtons($input['buttons']);
        }

        if (isset($input['category']) && is_string($input['category'])) {
            $input['category'] = strtoupper(trim($input['category']));
        }

        if (isset($input['language']) && is_string($input['language'])) {
            $input['language'] = trim($input['language']);
        }

        if (($input['category'] ?? null) === 'AUTHENTICATION' && blank($input['body'] ?? null)) {
            $input['body'] = self::AUTHENTICATION_BODIES[$input['language'] ?? 'fr'] ?? self::AUTHENTICATION_BODIES['en'];
        }

        if (isset($input['body']) && is_string($input['body'])) {
            $input['variables'] = self::variablesIn($input['body']);
        }

        if (array_key_exists('sample_data', $input)) {
            $input['sample_data'] = self::normalizeSamples($input['sample_data']);
        }

        return $input;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizeHeader(mixed $header): ?array
    {
        if (! is_array($header)) {
            return null;
        }

        $format = strtoupper(trim((string) ($header['format'] ?? $header['type'] ?? '')));

        if ($format === '' || $format === 'NONE') {
            return null;
        }

        if ($format === 'TEXT') {
            return ['format' => 'TEXT', 'text' => trim((string) ($header['text'] ?? ''))];
        }

        return array_filter([
            'format' => $format,
            'media_url' => is_string($header['media_url'] ?? null) ? trim($header['media_url']) : null,
            'media_file_name' => $header['media_file_name'] ?? null,
            'media_file_size' => isset($header['media_file_size']) ? (int) $header['media_file_size'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array{text: string}|null
     */
    public static function normalizeFooter(mixed $footer): ?array
    {
        $text = is_array($footer) ? ($footer['text'] ?? null) : $footer;
        $text = is_string($text) ? trim($text) : '';

        return $text === '' ? null : ['text' => $text];
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    public static function normalizeButtons(mixed $buttons): ?array
    {
        if (! is_array($buttons)) {
            return null;
        }

        $normalized = [];

        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $type = strtoupper(trim((string) ($button['type'] ?? '')));

            $normalized[] = array_filter([
                'type' => $type,
                'text' => isset($button['text']) ? trim((string) $button['text']) : null,
                'url' => $type === 'URL' && isset($button['url']) ? trim((string) $button['url']) : null,
                'phone_number' => $type === 'PHONE_NUMBER'
                    ? trim((string) ($button['phone_number'] ?? $button['phone'] ?? ''))
                    : null,
                // Kept for templates imported from AyosPush (authentication).
                'otp_type' => $button['otp_type'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Placeholders of a text, in order of first appearance ("1", "2" or names).
     *
     * @return array<int, string>
     */
    public static function variablesIn(string $text): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Example values keyed by variable number, as strings.
     *
     * @return array<string, string>|null
     */
    private static function normalizeSamples(mixed $samples): ?array
    {
        if (! is_array($samples)) {
            return null;
        }

        $normalized = [];
        foreach ($samples as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[(string) $key] = trim((string) $value);
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /* --------------------------------------------------------------------- */
    /* Submission                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Everything AyosPush or Meta would refuse, keyed by field.
     *
     * @return array<string, array<int, string>>
     */
    public function problems(WhatsappTemplate $template, ?WhatsappSetting $settings): array
    {
        $errors = [];
        $add = function (string $field, string $message) use (&$errors): void {
            $errors[$field][] = $message;
        };

        if (! $settings) {
            $add('whatsapp_settings', 'WhatsApp is not configured for this application: save its AyosPush API key first.');

            return $errors;
        }

        if (! $settings->resolveWabaAccountId()) {
            $add('waba_account_id', 'Choose the WhatsApp Business Account templates are created on: test the AyosPush connection, then pick one.');
        }

        if (! in_array($template->language, self::LANGUAGES, true)) {
            $add('language', 'AyosPush only accepts the languages fr and en.');
        }

        if (! in_array($template->category, self::CATEGORIES, true)) {
            $add('category', 'The category must be MARKETING, UTILITY or AUTHENTICATION.');
        }

        if ($template->category === 'AUTHENTICATION') {
            $minutes = $template->metadata['code_expiration_minutes'] ?? null;

            if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > 90) {
                $add('code_expiration_minutes', 'Authentication templates need a code expiration between 1 and 90 minutes.');
            }

            // Meta writes the body and the copy-code button itself.
            return $errors;
        }

        $this->checkBody($template, $add);
        $this->checkHeader($template, $add);
        $this->checkFooter($template, $add);
        $this->checkButtons($template, $add);

        return $errors;
    }

    /**
     * The JSON AyosPush expects on POST /v1/templates.
     *
     * @return array<string, mixed>
     */
    public function payload(WhatsappTemplate $template, WhatsappSetting $settings): array
    {
        $payload = [
            'name' => $template->name,
            'display_name' => $template->display_name ?: $template->name,
            'language' => $template->language,
            'category' => $template->category,
            'waba_account_id' => $settings->resolveWabaAccountId(),
            'submit_for_approval' => true,
        ];

        if ($template->category === 'AUTHENTICATION') {
            $payload['code_expiration_minutes'] = (int) ($template->metadata['code_expiration_minutes'] ?? 10);
            $payload['add_security_recommendation'] = (bool) ($template->metadata['add_security_recommendation'] ?? false);

            return $payload;
        }

        // AyosPush validates `components` as a JSON *string*.
        $payload['components'] = json_encode(
            $this->components($template),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $examples = [];
        foreach (self::variablesIn((string) $template->body) as $variable) {
            $examples[$variable] = (string) ($template->sample_data[$variable] ?? '');
        }
        if ($examples !== []) {
            $payload['variable_examples'] = $examples;
        }

        return $payload;
    }

    /**
     * Create the template on AyosPush, which submits it to Meta.
     *
     * @throws ValidationException when the template cannot be accepted as is
     * @throws AyosPushException when AyosPush refuses it or cannot be reached
     */
    public function submit(WhatsappTemplate $template): WhatsappTemplate
    {
        $settings = $this->settingsFor($template);

        if ($problems = $this->problems($template, $settings)) {
            throw ValidationException::withMessages($problems);
        }

        $payload = $this->payload($template, $settings);

        try {
            $data = AyosPushClient::for($settings)->createTemplate($payload);
        } catch (AyosPushException $e) {
            $template->forceFill([
                'provider_error' => $e->getMessage(),
                'provider_synced_at' => now(),
            ])->save();

            throw $e;
        }

        if (empty($data['template_id']) || empty($data['name'])) {
            $message = 'AyosPush accepted the template but did not return its identifier.';
            $template->forceFill(['provider_error' => $message, 'provider_synced_at' => now()])->save();

            throw new AyosPushException($message, 502);
        }

        $remoteStatus = isset($data['facebook_status']) ? (string) $data['facebook_status'] : null;

        $template->forceFill([
            'provider' => WhatsappSetting::PROVIDER_AYOSPUSH,
            'provider_template_id' => (int) $data['template_id'],
            // AyosPush sends by this name, not ours.
            'provider_template_name' => (string) $data['name'],
            'facebook_template_id' => $data['facebook_template_id'] ?? null,
            'facebook_status' => $remoteStatus,
            'status' => $remoteStatus === null ? 'draft' : (self::mapStatus($remoteStatus) ?? 'pending'),
            'submitted_at' => now(),
            'approved_at' => null,
            'rejection_reason' => null,
            'provider_error' => $remoteStatus === null
                ? 'AyosPush saved the template without submitting it to Meta.'
                : null,
            'provider_synced_at' => now(),
            'components' => isset($payload['components']) ? json_decode($payload['components'], true) : null,
        ])->save();

        return $template->fresh('business');
    }

    /* --------------------------------------------------------------------- */
    /* Synchronisation                                                        */
    /* --------------------------------------------------------------------- */

    /**
     * Local status for an AyosPush (Meta) status. null when unknown, so the
     * local status is left alone.
     */
    public static function mapStatus(?string $remote): ?string
    {
        return match (strtoupper(trim((string) $remote))) {
            'APPROVED' => 'approved',
            'REJECTED' => 'rejected',
            'PENDING', 'IN_APPEAL' => 'pending',
            'PAUSED', 'DISABLED', 'DELETED', 'PENDING_DELETION', 'ARCHIVED', 'LIMIT_EXCEEDED' => 'disabled',
            default => null,
        };
    }

    /**
     * Pull the current status of one submitted template.
     */
    public function refresh(WhatsappTemplate $template): WhatsappTemplate
    {
        $settings = $this->settingsFor($template);

        if (! $settings) {
            throw ValidationException::withMessages([
                'whatsapp_settings' => ['WhatsApp is not configured for this application.'],
            ]);
        }

        if (! $template->isSubmitted()) {
            throw ValidationException::withMessages([
                'status' => ['This template has not been submitted to AyosPush yet.'],
            ]);
        }

        try {
            $remote = AyosPushClient::for($settings)->template((int) $template->provider_template_id);
        } catch (AyosPushException $e) {
            $template->forceFill([
                'provider_error' => $e->status === 404
                    ? 'AyosPush no longer lists this template (deleted there, or outside the API key scope).'
                    : $e->getMessage(),
                'provider_synced_at' => now(),
            ])->save();

            if ($e->status !== 404) {
                throw $e;
            }

            return $template->fresh('business');
        }

        $this->applyRemote($template, $remote);

        return $template->fresh('business');
    }

    /**
     * Refresh every submitted template of an application and, with $import,
     * copy the templates that only exist on AyosPush.
     *
     * @return array{updated: int, unchanged: int, imported: int, missing: int, ignored: int}
     */
    public function syncBusiness(Business $business, bool $import = true): array
    {
        $settings = $business->whatsappSetting;

        if (! $settings) {
            throw ValidationException::withMessages([
                'whatsapp_settings' => ['WhatsApp is not configured for this application.'],
            ]);
        }

        $client = AyosPushClient::for($settings);
        $remoteTemplates = $client->allTemplates();

        // Soft-deleted ones included: a template deleted here must not come
        // back at the next import.
        $locals = WhatsappTemplate::withTrashed()
            ->where('business_id', $business->id)
            ->whereNotNull('provider_template_id')
            ->get();
        $byId = $locals->keyBy('provider_template_id');
        $byName = $locals->filter(fn (WhatsappTemplate $t) => $t->provider_template_name !== null)
            ->keyBy('provider_template_name');

        $counts = ['updated' => 0, 'unchanged' => 0, 'imported' => 0, 'missing' => 0, 'ignored' => 0];
        $seen = [];

        foreach ($remoteTemplates as $remote) {
            $remoteId = isset($remote['id']) ? (int) $remote['id'] : 0;
            if ($remoteId === 0) {
                continue;
            }

            $local = $byId->get($remoteId) ?? (isset($remote['name']) ? $byName->get($remote['name']) : null);

            if ($local) {
                $seen[$local->id] = true;

                if ($local->trashed()) {
                    $counts['ignored']++;

                    continue;
                }

                $this->applyRemote($local, $remote) ? $counts['updated']++ : $counts['unchanged']++;

                continue;
            }

            if ($import) {
                $this->importRemote($business, $client, $remote);
                $counts['imported']++;
            } else {
                $counts['ignored']++;
            }
        }

        foreach ($locals as $local) {
            if ($local->trashed() || isset($seen[$local->id])) {
                continue;
            }

            $local->forceFill([
                'provider_error' => 'AyosPush no longer lists this template (deleted there, or outside the API key scope).',
                'provider_synced_at' => now(),
            ])->save();
            $counts['missing']++;
        }

        $settings->forceFill(['templates_synced_at' => now()])->save();

        return $counts;
    }

    /**
     * Copy what AyosPush says about a template. True when its status changed.
     *
     * @param  array<string, mixed>  $remote
     */
    public function applyRemote(WhatsappTemplate $template, array $remote): bool
    {
        $remoteStatus = isset($remote['status']) ? (string) $remote['status'] : null;
        $mapped = self::mapStatus($remoteStatus);

        $attributes = [
            'provider' => WhatsappSetting::PROVIDER_AYOSPUSH,
            'facebook_status' => $remoteStatus,
            'facebook_template_id' => $remote['facebook_template_id'] ?? $template->facebook_template_id,
            'provider_template_name' => $remote['name'] ?? $template->provider_template_name,
            'provider_error' => null,
            'provider_synced_at' => now(),
        ];

        if (isset($remote['id'])) {
            $attributes['provider_template_id'] = (int) $remote['id'];
        }

        if ($mapped !== null) {
            $attributes['status'] = $mapped;

            if ($mapped === 'approved' && ! $template->approved_at) {
                $attributes['approved_at'] = now();
            }

            $attributes['rejection_reason'] = $mapped === 'rejected'
                ? ($template->rejection_reason ?: 'Rejected by Meta. The reason is shown in the AyosPush dashboard.')
                : null;
        }

        $template->forceFill($attributes);
        $changed = $template->isDirty(['status', 'facebook_status', 'facebook_template_id']);
        $template->save();

        return $changed;
    }

    /**
     * POST /v1/templates/upload-media on behalf of the application.
     *
     * @return array<string, mixed>
     */
    public function uploadMedia(Business $business, UploadedFile $file, string $type): array
    {
        $settings = $business->whatsappSetting;

        if (! $settings) {
            throw ValidationException::withMessages([
                'whatsapp_settings' => ['WhatsApp is not configured for this application.'],
            ]);
        }

        $data = AyosPushClient::for($settings)->uploadMedia($file, $type);

        if (empty($data['media_url'])) {
            throw new AyosPushException('AyosPush stored the file but did not return its URL.', 502);
        }

        return $data;
    }

    /* --------------------------------------------------------------------- */
    /* Internals                                                              */
    /* --------------------------------------------------------------------- */

    private function settingsFor(WhatsappTemplate $template): ?WhatsappSetting
    {
        return WhatsappSetting::where('business_id', $template->business_id)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function components(WhatsappTemplate $template): array
    {
        $components = [];

        if ($header = self::normalizeHeader($template->header)) {
            $component = ['type' => 'HEADER', 'format' => $header['format']];

            if ($header['format'] === 'TEXT') {
                $component['text'] = $header['text'];
            } else {
                // AyosPush downloads it and uploads it to Meta as the example.
                $component['media_url'] = $header['media_url'] ?? null;
                if (isset($header['media_file_size'])) {
                    $component['media_file_size'] = (int) $header['media_file_size'];
                }
            }

            $components[] = $component;
        }

        $components[] = ['type' => 'BODY', 'text' => (string) $template->body];

        if ($footer = self::normalizeFooter($template->footer)) {
            $components[] = ['type' => 'FOOTER', 'text' => $footer['text']];
        }

        $buttons = self::normalizeButtons($template->buttons) ?? [];
        if ($buttons !== []) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(fn (array $button) => array_filter([
                    'type' => $button['type'],
                    'text' => $button['text'] ?? '',
                    'url' => $button['url'] ?? null,
                    'phone_number' => isset($button['phone_number'])
                        ? preg_replace('/[\s().-]/', '', $button['phone_number'])
                        : null,
                ], fn ($value) => $value !== null), $buttons),
            ];
        }

        return $components;
    }

    private function checkBody(WhatsappTemplate $template, callable $add): void
    {
        $body = (string) $template->body;

        if (trim($body) === '') {
            $add('body', 'The body is required.');

            return;
        }

        if (mb_strlen($body) > 1024) {
            $add('body', 'The body is limited to 1024 characters.');
        }

        $variables = self::variablesIn($body);
        $named = array_values(array_filter($variables, fn (string $v) => ! ctype_digit($v)));

        if ($named !== []) {
            $add('body', 'Use numbered variables ({{1}}, {{2}}…): AyosPush does not support named variables ('
                . implode(', ', array_map(fn ($v) => '{{' . $v . '}}', $named)) . ').');

            return;
        }

        $numbers = array_map('intval', $variables);
        sort($numbers);

        if ($numbers !== [] && $numbers !== range(1, count($numbers))) {
            $add('body', 'Variables must be numbered from {{1}} without gaps.');
        }

        $missing = array_filter($numbers, fn (int $n) => trim((string) ($template->sample_data[$n] ?? '')) === '');

        if ($missing !== []) {
            $add('sample_data', 'Give an example value for '
                . implode(', ', array_map(fn ($n) => '{{' . $n . '}}', $missing))
                . ': Meta reviews the template with these examples.');
        }
    }

    private function checkHeader(WhatsappTemplate $template, callable $add): void
    {
        $header = self::normalizeHeader($template->header);

        if (! $header) {
            return;
        }

        if (! in_array($header['format'], self::HEADER_FORMATS, true)) {
            $add('header', 'The header format must be TEXT, IMAGE, VIDEO or DOCUMENT.');

            return;
        }

        if ($header['format'] === 'TEXT') {
            $text = $header['text'];

            if ($text === '') {
                $add('header', 'The header text is empty.');
            } elseif (mb_strlen($text) > 60) {
                $add('header', 'The header text is limited to 60 characters.');
            }

            if (str_contains($text, '{{')) {
                $add('header', 'Variables are not supported in the header: AyosPush only fills body variables when sending.');
            }

            return;
        }

        $url = $header['media_url'] ?? '';
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            $add('header', 'Upload the ' . strtolower($header['format']) . ' of the header first: AyosPush needs its URL.');
        }
    }

    private function checkFooter(WhatsappTemplate $template, callable $add): void
    {
        $footer = self::normalizeFooter($template->footer);

        if (! $footer) {
            return;
        }

        if (mb_strlen($footer['text']) > 60) {
            $add('footer', 'The footer is limited to 60 characters.');
        }

        if (str_contains($footer['text'], '{{')) {
            $add('footer', 'Variables are not allowed in the footer.');
        }
    }

    private function checkButtons(WhatsappTemplate $template, callable $add): void
    {
        $buttons = self::normalizeButtons($template->buttons) ?? [];

        if (count($buttons) > 10) {
            $add('buttons', 'At most 10 buttons.');
        }

        $urls = 0;
        $phones = 0;

        foreach ($buttons as $index => $button) {
            $label = 'Button ' . ($index + 1);
            $type = $button['type'] ?? '';

            if (! in_array($type, self::BUTTON_TYPES, true)) {
                $add('buttons', "{$label}: the type must be QUICK_REPLY, URL or PHONE_NUMBER.");

                continue;
            }

            $text = $button['text'] ?? '';
            if ($text === '') {
                $add('buttons', "{$label}: the text is required.");
            } elseif (mb_strlen($text) > 25) {
                $add('buttons', "{$label}: the text is limited to 25 characters.");
            }

            if ($type === 'URL') {
                $urls++;
                $url = $button['url'] ?? '';

                if (str_contains($url, '{{')) {
                    $add('buttons', "{$label}: dynamic URLs are not supported through AyosPush, which does not forward the example Meta requires.");
                } elseif (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
                    $add('buttons', "{$label}: enter a valid http(s) URL.");
                }
            }

            if ($type === 'PHONE_NUMBER') {
                $phones++;
                $phone = preg_replace('/[\s().-]/', '', $button['phone_number'] ?? '');

                if (! preg_match('/^\+[1-9][0-9]{6,18}$/', $phone)) {
                    $add('buttons', "{$label}: enter the phone number in international format (e.g. +24177000000).");
                }
            }
        }

        if ($urls > 2) {
            $add('buttons', 'At most 2 URL buttons.');
        }

        if ($phones > 1) {
            $add('buttons', 'At most 1 phone number button.');
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function importRemote(Business $business, AyosPushClient $client, array $remote): WhatsappTemplate
    {
        $parts = self::partsFromComponents(is_array($remote['components'] ?? null) ? $remote['components'] : []);
        $header = $parts['header'];

        // The listing only has a 100-character preview of the body, and Meta's
        // components carry a media header as an upload handle rather than the
        // file AyosPush keeps: the detail endpoint fills both in.
        $mediaWithoutFile = $header && $header['format'] !== 'TEXT' && empty($header['media_url']);

        if ($parts['body'] === null || $mediaWithoutFile) {
            $detail = self::partsFromDetail($client->template((int) $remote['id']));

            foreach ($detail as $key => $value) {
                $missing = $parts[$key] === null
                    || ($key === 'header' && $mediaWithoutFile && ! empty($value['media_url']));

                if ($missing && $value !== null) {
                    $parts[$key] = $value;
                }
            }
        }

        $remoteName = (string) ($remote['name'] ?? 'ayospush_template');
        $language = mb_substr((string) ($remote['language'] ?? 'fr'), 0, 10);
        $status = self::mapStatus($remote['status'] ?? null) ?? 'pending';
        $body = (string) ($parts['body'] ?? '');

        return WhatsappTemplate::create([
            'business_id' => $business->id,
            'name' => $this->uniqueName($business, $remoteName, $language),
            'display_name' => mb_substr((string) (($remote['display_name'] ?? null) ?: $remoteName), 0, 255),
            'description' => 'Imported from AyosPush.',
            'language' => $language,
            'category' => strtoupper((string) ($remote['category'] ?? 'UTILITY')),
            'header' => $parts['header'],
            'body' => $body,
            'footer' => $parts['footer'],
            'buttons' => $parts['buttons'],
            'components' => $remote['components'] ?? null,
            'variables' => self::variablesIn($body),
            'sample_data' => $parts['sample_data'],
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'is_active' => true,
            'facebook_status' => $remote['status'] ?? null,
            'facebook_template_id' => $remote['facebook_template_id'] ?? null,
            'provider' => WhatsappSetting::PROVIDER_AYOSPUSH,
            'provider_template_id' => (int) $remote['id'],
            'provider_template_name' => $remoteName,
            'provider_synced_at' => now(),
            'metadata' => ['imported_from' => 'ayospush', 'imported_at' => now()->toIso8601String()],
        ]);
    }

    /**
     * Header, body, footer, buttons and examples out of Meta components.
     *
     * @param  array<int, mixed>  $components
     * @return array{header: array<string, mixed>|null, body: string|null, footer: array{text: string}|null, buttons: array<int, array<string, string>>|null, sample_data: array<string, string>|null}
     */
    public static function partsFromComponents(array $components): array
    {
        $parts = ['header' => null, 'body' => null, 'footer' => null, 'buttons' => null, 'sample_data' => null];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            switch (strtoupper((string) ($component['type'] ?? ''))) {
                case 'HEADER':
                    $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
                    $handle = $component['example']['header_handle'][0] ?? null;
                    $parts['header'] = self::normalizeHeader([
                        'format' => $format,
                        'text' => $component['text'] ?? null,
                        'media_url' => $component['media_url']
                            ?? (is_string($handle) && preg_match('#^https?://#i', $handle) ? $handle : null),
                    ]);
                    break;

                case 'BODY':
                    if (isset($component['text']) && is_string($component['text'])) {
                        $parts['body'] = $component['text'];
                    }

                    $examples = $component['example']['body_text'][0] ?? null;
                    if (is_array($examples)) {
                        $samples = [];
                        foreach (array_values($examples) as $i => $value) {
                            $samples[(string) ($i + 1)] = (string) $value;
                        }
                        $parts['sample_data'] = self::normalizeSamples($samples);
                    }
                    break;

                case 'FOOTER':
                    $parts['footer'] = self::normalizeFooter($component['text'] ?? null);
                    break;

                case 'BUTTONS':
                    $parts['buttons'] = self::normalizeButtons($component['buttons'] ?? []);
                    break;
            }
        }

        return $parts;
    }

    /**
     * Same, out of GET /v1/templates/{id}.
     *
     * @param  array<string, mixed>  $detail
     * @return array{header: array<string, mixed>|null, body: string|null, footer: array{text: string}|null, buttons: array<int, array<string, string>>|null, sample_data: array<string, string>|null}
     */
    public static function partsFromDetail(array $detail): array
    {
        return [
            'header' => self::normalizeHeader($detail['header'] ?? null),
            'body' => is_string($detail['body'] ?? null) ? $detail['body'] : null,
            'footer' => self::normalizeFooter($detail['footer'] ?? null),
            'buttons' => self::normalizeButtons($detail['buttons'] ?? null),
            'sample_data' => self::normalizeSamples($detail['sample_data'] ?? null),
        ];
    }

    /**
     * Local names are unique per application and language (soft-deleted rows
     * included, the unique index covers them).
     */
    private function uniqueName(Business $business, string $name, string $language): string
    {
        $base = mb_substr($name, 0, 240);
        $candidate = $base;
        $suffix = 2;

        while (WhatsappTemplate::withTrashed()
            ->where('business_id', $business->id)
            ->where('name', $candidate)
            ->where('language', $language)
            ->exists()) {
            $candidate = "{$base}_{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
