# AninfPush - Multi-Channel Messaging Microservice

AninfPush is a Laravel 12 LTS API-only microservice designed for production-ready multi-channel messaging (Email, SMS, WhatsApp) with Keycloak authentication, Laravel Horizon for queue management, and comprehensive Swagger/OpenAPI documentation.

## 🚀 Features

- **API-Only Architecture**: Stateless RESTful API
- **Keycloak Authentication**: Secure JWT-based authentication
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

## ⚠️ User Management

**This microservice does NOT manage users locally.**

- ✅ All authentication is handled by **Keycloak**
- ✅ No local user table or User model
- ✅ Stateless JWT-based authentication only
- ✅ All user management operations (create, update, password reset) are done in Keycloak

See `AUTHENTICATION.md` for detailed Keycloak integration documentation.

## 🛠️ Installation

### Prerequisites

- PHP 8.2+
- Composer
- Redis
- Database (MySQL, PostgreSQL, or SQLite)
- Keycloak Server

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

4. **Configure Keycloak**
Edit `.env` and set your Keycloak credentials:
```env
KEYCLOAK_SERVER_URL=http://your-keycloak-server:8080
KEYCLOAK_REALM=your-realm
KEYCLOAK_CLIENT_ID=aninfpush
KEYCLOAK_CLIENT_SECRET=your-client-secret
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

All API endpoints (except `/health`) require Bearer Token authentication via Keycloak JWT.

**Headers:**
```
Authorization: Bearer <your-keycloak-jwt-token>
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

## 🔐 Keycloak Integration

### Middleware

All API routes are protected by the `keycloak.auth` middleware which:
- Validates JWT tokens via Keycloak introspection endpoint
- Caches validation results for performance
- Attaches user info to the request

### Configuration

Configure Keycloak settings in `config/keycloak.php`:

```php
'server_url' => env('KEYCLOAK_SERVER_URL'),
'realm' => env('KEYCLOAK_REALM'),
'client_id' => env('KEYCLOAK_CLIENT_ID'),
'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
'validation_method' => 'introspect', // or 'local'
'cache_ttl' => 5, // minutes
```

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
4. Configure Keycloak production instance
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

## 🤝 Contributing

This is a proprietary microservice. Please contact the development team for contribution guidelines.

## 📄 License

Proprietary - All rights reserved.

## 📞 Support

For support, please contact: support@aninfpush.com

## 🔖 Version

Current version: **1.0.0**

Built with Laravel 12 LTS
