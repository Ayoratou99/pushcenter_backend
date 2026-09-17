# AninfPush - Multi-Channel Messaging Microservice

AninfPush is a Laravel 12 LTS API-only microservice designed for production-ready multi-channel messaging (Email, SMS, WhatsApp) with internal JWT authentication, mandatory Google Authenticator, Laravel Horizon for queue management, and comprehensive Swagger/OpenAPI documentation.

## 🚀 Features

- **API-Only Architecture**: Stateless RESTful API
- **Internal JWT Authentication**: email + password, short lived access tokens and rotating refresh tokens
- **Mandatory Google Authenticator (TOTP)**: configured by the user on first login, with recovery codes
- **Role based access**: `admin` (manages users and every application) and `manager` (global, or restricted to specific applications)
- **Multi-Channel Messaging**: Support for Email, SMS, and WhatsApp
- **Laravel Horizon**: Advanced queue monitoring and management
- **Repository Pattern**: Clean separation of concerns
- **Swagger/OpenAPI Documentation**: Auto-generated API documentation
- **Production Ready**: Optimized for scalability and security

## 📋 Resources

The microservice manages the following resources:

1. **Businesses** - Core business entities
2. **Messages** - Unified message tracking across all channels
3. **Email Templates** - Unlayer-compatible email templates
4. **SMS Templates** - SMS message templates
5. **WhatsApp Templates** - Meta/Facebook-approved WhatsApp templates
6. **WhatsApp Phone Numbers** - WhatsApp Business phone number management
7. **SMTP Settings** - Email server configurations
8. **SMS Settings** - SMS provider configurations (Twilio, Nexmo, AfricasTalking, etc.)
9. **Facebook Settings** - Meta/Facebook API credentials and configuration

## 👤 User Management

Users live in this service (the `users` table); there is no external identity provider.

- **Roles**: `admin` manages users and reaches every application; `manager` operates on the applications they are assigned to.
- **Scope**: a manager is either `global` (every application, including future ones) or `restricted` to a list of applications through the `business_user` pivot.
- **Two-factor**: Google Authenticator is mandatory. A new account has no secret, so the first login returns a short lived `setup_token` that drives the enrolment wizard. Until it is confirmed, every application route answers `403 two_factor_setup_required`.

### Creating the first administrator (server side)

```bash
php artisan admin:create --email=admin@example.com --password='Secret123' --name="Administrator"
```

Run it interactively by omitting the options, and add `--force` to update an account that already exists. Inside Docker:

```bash
docker compose exec app php artisan admin:create --email=admin@example.com --password='Secret123' --name="Administrator"
```

Managers can be provisioned the same way:

```bash
# Global manager
php artisan user:create --email=manager@example.com --password='Secret123' --name="Manager" --scope=global

# Manager restricted to applications 3 and 7
php artisan user:create --email=manager@example.com --password='Secret123' --name="Manager" --business=3 --business=7
```

The password is never printed back, and Google Authenticator is configured by the user on their first login.

## 🛠️ Installation

### Prerequisites

- PHP 8.2+
- Composer
- Redis
- Database (MySQL, PostgreSQL, or SQLite)

### Steps

1. **Clone the repository**
```bash
git clone <repository-url>
cd aninfpush
```

2. **Install dependencies**
```bash
composer install
```

3. **Configure environment**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure authentication**
Edit `.env`. `JWT_SECRET` falls back to `APP_KEY` when left empty:
```
JWT_SECRET=
JWT_ALGO=HS256
JWT_ACCESS_TTL=60        # access token lifetime, in minutes
JWT_REFRESH_TTL=20160    # refresh token lifetime, in minutes (14 days)
JWT_AUDIENCE=aninfpush
```

5. **Configure Database**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aninfpush
DB_USERNAME=root
DB_PASSWORD=
```

6. **Run migrations**
```bash
php artisan migrate
```

7. **Install and configure Horizon**
```bash
php artisan horizon:install
```

8. **Start the development server**
```bash
php artisan serve
```

9. **Start Horizon**
```bash
php artisan horizon
```

## 📚 API Documentation

### Accessing Swagger Documentation

Once the application is running, access the Swagger UI at:
```
http://localhost:8000/api/documentation
```

### Authentication

All API endpoints (except `/health`, `/api/v1/auth/*` and `/api/public/*`) require a Bearer access token obtained from `POST /api/v1/auth/login`.

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
Accept: application/json
```

### Example Requests

#### Health Check
```bash
GET /api/health
```

#### Get All Businesses
```bash
GET /api/v1/businesses?per_page=15
Authorization: Bearer <token>
```

#### Create a Business
```bash
POST /api/v1/businesses
Authorization: Bearer <token>
Content-Type: application/json

{
  "name": "Acme Corporation",
  "email": "contact@acme.com",
  "phone_number": "+237670000000",
  "country_code": "+237",
  "website": "https://acme.com",
  "description": "A leading technology company",
  "address": "123 Main St",
  "city": "Douala",
  "country": "Cameroon",
  "timezone": "Africa/Douala"
}
```

## 🏗️ Architecture

### Repository Pattern

The application uses the Repository Pattern for data access abstraction:

```
app/
├── Models/              # Eloquent models
├── Repositories/
│   ├── Contracts/      # Repository interfaces
│   └── *Repository.php # Repository implementations
└── Http/
    └── Controllers/
        └── Api/
            └── V1/     # Versioned controllers
```

### Example Usage

```php
use App\Repositories\Contracts\BusinessRepositoryInterface;

class BusinessController extends Controller
{
    protected $businessRepository;

    public function __construct(BusinessRepositoryInterface $businessRepository)
    {
        $this->businessRepository = $businessRepository;
    }

    public function index()
    {
        $businesses = $this->businessRepository->all(15);
        return response()->json($businesses);
    }
}
```

## 🔐 Authentication flow

```
POST /api/v1/auth/login                { email, password }
  ├─ 2FA never configured  → { two_factor_setup_required: true, setup_token }
  │     POST /api/v1/auth/two-factor/setup    { setup_token }   → { secret, otpauth_url, qr_code, instructions }
  │     POST /api/v1/auth/two-factor/confirm  { setup_token, code } → tokens + recovery_codes
  ├─ 2FA configured        → { two_factor_required: true, challenge_token }
  │     POST /api/v1/auth/login/two-factor    { challenge_token, code } → tokens
  └─ code sent inline      → tokens directly

POST /api/v1/auth/refresh  { refresh_token }   → a new pair (the old refresh token is revoked)
POST /api/v1/auth/logout   { refresh_token }   → revokes it (add all_devices: true to revoke every session)
```

Access tokens are HS256 JWTs carrying `sub`, `email`, `role` and `scope`. Refresh tokens are opaque; only their SHA-256 hash is stored, and `auth:prune-tokens` (scheduled daily) clears the expired ones.

Both the TOTP code and a single use recovery code are accepted wherever a code is asked.


## 📨 Queue Management with Horizon

### Job Example: SendMessagesJob

```php
use App\Jobs\SendMessagesJob;

// Dispatch a job to send a message
SendMessagesJob::dispatch($messageId);
```

### Monitoring

Access Horizon dashboard at:
```
http://localhost:8000/horizon
```

Features:
- Real-time queue monitoring
- Failed job management
- Job metrics and statistics
- Auto-scaling workers

## 🗄️ Database Schema

### Key Tables

- `businesses` - Business entities
- `messages` - Unified message log
- `email_templates` - Email templates with Unlayer design
- `sms_templates` - SMS message templates
- `whatsapp_templates` - WhatsApp approved templates
- `whatsapp_phone_numbers` - WhatsApp Business phone numbers
- `smtp_settings` - SMTP server configurations
- `sms_settings` - SMS provider settings
- `facebook_settings` - Facebook/Meta API credentials

## 🧪 Testing

Run tests:
```bash
php artisan test
```

## 📦 Deployment

### Production Checklist

1. Set `APP_ENV=production` and `APP_DEBUG=false`
2. Configure production database
3. Set up Redis for caching and queues
4. Set a dedicated `JWT_SECRET` and create the first administrator with `php artisan admin:create`
5. Set up SSL/TLS certificates
6. Configure queue workers as systemd services
7. Set up Horizon monitoring
8. Configure backups
9. Enable rate limiting
10. Set up logging and monitoring

### Queue Workers

For production, use Supervisor to manage Horizon:

```ini
[program:aninfpush-horizon]
process_name=%(program_name)s
command=php /path/to/aninfpush/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/aninfpush/storage/logs/horizon.log
stopwaitsecs=3600
```

## 📝 Development

### Creating New Resources

1. **Create Migration**
```bash
php artisan make:migration create_resource_table
```

2. **Create Model**
```bash
php artisan make:model Resource
```

3. **Create Repository Interface**
```php
// app/Repositories/Contracts/ResourceRepositoryInterface.php
```

4. **Create Repository Implementation**
```php
// app/Repositories/ResourceRepository.php
```

5. **Register in Service Provider**
```php
// app/Providers/RepositoryServiceProvider.php
$this->app->bind(ResourceRepositoryInterface::class, ResourceRepository::class);
```

6. **Create Controller**
```bash
php artisan make:controller Api/V1/ResourceController --api
```

7. **Add Routes**
```php
// routes/api.php
Route::apiResource('resources', ResourceController::class);
```

## 📦 Template export / import

Templates move between applications as portable documents. Ids, business, usage counters and
approval state are never carried over, and an import always lands as an **inactive draft**.

```bash
# One template, as JSON or TXT
GET  /api/v1/templates/{email|sms|whatsapp}/{id}/export?format=json   # add &download=0 for an inline body

# Several templates of the same type, in one file
POST /api/v1/templates/export           { type, ids: [...], format }

# Inspect a file without saving anything
POST /api/v1/templates/import/preview   multipart: file

# Import: the only required choice is the target application
POST /api/v1/templates/import           multipart: file, business_id[, type, name]
```

The TXT format is the very same JSON wrapped in a short comment header, so a `.txt` export can be
re-imported as-is. When two templates share a name inside an application, the imported one is
suffixed (`Welcome (2)`) rather than failing.

## 🔎 Filtering

Every list endpoint composes its filters instead of honouring only the first one, and all of them
accept `per_page` (capped at 100), `sort_by` and `sort_dir`.

| Endpoint | Filters |
|---|---|
| `/businesses` | `search`, `status`, `status_in`, `verification_status`, `country`, `city`, `timezone`, `created_from`, `created_to` |
| `/messages` | `search`, `business_id`, `message_type`, `status`, `status_in`, `template_id`, `is_template`, `recipient`, `subject`, `campaign_id`, `has_error`, `start_date`, `end_date`, `sent_from`, `sent_to`, `min_cost`, `max_cost` |
| `/email-templates` | `search`, `business_id`, `status`, `category`, `is_active`, `created_from`, `created_to`, `min_usage_count`, `max_usage_count` |
| `/sms-templates` | same, plus `min_cost_per_message` / `max_cost_per_message` |
| `/whatsapp-templates` | same, plus `language` and `facebook_status` |
| `/users` | `search`, `role`, `scope`, `is_active`, `two_factor`, `business_id`, `created_from`, `created_to` |

Managers whose scope is `restricted` only ever see their own applications: the restriction is applied
server side, so passing another `business_id` returns nothing rather than someone else's data.

The same rule covers single records, not just the lists. Reading, updating, deleting, activating,
exporting or importing something that belongs to another application answers `403`, and creating a
record under another `business_id` is refused too. An id that does not exist still answers `404`, so
the guard does not leak which ids are taken.

## 🧪 Tests

```bash
php artisan test
```

The suite runs on an in-memory SQLite database, so no MySQL is needed.

## 🤝 Contributing

This is a proprietary microservice. Please contact the development team for contribution guidelines.

## 📄 License

Proprietary - All rights reserved.

## 📞 Support

For support, please contact: support@aninfpush.com

## 🔖 Version

Current version: **1.0.0**

Built with Laravel 12 LTS
