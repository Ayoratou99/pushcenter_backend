<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use App\Repositories\Contracts\MessageRepositoryInterface;
use App\Repositories\Contracts\EmailTemplateRepositoryInterface;
use App\Repositories\Contracts\SmsTemplateRepositoryInterface;
use App\Repositories\Contracts\WhatsappTemplateRepositoryInterface;
use App\Repositories\BusinessRepository;
use App\Repositories\TemplateRepository;
use App\Repositories\MessageRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\SmsTemplateRepository;
use App\Repositories\WhatsappTemplateRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(BusinessRepositoryInterface::class, BusinessRepository::class);
        $this->app->bind(TemplateRepositoryInterface::class, TemplateRepository::class);
        $this->app->bind(MessageRepositoryInterface::class, MessageRepository::class);
        $this->app->bind(EmailTemplateRepositoryInterface::class, EmailTemplateRepository::class);
        $this->app->bind(SmsTemplateRepositoryInterface::class, SmsTemplateRepository::class);
        $this->app->bind(WhatsappTemplateRepositoryInterface::class, WhatsappTemplateRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
