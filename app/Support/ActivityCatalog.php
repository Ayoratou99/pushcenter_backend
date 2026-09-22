<?php

namespace App\Support;

use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\SmsTemplate;
use App\Models\SmtpSetting;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Models\Template;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;

/**
 * Console routes that change something, and how each one reads in the
 * activity log.
 *
 * subject:  where the subject id comes from: `param:<name>` (route parameter),
 *           `response` (data.id of the answer, for creations) or `actor`.
 * business: the application concerned: `self` (the subject is one), `subject`
 *           (its business_id), `param:<name>` or `input:<name>`.
 */
class ActivityCatalog
{
    /**
     * @var array<string, array{0: string, 1: string, 2?: class-string|null, 3?: string|null, 4?: string|null}>
     */
    private const ROUTES = [
        // Account (the signed-in user's own)
        'POST v1/auth/logout' => ['account.logout', 'Signed out', User::class, 'actor'],
        'PUT v1/auth/profile' => ['account.profile_updated', 'Updated their profile', User::class, 'actor'],
        'PUT v1/auth/password' => ['account.password_changed', 'Changed their password', User::class, 'actor'],
        'POST v1/auth/two-factor/disable' => ['account.two_factor_disabled', 'Turned Google Authenticator off', User::class, 'actor'],
        'POST v1/auth/two-factor/recovery-codes' => ['account.recovery_codes_regenerated', 'Generated new recovery codes', User::class, 'actor'],

        // Users
        'POST v1/users' => ['user.created', 'Created user {subject}', User::class, 'response'],
        'PUT v1/users/{user}' => ['user.updated', 'Updated user {subject}', User::class, 'param:user'],
        'PATCH v1/users/{user}' => ['user.updated', 'Updated user {subject}', User::class, 'param:user'],
        'DELETE v1/users/{user}' => ['user.deleted', 'Deleted user {subject}', User::class, 'param:user'],
        'PUT v1/users/{id}/businesses' => ['user.applications_assigned', 'Changed the applications of {subject}', User::class, 'param:id'],
        'POST v1/users/{id}/reset-two-factor' => ['user.two_factor_reset', 'Reset Google Authenticator for {subject}', User::class, 'param:id'],

        // Applications
        'POST v1/businesses' => ['business.created', 'Created application {subject}', Business::class, 'response', 'self'],
        'PUT v1/businesses/{business}' => ['business.updated', 'Updated application {subject}', Business::class, 'param:business', 'self'],
        'PATCH v1/businesses/{business}' => ['business.updated', 'Updated application {subject}', Business::class, 'param:business', 'self'],
        'DELETE v1/businesses/{business}' => ['business.deleted', 'Deleted application {subject}', Business::class, 'param:business', 'self'],
        'POST v1/businesses/{id}/regenerate-credentials' => ['business.credentials_regenerated', 'Regenerated the API credentials of {subject}', Business::class, 'param:id', 'self'],

        // Email settings
        'POST v1/businesses/{businessId}/smtp-settings' => ['smtp_setting.created', 'Added SMTP settings {subject}', SmtpSetting::class, 'response', 'param:businessId'],
        'PUT v1/businesses/{businessId}/smtp-settings/{id}' => ['smtp_setting.updated', 'Updated SMTP settings {subject}', SmtpSetting::class, 'param:id', 'param:businessId'],
        'DELETE v1/businesses/{businessId}/smtp-settings/{id}' => ['smtp_setting.deleted', 'Deleted SMTP settings {subject}', SmtpSetting::class, 'param:id', 'param:businessId'],
        'POST v1/businesses/{businessId}/smtp-settings/{id}/test' => ['smtp_setting.tested', 'Sent a test email with SMTP settings {subject}', SmtpSetting::class, 'param:id', 'param:businessId'],

        // WhatsApp (AyosPush)
        'PUT v1/businesses/{businessId}/whatsapp-settings' => ['whatsapp_settings.updated', 'Saved the AyosPush API key', WhatsappSetting::class, null, 'param:businessId'],
        'DELETE v1/businesses/{businessId}/whatsapp-settings' => ['whatsapp_settings.deleted', 'Removed the AyosPush configuration', WhatsappSetting::class, null, 'param:businessId'],
        'POST v1/businesses/{businessId}/whatsapp-settings/test' => ['whatsapp_settings.tested', 'Tested the AyosPush connection', WhatsappSetting::class, null, 'param:businessId'],
        'POST v1/businesses/{businessId}/whatsapp-templates/sync' => ['whatsapp_template.synced_all', 'Synchronised the WhatsApp templates with AyosPush', null, null, 'param:businessId'],
        'POST v1/whatsapp-templates/media' => ['whatsapp_template.media_uploaded', 'Uploaded a WhatsApp header media to AyosPush', null, null, 'input:business_id'],
        'POST v1/whatsapp-templates/{id}/submit' => ['whatsapp_template.submitted', 'Submitted WhatsApp template {subject} to Meta through AyosPush', WhatsappTemplate::class, 'param:id', 'subject'],
        'POST v1/whatsapp-templates/{id}/sync' => ['whatsapp_template.synced', 'Refreshed the approval status of WhatsApp template {subject}', WhatsappTemplate::class, 'param:id', 'subject'],

        // Telegram
        'PUT v1/businesses/{businessId}/telegram-settings' => ['telegram_settings.updated', 'Saved the Telegram bot', TelegramSetting::class, null, 'param:businessId'],
        'DELETE v1/businesses/{businessId}/telegram-settings' => ['telegram_settings.deleted', 'Removed the Telegram bot', TelegramSetting::class, null, 'param:businessId'],
        'POST v1/businesses/{businessId}/telegram-settings/test' => ['telegram_settings.tested', 'Tested the Telegram bot', TelegramSetting::class, null, 'param:businessId'],
        'POST v1/businesses/{businessId}/telegram-settings/poll' => ['telegram_settings.polled', 'Read the new Telegram subscriptions', TelegramSetting::class, null, 'param:businessId'],
        'DELETE v1/businesses/{businessId}/telegram-subscribers/{id}' => ['telegram_subscriber.deleted', 'Forgot Telegram subscriber {subject}', TelegramSubscriber::class, 'param:id', 'param:businessId'],
        'POST v1/businesses/{businessId}/telegram-invitations' => ['telegram_invitation.created', 'Created a Telegram invitation link', null, null, 'param:businessId'],
        'POST v1/telegram-templates' => ['telegram_template.created', 'Created Telegram template {subject}', TelegramTemplate::class, 'response', 'subject'],
        'PUT v1/telegram-templates/{telegram_template}' => ['telegram_template.updated', 'Updated Telegram template {subject}', TelegramTemplate::class, 'param:telegram_template', 'subject'],
        'PATCH v1/telegram-templates/{telegram_template}' => ['telegram_template.updated', 'Updated Telegram template {subject}', TelegramTemplate::class, 'param:telegram_template', 'subject'],
        'DELETE v1/telegram-templates/{telegram_template}' => ['telegram_template.deleted', 'Deleted Telegram template {subject}', TelegramTemplate::class, 'param:telegram_template', 'subject'],
        'POST v1/telegram-templates/{id}/media' => ['telegram_template.media_attached', 'Attached a file to Telegram template {subject}', TelegramTemplate::class, 'param:id', 'subject'],
        'DELETE v1/telegram-templates/{id}/media' => ['telegram_template.media_removed', 'Removed the attachment of Telegram template {subject}', TelegramTemplate::class, 'param:id', 'subject'],
        'POST v1/telegram-templates/{id}/activate' => ['telegram_template.activated', 'Activated Telegram template {subject}', TelegramTemplate::class, 'param:id', 'subject'],
        'POST v1/telegram-templates/{id}/deactivate' => ['telegram_template.deactivated', 'Deactivated Telegram template {subject}', TelegramTemplate::class, 'param:id', 'subject'],

        // Templates
        'POST v1/email-templates' => ['email_template.created', 'Created email template {subject}', EmailTemplate::class, 'response', 'subject'],
        'PUT v1/email-templates/{email_template}' => ['email_template.updated', 'Updated email template {subject}', EmailTemplate::class, 'param:email_template', 'subject'],
        'PATCH v1/email-templates/{email_template}' => ['email_template.updated', 'Updated email template {subject}', EmailTemplate::class, 'param:email_template', 'subject'],
        'DELETE v1/email-templates/{email_template}' => ['email_template.deleted', 'Deleted email template {subject}', EmailTemplate::class, 'param:email_template', 'subject'],
        'POST v1/email-templates/{id}/activate' => ['email_template.activated', 'Activated email template {subject}', EmailTemplate::class, 'param:id', 'subject'],
        'POST v1/email-templates/{id}/deactivate' => ['email_template.deactivated', 'Deactivated email template {subject}', EmailTemplate::class, 'param:id', 'subject'],

        'POST v1/sms-templates' => ['sms_template.created', 'Created SMS template {subject}', SmsTemplate::class, 'response', 'subject'],
        'PUT v1/sms-templates/{sms_template}' => ['sms_template.updated', 'Updated SMS template {subject}', SmsTemplate::class, 'param:sms_template', 'subject'],
        'PATCH v1/sms-templates/{sms_template}' => ['sms_template.updated', 'Updated SMS template {subject}', SmsTemplate::class, 'param:sms_template', 'subject'],
        'DELETE v1/sms-templates/{sms_template}' => ['sms_template.deleted', 'Deleted SMS template {subject}', SmsTemplate::class, 'param:sms_template', 'subject'],
        'POST v1/sms-templates/{id}/activate' => ['sms_template.activated', 'Activated SMS template {subject}', SmsTemplate::class, 'param:id', 'subject'],
        'POST v1/sms-templates/{id}/deactivate' => ['sms_template.deactivated', 'Deactivated SMS template {subject}', SmsTemplate::class, 'param:id', 'subject'],

        'POST v1/whatsapp-templates' => ['whatsapp_template.created', 'Created WhatsApp template {subject}', WhatsappTemplate::class, 'response', 'subject'],
        'PUT v1/whatsapp-templates/{whatsapp_template}' => ['whatsapp_template.updated', 'Updated WhatsApp template {subject}', WhatsappTemplate::class, 'param:whatsapp_template', 'subject'],
        'PATCH v1/whatsapp-templates/{whatsapp_template}' => ['whatsapp_template.updated', 'Updated WhatsApp template {subject}', WhatsappTemplate::class, 'param:whatsapp_template', 'subject'],
        'DELETE v1/whatsapp-templates/{whatsapp_template}' => ['whatsapp_template.deleted', 'Deleted WhatsApp template {subject}', WhatsappTemplate::class, 'param:whatsapp_template', 'subject'],
        'POST v1/whatsapp-templates/{id}/activate' => ['whatsapp_template.activated', 'Activated WhatsApp template {subject}', WhatsappTemplate::class, 'param:id', 'subject'],
        'POST v1/whatsapp-templates/{id}/deactivate' => ['whatsapp_template.deactivated', 'Deactivated WhatsApp template {subject}', WhatsappTemplate::class, 'param:id', 'subject'],

        'POST v1/templates' => ['template.created', 'Created template {subject}', Template::class, 'response', 'subject'],
        'PUT v1/templates/{template}' => ['template.updated', 'Updated template {subject}', Template::class, 'param:template', 'subject'],
        'PATCH v1/templates/{template}' => ['template.updated', 'Updated template {subject}', Template::class, 'param:template', 'subject'],
        'DELETE v1/templates/{template}' => ['template.deleted', 'Deleted template {subject}', Template::class, 'param:template', 'subject'],
        'POST v1/templates/{id}/activate' => ['template.activated', 'Activated template {subject}', Template::class, 'param:id', 'subject'],
        'POST v1/templates/{id}/deactivate' => ['template.deactivated', 'Deactivated template {subject}', Template::class, 'param:id', 'subject'],

        // Template transfer: data leaving is worth knowing about too.
        'GET v1/templates/{type}/{id}/export' => ['template.exported', 'Exported {type} template {subject}', 'param:type', 'param:id', 'subject'],
        'POST v1/templates/export' => ['template.exported', 'Exported {type} templates', null, null, null],
        'POST v1/templates/import' => ['template.imported', 'Imported a template', null, null, 'input:business_id'],
        'POST v1/templates/{type}/{id}/duplicate' => ['template.duplicated', 'Duplicated {type} template {subject}', 'param:type', 'param:id', 'subject'],

        // Messages
        'POST v1/messages/{id}/retry' => ['message.retried', 'Sent message {subject} again', Message::class, 'param:id', 'subject'],
        'POST v1/messages/{id}/cancel' => ['message.cancelled', 'Cancelled message {subject}', Message::class, 'param:id', 'subject'],
        'DELETE v1/messages/{message}' => ['message.deleted', 'Deleted message {subject}', Message::class, 'param:message', 'subject'],
    ];

    /**
     * Template types of the export route and their models.
     */
    public const TEMPLATE_TYPES = [
        'email' => EmailTemplate::class,
        'sms' => SmsTemplate::class,
        'whatsapp' => WhatsappTemplate::class,
        'telegram' => TelegramTemplate::class,
    ];

    /**
     * Actions that are recorded outside the routes above.
     */
    public const OTHER_ACTIONS = [
        'account.login' => 'Signed in',
        'account.login_failed' => 'Failed sign-in',
        'account.two_factor_enabled' => 'Turned Google Authenticator on',
    ];

    /**
     * @return array{action: string, description: string, subject_model: string|null, subject: string|null, business: string|null}|null
     */
    public static function for(Request $request): ?array
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        $key = $request->method() . ' ' . preg_replace('#^api/#', '', $route->uri());
        $definition = self::ROUTES[$key] ?? null;

        if (! $definition) {
            return null;
        }

        return [
            'action' => $definition[0],
            'description' => $definition[1],
            'subject_model' => $definition[2] ?? null,
            'subject' => $definition[3] ?? null,
            'business' => $definition[4] ?? null,
            'route' => $key,
        ];
    }

    /**
     * Every action, for the console's filter.
     *
     * @return array<int, string>
     */
    public static function actions(): array
    {
        return collect(self::ROUTES)
            ->pluck(0)
            ->merge(array_keys(self::OTHER_ACTIONS))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Readable name of a subject.
     */
    public static function label(?object $model): ?string
    {
        if (! $model) {
            return null;
        }

        return match (true) {
            $model instanceof User => $model->name ? "{$model->name} ({$model->email})" : $model->email,
            $model instanceof Message => '#' . $model->getKey(),
            $model instanceof WhatsappSetting, $model instanceof TelegramSetting => null,
            $model instanceof TelegramSubscriber => $model->displayName(),
            default => $model->display_name ?? $model->name ?? ('#' . $model->getKey()),
        };
    }
}
