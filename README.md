# AninfPush - Multi-Channel Messaging Microservice

AninfPush is a Laravel 12 LTS API-only microservice designed for production-ready multi-channel messaging (Email, SMS, WhatsApp, Telegram) with internal JWT authentication, mandatory Google Authenticator, Laravel Horizon for queue management, and comprehensive Swagger/OpenAPI documentation.

## 🚀 Features

- **API-Only Architecture**: Stateless RESTful API
- **Internal JWT Authentication**: email + password, short lived access tokens and rotating refresh tokens
- **Mandatory Google Authenticator (TOTP)**: configured by the user on first login, with recovery codes
- **Role based access**: `admin` (manages users and every application) and `manager` (global, or restricted to specific applications)
- **Multi-Channel Messaging**: Support for Email, SMS, WhatsApp (through AyosPush) and Telegram (one bot per application)
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
5. **WhatsApp Templates** - submitted to Meta and synchronised through AyosPush
6. **WhatsApp Settings** - the AyosPush API key of each application
7. **Telegram Templates** - formatted messages with link buttons
8. **Telegram Settings** - the bot of each application, its subscribers and invitation links
9. **SMTP Settings** - Email server configurations
10. **SMS Settings** - SMS provider configurations (Twilio, Nexmo, AfricasTalking, etc.)

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

| Volume | Monté sur | Contenu |
|---|---|---|
| `db_data` | `/var/lib/mysql` (`db`) | La base |
| `redis_data` | `/data` (`redis`) | Files et cache |
| `app_files` | `/var/www/html/storage/app` (`app`, `horizon`, `cron`) | Fichiers joints aux templates Telegram |

Un rebuild des images ne touche pas aux volumes ; `docker compose down -v` les efface.

Le frontend vit dans son propre dépôt et se branche sur le réseau
`aninfpush_network` créé ici.

## 👤 User Management

Users live in this service (the `users` table); there is no external identity provider.

- **Roles**: `admin` manages users and reaches every application; `manager` operates on the applications they are assigned to.
- **Scope**: a manager is either `global` (every application, including future ones) or `restricted` to a list of applications through the `business_user` pivot.
- **What a manager may do**:
  - edit its own applications, but not create or delete one (admins only);
  - add users, always as managers restricted to some of its own applications, never admins or global managers;
  - edit, reset or delete only the managers whose applications are all among its own (a user shared with another application stays read-only for it), and never itself (that goes through the profile).
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

## 🕵️ Activity (audit trail)

Every console action is written to `activity_logs` once it succeeded: who, on
which application, what (`action`, e.g. `whatsapp_template.submitted`, and a
readable description), the subject, the submitted values **with secrets
masked** (`password`, `api_secret`, tokens, codes…) and bulky content left out,
the method and route, the real client IP (see TRUSTED_PROXIES) and the user
agent. Sign-ins, failed sign-ins (wrong password or code) and the Google
Authenticator enrolment are recorded as well. Refused requests (403, 422…) are
not.

Names are copied at the time of the action, so an entry still reads correctly
once the user, the template or the application is deleted.

```
GET /api/v1/activities?action_group=whatsapp_template&business_id=3&start_date=2026-09-01
GET /api/v1/activities/actions        # every action, for filters
```

Filters combine: `search`, `user_id`, `business_id`, `action`, `action_group`,
`subject_type`, `subject_id`, `ip_address`, `start_date`, `end_date`.

Visibility: admins and global managers see everything. A manager restricted to
some applications sees what happened on them, plus the actions not tied to an
application (sign-ins, profile and user changes) done by or on the users of
those applications. The console shows it under **Activity**.

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

### Supervisors and scaling

| Superviseur | Files | Workers (min → max) | Timeout |
|-------------|-------|---------------------|---------|
| `supervisor-1` | `default`, `emails`, `sms`, `whatsapp`, `telegram` | 1 par file → 10 au total | 60 s |
| `supervisor-webhooks` | `webhooks` | 1 → 3 | 30 s |

- Au repos : 6 workers (1 par file). Sous charge, Horizon répartit les workers
  d'un superviseur selon « jobs en attente × durée moyenne d'un job », par pas de
  1 worker toutes les 3 s ; une file de `supervisor-1` monte au plus à 6 (10 moins
  1 pour chacune des 4 autres).
- Les webhooks ont leurs propres workers : un endpoint client lent ou en panne
  ne prend jamais de capacité à l'envoi des messages.
- En `local` : jusqu'à 6 + 1 workers. Tout autre `APP_ENV` (`staging`, `preprod`…) prend
  la configuration de production (`'*'` dans `config/horizon.php`) : sans elle,
  Horizon ne démarrerait aucun superviseur et les jobs attendraient dans Redis.

### Redis

Le client est **phpredis**, l'extension compilée dans l'image Docker
(`pecl install redis`). Le paquet `predis/predis` a été retiré : il n'était pas
utilisé et portait une faille critique (CVE-2026-84372). En dehors de Docker,
installez l'extension (`pecl install redis`) ou utilisez d'autres drivers
(`CACHE_STORE=file`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file`).

## 🗄️ Database Schema

### Key Tables

- `businesses` - Business entities
- `messages` - Unified message log
- `email_templates` - Email templates with Unlayer design
- `sms_templates` - SMS message templates
- `whatsapp_templates` - WhatsApp templates and their AyosPush identity (`provider_template_id`, `provider_template_name`)
- `whatsapp_settings` - AyosPush API key of each application (secret encrypted)
- `smtp_settings` - SMTP server configurations
- `sms_settings` - SMS provider settings

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

### 3. Envoyer un message WhatsApp par template

Le template doit être **approuvé par Meta et actif** (voir « WhatsApp via AyosPush »).
Les variables sont numérotées, dans l'ordre du template (corps d'abord) ; une liste
est acceptée aussi (`["Awa", "CMD-2026-001"]`).

```bash
curl -X POST https://api.example.com/api/v1/app/messages/whatsapp \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Content-Type: application/json' \
  -d '{
    "template_id": 12,
    "recipient_number": "+24177123456",
    "recipient_name": "Awa",
    "variables": {"1": "Awa", "2": "CMD-2026-001"},
    "campaign_id": "rentree-2026"
  }'
```

`template_name` (+ `language` si le nom existe en français et en anglais) peut
remplacer `template_id`. `GET /v1/app/templates/whatsapp` liste les templates
envoyables avec les numéros de variables attendus.

Derrière, `SendMessagesJob` (file `whatsapp`) appelle AyosPush
`POST /v1/messages/template` avec la clé et le numéro expéditeur de l'application,
puis `TrackWhatsappDelivery` lit le résultat sur `GET /v1/messages/{requestId}/status`
(après 10 s, 30 s, 1 min 30… jusqu'à ~2 h) :

| Statut | Signification |
|---|---|
| `queued` | En file chez AninfPush (ou en attente de la limite de débit AyosPush) |
| `sending` | Accepté par AyosPush, en cours d'envoi |
| `sent` | Remis à Meta ; `whatsapp.whatsapp_message_id` est l'identifiant WhatsApp |
| `failed` | Refusé ou non envoyé, avec la raison dans `error_message` |

AyosPush ne transmet pas les accusés « délivré » / « lu » de Meta à ses clients
API : `sent` est le dernier statut qu'un message WhatsApp atteint ici. Les
webhooks `message.sent` / `message.failed` partent comme pour l'email.

| Cas refusé | Réponse |
|---|---|
| Template inconnu, non approuvé ou inactif | `422` |
| Numéro qui n'est pas au format international | `422` |
| Variable manquante, ou avec retour à la ligne, tabulation ou plus de 4 espaces (refusé par Meta) | `422` |
| AyosPush non configuré ou non testé, ou aucun numéro expéditeur choisi | `503` |

À savoir :

- AyosPush limite l'API à **5 requêtes/s et 1000/h par compte AyosPush** (toutes
  clés confondues). Chaque message coûte un envoi plus au moins une lecture de
  statut : comptez ~450 messages/heure au plus par compte. AninfPush cadence ses
  appels (`AYOSPUSH_REQUESTS_PER_SECOND`, 4 par défaut) et attend quand AyosPush
  répond 429.
- Le palier de messagerie Meta du numéro (conversations ouvertes par 24 h) épuisé,
  un quota AyosPush dépassé ou un template refusé font échouer le message avec
  la raison, sans nouvel essai automatique.
- Sans réponse d'AyosPush une fois la requête partie (délai dépassé), le message
  échoue avec un avertissement : il a pu être reçu, vérifiez avant de le relancer.
- Relancer un message échoué depuis la console crée une nouvelle requête AyosPush.
- L'en-tête média d'un template est celui téléversé à sa création ; l'envoi d'un
  fichier propre à chaque message (`file` d'AyosPush) n'est pas encore proposé.

### 4. Envoyer un message Telegram par template

Un bot ne peut jamais écrire en premier : la personne doit d'abord ouvrir le bot et
appuyer sur **Démarrer**. L'application lui envoie donc un lien d'invitation portant
sa propre référence pour cette personne (numéro de client, de dossier…) :

```bash
curl -X POST https://api.example.com/api/v1/app/telegram/invitations \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Content-Type: application/json' \
  -d '{"external_ref": "citizen-4521", "label": "Awa Ndong", "expires_in_hours": 168}'
```

```json
{ "data": { "link": "https://t.me/GuichetAninfBot?start=Xb3…", "external_ref": "citizen-4521", "expires_at": "…" } }
```

Le lien sert une fois (7 jours par défaut). Quand la personne démarre le bot, son chat
est rattaché à `external_ref` ; `GET /v1/app/telegram/subscribers/citizen-4521`
répond alors `"subscribed": true`. Ensuite :

```bash
curl -X POST https://api.example.com/api/v1/app/messages/telegram \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Content-Type: application/json' \
  -d '{
    "template_id": 5,
    "external_ref": "citizen-4521",
    "variables": {"name": "Awa", "date": "12 octobre", "ref": "D-2026-001"}
  }'
```

`template_name` peut remplacer `template_id`, et `chat_id` remplacer `external_ref`.
Les valeurs des variables sont échappées (elles ne peuvent ni ajouter de mise en forme
ni injecter de lien) et encodées dans l'adresse des boutons. Le message part sur la
file `telegram` (`sendMessage` de la Bot API, ~25 messages/s par bot).

**Pièce jointe.** Un template peut envoyer une photo, une vidéo ou un document, le texte
devenant sa légende (1024 caractères, facultative) :

- *fichier du template* (le même pour tous : bannière, guide PDF) — rien à ajouter à
  l'appel ;
- *fichier propre à chaque message* (facture, reçu) — l'application ajoute `media_url`,
  un lien public que **Telegram télécharge lui-même** : 5 Mo au plus pour une photo,
  20 Mo pour une vidéo ou un document, et par lien un document doit être un PDF, un ZIP
  ou un GIF. Rien n'est stocké chez AninfPush.

```json
{ "template_id": 8, "external_ref": "citizen-4521",
  "variables": {"numero": "F-2026-001"},
  "media_url": "https://files.example.com/invoices/F-2026-001.pdf" }
```

`GET /v1/app/templates/telegram` indique pour chaque template `media_type` et
`needs_media_url`.

| Statut | Signification |
|---|---|
| `queued` | En file (ou en attente de la limite de Telegram, qui donne son délai) |
| `sent` | Remis par Telegram ; `telegram.telegram_message_id` est son identifiant |
| `failed` | Refusé, avec la raison dans `error_message` |

| Cas refusé | Réponse |
|---|---|
| Template inconnu ou inactif | `422` |
| Personne qui n'a pas démarré le bot avec cette référence | `404` |
| Personne qui a bloqué le bot ou envoyé `/stop` | `422` |
| Variable manquante, ou message de plus de 4096 caractères (1024 pour une légende) une fois rempli | `422` |
| `media_url` absent pour un template à fichier par message, ou donné à un autre template | `422` |
| Fichier du template manquant | `422` |
| Aucun bot configuré et testé | `503` |

Un bot bloqué entre-temps fait échouer le message et passe l'abonné en `blocked`. Sans
réponse de Telegram une fois la requête partie, le message échoue avec un avertissement
(il a pu être remis) plutôt que d'être renvoyé en double. Un lien que Telegram ne peut
pas télécharger (privé, page de connexion, trop lourd, mauvais type) fait échouer le
message avec la raison donnée par Telegram.

### 5. Suivre la livraison

```bash
curl -H 'Authorization: Bearer eyJ...' \
  https://api.example.com/api/v1/app/messages/{message_id}
```

En cas d'échec, `error_message` contient la raison exacte remontée par le serveur SMTP,
par AyosPush ou par Telegram.

### Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| `POST` | `/v1/auth/token` | Échange app_id + app_secret contre un token |
| `POST` | `/v1/app/messages/email` | Met un email en file |
| `POST` | `/v1/app/messages/whatsapp` | Met un message WhatsApp (template) en file |
| `GET` | `/v1/app/messages/{id}` | Statut de livraison |
| `GET` | `/v1/app/templates/email` | Templates email actifs |
| `GET` | `/v1/app/templates/whatsapp` | Templates WhatsApp envoyables, avec leurs variables |
| `POST` | `/v1/app/messages/telegram` | Met un message Telegram (template) en file |
| `GET` | `/v1/app/templates/telegram` | Templates Telegram actifs, avec leurs variables |
| `POST` | `/v1/app/telegram/invitations` | Lien d'invitation au bot pour une de vos références |
| `GET` | `/v1/app/telegram/subscribers/{externalRef}` | Cette personne a-t-elle démarré le bot ? |

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

## 🔀 Reverse proxy (TLS terminé en amont)

En préprod et en production, Traefik termine le TLS et transmet la requête en
HTTP avec les en-têtes `X-Forwarded-*`. Laravel ne les lit que depuis les
proxies de confiance, définis par `TRUSTED_PROXIES` (`config/trustedproxy.php`).
`bootstrap/app.php` remplace le middleware `TrustProxies` du framework par
`App\Http\Middleware\TrustProxies`, qui lit cette configuration à chaque requête :

```env
TRUSTED_PROXIES=*                          # le pair direct, quel qu'il soit (défaut)
TRUSTED_PROXIES=10.0.1.2                   # ou les IP du proxy…
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12   # …ou ses plages CIDR, séparées par des virgules
TRUSTED_PROXIES=                           # vide : personne (en-têtes X-Forwarded-* ignorés)
```

La valeur est prise avec le reste de la configuration : avec `config:cache` (lancé
au démarrage du conteneur), la changer demande de recréer le conteneur.
`$middleware->trustProxies(at: …)` n'est pas utilisé : il s'exécute avant le
chargement de la configuration, et demanderait une liste en dur ou un `env()`
hors de `config/`.

Sans cela, les URLs générées sont en `http://` (la page Swagger charge alors ses
CSS/JS en contenu mixte, bloqué : page blanche) et `$request->ip()` vaut l'IP du
proxy pour tout le monde (limitation des connexions et des jetons applicatifs
par IP neutralisée, IP fausse dans les jetons enregistrés). `*` suppose que l'API
n'est joignable qu'à travers le proxy ; si le port du conteneur est exposé
directement, donnez la liste des IP du proxy.

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

## 🩺 État du worker de file

`GET /api/v1/system/horizon` indique si Horizon traite bien les jobs. C'est ce
qui alimente le bandeau d'alerte de la console.

Ça compte parce que **tout ce qui sort passe par la file** : Horizon arrêté,
l'API accepte les messages et les marque `queued`, mais rien n'est jamais envoyé.

```json
{
  "status": "inactive",
  "healthy": false,
  "message": "Horizon is not running: queued messages will not be delivered.",
  "supervisors": 0,
  "queues": [],
  "pending_jobs": 0,
  "failed_jobs": 3,
  "longest_wait_seconds": 0
}
```

| `status` | Signification |
|---|---|
| `running` | Des superviseurs tournent. `healthy` est `false` si une file prend du retard (plus de 2 min d'attente) |
| `paused` | Horizon est en pause, les jobs attendent |
| `inactive` | Aucun superviseur : Horizon est arrêté |
| `unknown` | Redis injoignable — on ne peut pas savoir, et l'annoncer « running » serait pire |

> Le battement de cœur d'Horizon expire après **15 secondes** dans Redis. Un
> arrêt est donc détecté avec ce délai, pas instantanément.

La console interroge cet endpoint toutes les 20 secondes et affiche un bandeau en
haut de l'écran tant que `healthy` est `false`. Rien ne s'affiche quand tout va
bien, et le bandeau disparaît de lui-même au retour à la normale.

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

## 💬 WhatsApp via AyosPush

AninfPush ne parle pas à Meta : WhatsApp passe par l'**API publique v1
d'AyosPush**. La connexion Facebook, les comptes WhatsApp Business (WABA) et les
numéros sont gérés dans le tableau de bord AyosPush ; chaque application
AninfPush y a sa propre clé API, comme elle a ses propres réglages SMTP.

### Configuration

1. Dans AyosPush, créer une clé API pour l'application avec les permissions
   `config.read`, `templates.read`, `templates.write` (plus `templates.send` et
   `messages.read` pour l'envoi), idéalement restreinte au WABA de l'application.
   Si la clé restreint les origines, autoriser l'IP du serveur AninfPush.
2. Dans la console : *Applications › l'application › WhatsApp (AyosPush)*,
   saisir la clé et le secret puis **Save and test**. Le test se connecte
   (`POST /v1/auth/login`), lit `GET /v1/facebook-config` et liste les WABA et
   numéros accessibles ; s'il n'y en a qu'un, il est choisi d'office.
3. L'adresse de l'API est commune à toutes les applications :

```env
AYOSPUSH_API_URL=https://ayospush.com/api/v1
AYOSPUSH_TIMEOUT=20
AYOSPUSH_SUBMIT_TIMEOUT=60   # la soumission attend la réponse de Meta
```

Le secret est chiffré en base (il dépend donc d'`APP_KEY`) et n'est jamais
renvoyé, seuls ses 4 derniers caractères. Le jeton AyosPush (1 h) est mis en
cache chiffré et redemandé à l'expiration ou sur un 401. `POST /v1/auth/refresh`
n'est volontairement pas utilisé : côté AyosPush il se ré-authentifie avec un
secret vide, répond 200 sans jeton et révoque le jeton courant.

### Cycle de vie d'un template

| Étape | Endpoint AninfPush | Appel AyosPush |
|-------|--------------------|----------------|
| Brouillon | `POST /api/v1/whatsapp-templates` (toujours `draft`) | — |
| Média d'en-tête | `POST /api/v1/whatsapp-templates/media` (multipart) | `POST /v1/templates/upload-media` |
| Soumission à Meta | `POST /api/v1/whatsapp-templates/{id}/submit` | `POST /v1/templates` avec `submit_for_approval` |
| Statut | `POST /api/v1/whatsapp-templates/{id}/sync` | `GET /v1/templates/{id}` |
| Synchro + import | `POST /api/v1/businesses/{id}/whatsapp-templates/sync` | `GET /v1/templates` (toutes les pages) |

- AyosPush **renomme** chaque template (`<nom>_biz<id>_<horodatage>`) et envoie
  sous ce nom : il est conservé dans `provider_template_name`.
- Statuts Meta → AninfPush : `APPROVED` → `approved`, `REJECTED` → `rejected`,
  `PENDING`/`IN_APPEAL` → `pending`, `PAUSED`/`DISABLED`/`DELETED`… → `disabled`.
  Le statut ne se modifie plus à la main (`status` n'accepte que `draft`).
- Comme sur Meta, un template WhatsApp **n'est jamais modifié** : on en crée un
  nouveau ou on le supprime (`PUT` répond toujours 422). Seul un brouillon jamais
  soumis part chez AyosPush ; un template `rejected` se remplace par une copie
  corrigée (« Duplicate into a new draft » dans la console). Supprimer un
  brouillon jamais soumis l'efface définitivement (son nom redevient libre) ; un
  template connu d'AyosPush reste en suppression douce, pour l'historique et pour
  qu'une synchronisation ne le réimporte pas.
- Un template importé par fichier (export/import JSON ou TXT) arrive en brouillon
  inactif : il faut le soumettre à AyosPush depuis cette application.
- Pour un en-tête média (image, vidéo, document), la synchronisation récupère le
  fichier conservé par AyosPush (`GET /v1/templates/{id}`) quand les composants
  Meta ne donnent qu'un identifiant de téléversement ; à l'envoi, AyosPush joint
  ce même fichier.
- `php artisan whatsapp:sync-templates` (planifiée toutes les 15 min) récupère la
  décision de Meta pour les applications ayant un template en attente. Options :
  `--business=ID`, `--all`, `--import`. Chaque appel à l'API AyosPush lui est
  facturé : la tâche planifiée ignore les applications sans template en attente.
- La synchronisation manuelle importe aussi les templates créés directement dans
  AyosPush (contenu relu depuis les composants Meta).

### Règles vérifiées avant d'appeler AyosPush

Ce qu'AyosPush ou Meta refuseraient est signalé en 422, champ par champ :
langues `fr`/`en` uniquement ; variables numérotées `{{1}}`, `{{2}}`… sans trou,
chacune avec un exemple (`sample_data`) ; pas de variable dans l'en-tête (AyosPush
ne remplit que celles du corps à l'envoi) ni dans le pied ; en-tête média déjà
téléversé ; boutons `QUICK_REPLY`, `URL` (adresse fixe : AyosPush ne transmet pas
l'exemple que Meta exige pour une URL dynamique, 2 max) et `PHONE_NUMBER` (format
international, 1 max) ; templates `AUTHENTICATION` avec une expiration de 1 à 90
minutes (Meta écrit le texte et le bouton « copier le code »).

Les erreurs AyosPush sont relayées avec leur motif (clé refusée, clé révoquée —
qu'AyosPush annonce pourtant en 200 —, permission manquante avec le scope en
cause, solde épuisé, limite de débit avec son délai). Elles ne sont jamais
renvoyées en 401 à la console, qui prendrait cela pour la fin de sa session.

L'**envoi** d'un template approuvé passe par l'API applicative :
`POST /v1/app/messages/whatsapp` (voir « API applicative »). Il demande en plus
les permissions AyosPush `templates.send` et `messages.read`, et un numéro
expéditeur choisi dans l'onglet WhatsApp de l'application.

Écarts constatés entre la documentation publique d'AyosPush et son code :
`variables` y est montré comme un objet JSON, mais le contrôleur le valide avec
la règle `json` de Laravel, qui n'accepte qu'une **chaîne** JSON (AninfPush
envoie donc `"{\"1\":\"Awa\"}"`) ; et l'option `file_url` décrite n'est pas lue.

## ✈️ Telegram

Chaque application a **son propre bot**, créé avec @BotFather ; AninfPush n'a pas de
bot commun. La console en donne la marche à suivre (*Applications › l'application ›
Telegram*) :

1. Dans Telegram, ouvrir **@BotFather**, envoyer `/newbot`, choisir le nom affiché
   puis un nom d'utilisateur finissant par `bot`.
2. Copier le token donné (`123456789:AAH…`) dans l'onglet Telegram, puis **Save and
   test** : AninfPush lit l'identité du bot (`getMe`) et vérifie qu'aucun webhook ne
   capte ses mises à jour (`getWebhookInfo`) — un bot déjà branché ailleurs est refusé.
3. Facultatif : un message de bienvenue, envoyé à chaque personne qui démarre le bot.
4. Diffuser le lien du bot (`https://t.me/<bot>`) ou des liens personnels (une
   référence, un usage), depuis la console ou l'API applicative.

Le token est chiffré en base et n'est jamais renvoyé (4 derniers caractères seulement) ;
il est masqué dans les messages d'erreur. Un token régénéré par `/revoke` garde le même
bot et ses abonnés ; le token d'un **autre** bot passe les abonnés en `blocked` jusqu'à
ce qu'ils démarrent le nouveau (leur référence est conservée).

**Qui a démarré le bot.** Pas de webhook entrant à exposer : `php artisan telegram:poll`
(planifiée **chaque minute**, `--business=ID` pour une seule application) lit les mises
à jour de chaque bot connecté (`getUpdates`). `/start` abonne (avec la référence de
l'invitation si le lien en portait une), `/stop` ou le blocage du bot désabonne. Seuls
les chats privés comptent. Le bouton *Check now* de la console fait la même lecture
immédiatement.

**Templates** (`/api/v1/telegram-templates`, modifiables contrairement à WhatsApp) :
texte avec variables `{{ name }}`, en HTML Telegram (`b`, `i`, `u`, `s`, `a href`,
`code`, `pre`, `blockquote`, `tg-spoiler`) ou en texte brut, jusqu'à 10 boutons-liens
(`https://`, `http://` ou `tg://`, variables acceptées). Ce que Telegram refuserait est
signalé en 422 à l'enregistrement : balise inconnue, balise non fermée ou mal imbriquée,
`<` ou `&` isolé, `<span>` hors spoiler, lien sans `href`, plus de 4096 caractères.

**Pièces jointes.** `media_type` (`photo`, `video`, `document`) et `media_source` :

| `media_source` | Fichier | Stockage |
|---|---|---|
| `file` | Le même pour chaque message (bannière, guide PDF), téléversé par `POST /api/v1/telegram-templates/{id}/media` | Sur le serveur (`TELEGRAM_MEDIA_DISK`, `storage/app/private` par défaut) |
| `url` | Propre à chaque message : `media_url` dans l'appel d'envoi | Aucun : Telegram télécharge le lien |

- Fichiers acceptés : photo JPEG, PNG ou WebP ≤ 10 Mo (largeur + hauteur ≤ 10000 px,
  rapport ≤ 20) ; vidéo MP4 ≤ 25 Mo ; document PDF, Office, OpenDocument, ZIP, texte ou
  image ≤ 25 Mo (Telegram accepte 50 Mo, les limites d'envoi du serveur 25).
- Le fichier n'est pas public : la console le lit par `GET …/media` avec la session.
- Le premier message l'envoie à Telegram (délai `TELEGRAM_UPLOAD_TIMEOUT`, 45 s) ; les
  suivants réutilisent l'identifiant que Telegram lui a donné pour ce bot. Si Telegram
  l'a oublié, le fichier repart une fois de plus.
- Remplacer le fichier, changer de type ou de source, retirer la pièce jointe
  (`DELETE …/media`) ou supprimer le template efface le fichier stocké. Une copie
  (« Duplicate ») a son propre fichier. Un export JSON/TXT ne contient pas le fichier :
  il faut le joindre de nouveau après un import.

> **Docker : `storage/app` doit être un volume.** Sans volume, les fichiers des
> templates disparaissent à chaque nouveau conteneur (rebuild, `up --force-recreate`)
> et les messages qui les utilisent échouent (« The file of the Telegram template is
> missing »). `docker-compose.yml` monte le volume `app_files` sur
> `/var/www/html/storage/app` pour `app`, `horizon` (qui envoie les fichiers) et `cron`.
> En production, même montage persistant, partagé par le conteneur PHP et Horizon et
> accessible en écriture à `www-data` — ou `TELEGRAM_MEDIA_DISK` sur un stockage objet.

| Endpoint console | Rôle |
|---|---|
| `GET/PUT/DELETE /api/v1/businesses/{id}/telegram-settings` | Bot de l'application |
| `POST /api/v1/businesses/{id}/telegram-settings/test` | Vérifie le token et le webhook |
| `POST /api/v1/businesses/{id}/telegram-settings/poll` | Lit les mises à jour maintenant |
| `GET /api/v1/businesses/{id}/telegram-subscribers` | Abonnés (`status`, `search`) |
| `DELETE /api/v1/businesses/{id}/telegram-subscribers/{id}` | Oublie un abonné |
| `GET/POST /api/v1/businesses/{id}/telegram-invitations` | Liens d'invitation |
| `POST/GET/DELETE /api/v1/telegram-templates/{id}/media` | Fichier d'un template (joindre, lire, retirer) |

## 📦 Template export / import

Templates move between applications as portable documents. Ids, business, usage counters and
approval state are never carried over, and an import always lands as an **inactive draft**.

```bash
# One template, as JSON or TXT
GET  /api/v1/templates/{email|sms|whatsapp|telegram}/{id}/export?format=json   # add &download=0 for an inline body

# A copy in the same application, as an inactive draft ("<name> copy", "<name>_copy" for WhatsApp)
POST /api/v1/templates/{email|sms|whatsapp|telegram}/{id}/duplicate

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
