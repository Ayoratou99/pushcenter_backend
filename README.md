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

## 🐳 Docker

### Avant le premier `docker compose up`

Deux secrets doivent exister **avant** de lancer la stack. Ils ne sont pas générés
automatiquement : chaque container en produirait un différent, perdu à chaque
recréation.

```bash
cp .env.example .env
```

**1. `APP_KEY`** — chiffre les colonnes `two_factor_secret` et
`two_factor_recovery_codes`, et sert de secret JWT par défaut.

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

Collez la valeur affichée dans `.env` :

```env
APP_KEY=base64:LaValeurAffichee...
```

> Sans conteneur PHP disponible, la même clé peut être produite avec :
> ```bash
> echo "base64:$(openssl rand -base64 32)"
> ```

**2. `JWT_SECRET`** — optionnel. Laissé vide, il retombe sur `APP_KEY`, ce qui
convient en développement. En production, donnez-lui sa propre valeur pour pouvoir
la faire tourner sans toucher au chiffrement des données :

```bash
echo "JWT_SECRET=$(openssl rand -hex 32)"
```

> Toute valeur fonctionne : le secret est étendu en clé de 32 octets par HKDF
> avant signature (RFC 7518 impose une clé au moins aussi longue que le hash).

**3. Base de données et Redis** — ajustez dans `.env` :

```env
DB_DATABASE=aninfpush
DB_USERNAME=aninfpush_user
DB_PASSWORD=<mot-de-passe-fort>
DB_ROOT_PASSWORD=<autre-mot-de-passe-fort>
REDIS_PASSWORD=<mot-de-passe-fort>
```

### Lancer la stack

```bash
docker compose up -d --build
```

L'entrypoint applique les migrations et met la configuration en cache. Il
s'arrête avec un message explicite si `APP_KEY` manque.

### Créer le premier administrateur

```bash
docker compose exec app php artisan admin:create \
    --email=admin@example.com --password='MotDePasseFort123' --name="Administrateur"
```

Google Authenticator est obligatoire : l'assistant s'ouvre à la première connexion.

### ⚠️ Ne changez pas `APP_KEY` après coup

`APP_KEY` chiffre les secrets Google Authenticator en base. La modifier rend tous
les enrôlements 2FA indéchiffrables, et les utilisateurs se retrouvent enfermés
dehors. S'il faut absolument la changer, réinitialisez d'abord le second facteur
de tous les comptes :

```bash
docker compose exec app php artisan tinker --execute="\
    App\Models\User::query()->update([ \
        'two_factor_secret' => null, \
        'two_factor_confirmed_at' => null, \
        'two_factor_recovery_codes' => null, \
    ]);"
```

Chacun refera l'enrôlement à sa prochaine connexion.

### Services

| Service | Rôle | Port |
|---|---|---|
| `app` | API (nginx + php-fpm) | 8000 |
| `horizon` | worker de files | interne |
| `cron` | planificateur Laravel | interne |
| `db` | MySQL 8 | interne |
| `redis` | cache, files, rate limiting | interne |

Le frontend vit dans son propre dépôt et se branche sur le réseau
`aninfpush_network` créé ici.

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

## 🔌 API applicative (machine à machine)

Une application s'authentifie avec ses propres identifiants, puis envoie ses messages.
Le token porte l'application : aucun endpoint n'accepte un `business_id` dans le corps.

### 1. Obtenir un token

```bash
curl -X POST https://api.example.com/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"app_id":"app_...","app_secret":"secret_..."}'
```

```json
{ "data": { "access_token": "eyJ...", "token_type": "Bearer", "expires_in": 3600 } }
```

Le secret n'est **stocké que haché**. Il n'apparaît en clair qu'une fois, à la création de
l'application ou lors d'une régénération. Perdu, il faut en générer un nouveau.

### 2. Envoyer un email par template

```bash
curl -X POST https://api.example.com/api/v1/app/messages/email \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Content-Type: application/json' \
  -d '{
        "template_id": 12,
        "recipient_email": "jane@customer.test",
        "variables": {"name": "Jane", "order_id": "A-1234"}
      }'
```

La réponse est `201` avec `"status": "queued"`. Le message part sur la queue `emails`, que
Horizon traite avec la configuration SMTP de l'application elle-même.

Refusé **avant** la mise en file, plutôt que d'échouer plus tard :

| Cas | Réponse |
|---|---|
| Template d'une autre application | `422` |
| Template inactif | `422` |
| Variable `{{...}}` sans valeur | `422`, avec la liste des manquantes |
| Aucune configuration SMTP active | `503` |

### 3. Suivre la livraison

```bash
curl -H 'Authorization: Bearer eyJ...' \
  https://api.example.com/api/v1/app/messages/{message_id}
```

En cas d'échec, `error_message` contient la raison exacte remontée par le serveur SMTP.

### Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| `POST` | `/v1/auth/token` | Échange app_id + app_secret contre un token |
| `POST` | `/v1/app/messages/email` | Met un email en file |
| `GET` | `/v1/app/messages/{id}` | Statut de livraison |
| `GET` | `/v1/app/templates/email` | Templates email actifs |

Un token applicatif ne peut pas atteindre l'API d'administration, et un token utilisateur ne
peut pas atteindre `/v1/app/*`.

## 📣 Webhooks

Quand une application déclare une `webhook_url`, chaque changement d'état de ses messages y est
poussé. C'est le moyen le plus direct d'apprendre un échec sans interroger l'API.

Configuration depuis **Applications → une application → Webhooks**, ou via l'API
(`webhook_url`, `webhook_secret`, `webhook_events`).

### Événements

| Événement | Quand |
|---|---|
| `message.sent` | Le serveur SMTP a accepté le message |
| `message.failed` | La livraison a échoué, avec la raison |

Sans liste `webhook_events`, tous les événements sont envoyés.

### Charge utile

```json
{
  "event": "message.failed",
  "occurred_at": "2026-01-15T10:32:11+00:00",
  "data": {
    "message_id": "9b1c...",
    "message_type": "email",
    "status": "failed",
    "recipient": "jane@customer.test",
    "subject": "Order A-1234 confirmed",
    "error_message": "Connection could not be established with host smtp.example.test:587",
    "retry_count": 3,
    "failed_at": "2026-01-15T10:32:11+00:00"
  }
}
```

### Vérifier la signature

Chaque requête porte `X-AninfPush-Signature` : le HMAC-SHA256 du corps **brut**, calculé avec
votre `webhook_secret`. Vérifiez-le avant de parser.

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $yourSecret);

if (! hash_equals($expected, $request->header('X-AninfPush-Signature'))) {
    abort(401);
}
```

En-têtes également présents : `X-AninfPush-Event` et `X-AninfPush-Delivery` (le `message_id`,
utile pour dédupliquer).

### Isolation

Les webhooks tournent sur leur **propre queue** (`webhooks`), séparée de `emails`. Un endpoint
lent ou en panne ne retarde jamais l'envoi des messages, et **ne fait jamais basculer un message
livré en échec**. Le résultat de la notification est suivi à part sur le message
(`webhook_status`, `webhook_error`, `webhook_attempts`), visible dans le détail d'un message
depuis le tableau de bord.

Un endpoint qui répond une erreur est réessayé 5 fois, avec un délai croissant
(10 s, 1 min, 5 min, 15 min).

## 🌐 CORS et origine du frontend

`FRONTEND_URL` est l'origine de la console d'administration. Seules les origines
déclarées peuvent appeler l'API **depuis un navigateur**.

```env
# Origine de la console : schéma + hôte + port, sans slash final ni chemin.
FRONTEND_URL=http://localhost

# Origines supplémentaires, séparées par des virgules (console de recette, etc.)
CORS_ALLOWED_ORIGINS=

# Durée de cache du préflight, en secondes.
CORS_MAX_AGE=3600
```

Configuration dans [config/cors.php](config/cors.php) :

| Réglage | Valeur |
|---|---|
| Chemins couverts | `api/*` |
| Origines | `FRONTEND_URL` + `CORS_ALLOWED_ORIGINS` |
| En-têtes acceptés | `Accept`, `Authorization`, `Content-Type`, `X-Requested-With`, `X-CSRF-TOKEN` |
| En-têtes exposés | `Content-Disposition` (nom de fichier des exports) |
| Credentials | désactivés — les jetons voyagent dans `Authorization`, jamais en cookie |

Tant que rien n'est configuré, l'API accepte toutes les origines pour qu'une
installation neuve fonctionne. **En production, `FRONTEND_URL` doit être défini.**

> L'API applicative n'est pas concernée : CORS est un mécanisme de navigateur, et
> un appel serveur à serveur n'envoie aucun en-tête `Origin`.

`FRONTEND_URL` sert aussi à autoriser la console à **embarquer la documentation
Swagger** dans une iframe (en-tête `Content-Security-Policy: frame-ancestors`,
posé par nginx uniquement sur `/api/documentation` et `/docs`). Le reste de l'API
conserve `X-Frame-Options: SAMEORIGIN`.

## 🔔 Notifications

`GET /api/v1/notifications` renvoie les alertes d'exploitation des 7 derniers
jours, limitées aux applications que l'utilisateur peut voir.

| Type | Origine | Gravité |
|---|---|---|
| `message.failed` | Un message dont la livraison a échoué, avec sa raison | erreur |
| `webhook.failed` | Une notification qui n'a pas atteint l'endpoint client | avertissement |
| `smtp.failed` | Une configuration SMTP dont le dernier test a échoué | avertissement |

Elles sont **dérivées de l'état réel**, pas stockées en base : une alerte
disparaît d'elle-même quand le problème qu'elle décrit sort de la fenêtre.

`POST /api/v1/notifications/read` marque tout comme lu. Le repère est propre à
chaque utilisateur (`users.notifications_read_at`) : « non lu » signifie
simplement « survenu après ma dernière ouverture du panneau ».

Dans la console, la cloche affiche le nombre de non-lus, se rafraîchit toute les
minutes, et un clic sur une alerte ouvre le message concerné.

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
