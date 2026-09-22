<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\User;
use App\Support\ActivityCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the activity log. Recording must never break the action itself:
 * every failure is only logged.
 */
class ActivityRecorder
{
    /**
     * Input keys whose value never reaches the log.
     */
    private const SECRET = '/pass|secret|token|code|recovery|credential|api_key/i';

    /**
     * Bulky content replaced by a marker.
     */
    private const BULKY = ['design', 'html', 'plain_text', 'components', 'file', 'content'];

    /**
     * @param  array<string, mixed>  $attributes  business, subject, subject_label, properties
     */
    public function record(?User $user, string $action, string $description, array $attributes = [], ?Request $request = null): ?ActivityLog
    {
        try {
            $business = $attributes['business'] ?? null;
            if ($business !== null && ! $business instanceof Business) {
                $business = Business::withTrashed()->find($business);
            }

            $subject = $attributes['subject'] ?? null;
            $request ??= request();

            return ActivityLog::create([
                'user_id' => $user?->getKey(),
                'user_name' => $user?->name,
                'user_email' => $user?->email ?? ($attributes['user_email'] ?? null),
                'business_id' => $business?->getKey(),
                'business_name' => $business?->name,
                'action' => $action,
                'subject_type' => $attributes['subject_type'] ?? ($subject ? Str::snake(class_basename($subject)) : null),
                'subject_id' => $attributes['subject_id'] ?? $subject?->getKey(),
                'subject_label' => Str::limit((string) ($attributes['subject_label'] ?? ActivityCatalog::label($subject) ?? ''), 250, '…') ?: null,
                'description' => Str::limit($description, 480, '…'),
                'properties' => $attributes['properties'] ?? null,
                'method' => $request?->method(),
                'route' => $attributes['route'] ?? null,
                'ip_address' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity not recorded', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * What has to be read before the controller runs: the subject of an
     * update or a deletion, as it was.
     *
     * @param  array<string, mixed>  $definition
     * @return array{subject: Model|null}
     */
    public function before(Request $request, array $definition): array
    {
        $source = $definition['subject'] ?? null;

        if (! $source || $source === 'response') {
            return ['subject' => null];
        }

        try {
            return ['subject' => $this->loadSubject($request, $definition, $this->idFrom($source, $request, null))];
        } catch (\Throwable) {
            return ['subject' => null];
        }
    }

    /**
     * Log a console request once it succeeded.
     *
     * @param  array<string, mixed>  $definition
     * @param  array{subject: Model|null}  $before
     */
    public function recordRequest(Request $request, Response $response, array $definition, array $before): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $data = $this->responseData($response);
        $subject = $before['subject'];

        if (! $subject && ($source = $definition['subject'] ?? null)) {
            $subject = $this->loadSubject($request, $definition, $this->idFrom($source, $request, $data));
        }

        $business = match (true) {
            ($definition['business'] ?? null) === 'self' => $subject,
            ($definition['business'] ?? null) === 'subject' => $subject?->business_id,
            str_starts_with((string) ($definition['business'] ?? ''), 'param:') => $request->route(Str::after($definition['business'], 'param:')),
            str_starts_with((string) ($definition['business'] ?? ''), 'input:') => $request->input(Str::after($definition['business'], 'input:')),
            default => null,
        };

        $label = ActivityCatalog::label($subject);
        $description = strtr($definition['description'], [
            '{subject}' => $label ?? ($subject ? '#' . $subject->getKey() : ''),
            '{type}' => (string) ($request->route('type') ?? $request->input('type') ?? $data['type'] ?? ''),
        ]);

        $this->record($user, $definition['action'], trim(preg_replace('/\s+/', ' ', $description)), [
            'business' => $business,
            'subject' => $subject,
            'subject_type' => $subject ? null : $this->subjectType($definition, $request),
            'subject_label' => $label,
            'route' => $definition['route'] ?? null,
            'properties' => array_filter([
                'status' => $response->getStatusCode(),
                'input' => $request->isMethod('GET') ? null : $this->sanitize($request->except(['_token'])),
            ], fn ($value) => $value !== null && $value !== []),
        ], $request);
    }

    /**
     * Request input without secrets nor bulky content.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public function sanitize(array $input, int $depth = 0): array
    {
        $clean = [];

        foreach (array_slice($input, 0, 50, true) as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key)) {
                $clean[$key] = '***';
            } elseif (is_string($key) && in_array($key, self::BULKY, true)) {
                $clean[$key] = '[omitted]';
            } elseif (is_array($value)) {
                $clean[$key] = $depth >= 2 ? '[…]' : $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, 200, '…');
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[' . get_debug_type($value) . ']';
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function loadSubject(Request $request, array $definition, mixed $id): ?Model
    {
        $class = $this->subjectClass($definition, $request);

        if (! $class || ! $id || ! is_numeric($id)) {
            return null;
        }

        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find((int) $id);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return class-string<Model>|null
     */
    private function subjectClass(array $definition, Request $request): ?string
    {
        $model = $definition['subject_model'] ?? null;

        if (is_string($model) && str_starts_with($model, 'param:')) {
            return ActivityCatalog::TEMPLATE_TYPES[$request->route(Str::after($model, 'param:'))] ?? null;
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function subjectType(array $definition, Request $request): ?string
    {
        $class = $this->subjectClass($definition, $request);

        return $class ? Str::snake(class_basename($class)) : null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function idFrom(string $source, Request $request, ?array $data): mixed
    {
        return match (true) {
            $source === 'actor' => $request->user()?->getKey(),
            $source === 'response' => $data['id'] ?? $data['business']['id'] ?? $data['user']['id'] ?? null,
            str_starts_with($source, 'param:') => $request->route(Str::after($source, 'param:')),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
