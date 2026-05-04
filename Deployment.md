# Vidio King — Deployment Guide

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Environment Variables](#environment-variables)
- [Deployment: Docker (Recommended)](#deployment-docker-recommended)
- [Deployment: VPS (Bare Metal)](#deployment-vps-bare-metal)
- [Post-Deployment Setup](#post-deployment-setup)
- [Queue Workers](#queue-workers)
- [Filament Admin Panel](#filament-admin-panel)
- [Configuring AI Vendors](#configuring-ai-vendors)
- [SSL / HTTPS](#ssl--https)
- [CORS Configuration](#cors-configuration)
- [Storage and File Uploads](#storage-and-file-uploads)
- [Firebase Push Notifications](#firebase-push-notifications)
- [Scheduled Tasks](#scheduled-tasks)
- [Monitoring and Logs](#monitoring-and-logs)
- [Troubleshooting](#troubleshooting)
- [Architecture Reference](#architecture-reference)
- [API Endpoints Reference](#api-endpoints-reference)

---

## Overview

Vidio King is a Laravel 12 backend that acts as middleware between mobile/web clients and AI video generation vendors (Kling, Vidu). It handles authentication, queued job processing, vendor-agnostic strategy routing, local asset storage, Firebase push notifications, and a Filament admin panel for configuration management.

Stack: PHP 8.2, Laravel 12, MySQL 8, Nginx, Filament 3, Sanctum, Laravel Queues

---

## Requirements

PHP 8.2+ with extensions: pdo_mysql, mbstring, zip, exif, pcntl, opcache, intl, gd, fileinfo, redis. MySQL 8.0+. Nginx. Composer 2+. PM2 or Supervisor for queue worker management. Redis is optional (default uses the database driver for queues and cache).

---

## Environment Variables

Create a `.env` file from the template below. Every value marked CHANGE_ME must be set before deployment.

```env
# Application
APP_NAME=VidioKing
APP_ENV=production
APP_KEY=                              # Run: php artisan key:generate
APP_DEBUG=false
APP_URL=https://king-ai.nxtgenitpark.com    # CHANGE_ME

APP_TIMEZONE=UTC
APP_LOCALE=en

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1                     # Use 'mysql' for Docker
DB_PORT=3306
DB_DATABASE=king_ai                   # CHANGE_ME
DB_USERNAME=king_ai_user              # CHANGE_ME
DB_PASSWORD=your_secure_password      # CHANGE_ME

# Queue
QUEUE_CONNECTION=database
DB_QUEUE=default

# Cache / Session
CACHE_STORE=file
SESSION_DRIVER=file
SESSION_LIFETIME=120

# AI Vendor Credentials
KLING_ACCESS_KEY=your_kling_access_key      # CHANGE_ME
KLING_SECRET_KEY=your_kling_secret_key      # CHANGE_ME
VIDU_API_KEY=your_vidu_api_key              # CHANGE_ME

# Firebase Push Notifications
PUSH_PROVIDER=fcm

# Default AI Vendor
AI_DEFAULT_VENDOR=kling

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=info

# Filesystem
FILESYSTEM_DISK=local
```

Never commit `.env` to git.

---

## Deployment: Docker (Recommended)

### 1. Clone and configure

```bash
git clone git@your-repo.git /var/www/kingai
cd /var/www/kingai
cp .env.example .env
nano .env                             # Set all values, DB_HOST=mysql for Docker
```

### 2. Build and start

```bash
docker compose build
docker compose up -d
```

This starts 3 containers: `king_ai_backend` (PHP 8.2-FPM), `king_ai_nginx` (Nginx), `king_ai_mysql` (MySQL 8.0).

### 3. Initialize

```bash
docker compose exec app composer install --optimize-autoloader --no-dev
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan storage:link
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

### 4. Set permissions

```bash
docker compose exec app chmod -R 775 storage bootstrap/cache
docker compose exec app chown -R www:www storage bootstrap/cache
```

### 5. Start queue worker

```bash
docker compose exec -d app php artisan queue:work --queue=ai_generation,ai_polling,notifications --tries=3 --timeout=300
```

### 6. Verify

```bash
docker compose ps
curl https://your-domain.com/up
docker compose exec app php -i | grep -E "upload_max_filesize|post_max_size"
# Should show: upload_max_filesize=128M, post_max_size=130M
```

---

## Deployment: VPS (Bare Metal)

### 1. Install dependencies

```bash
sudo apt update && sudo apt install -y \
    nginx \
    php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-zip php8.2-exif \
    php8.2-pcntl php8.2-opcache php8.2-intl php8.2-gd php8.2-curl \
    php8.2-xml php8.2-redis \
    mysql-server composer unzip git curl

# PM2 for queue workers
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
```

### 2. Configure PHP upload limits

Edit `/etc/php/8.2/fpm/php.ini`:

```ini
upload_max_filesize = 128M
post_max_size = 130M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
```

Then `sudo systemctl restart php8.2-fpm`.

### 3. Configure Nginx

Create `/etc/nginx/sites-available/kingai`:

```nginx
server {
    listen 80;
    server_name king-ai.nxtgenitpark.com;
    root /var/www/kingai/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;
    client_max_body_size 128M;

    location /api {
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' 'https://king-ai-gamma.vercel.app';
            add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
            add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With, Accept';
            add_header 'Access-Control-Allow-Credentials' 'true';
            add_header 'Content-Length' '0';
            add_header 'Content-Type' 'text/plain charset=UTF-8';
            return 204;
        }
        try_files $uri $uri/ /index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_connect_timeout 300s;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 16 16k;
        add_header 'Access-Control-Allow-Origin' 'https://king-ai-gamma.vercel.app' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/kingai /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 4. Deploy application

```bash
cd /var/www/kingai
git pull origin main
composer install --optimize-autoloader --no-dev
cp .env.example .env && nano .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 5. Start queue workers

```bash
pm2 start "php /var/www/kingai/artisan queue:work --queue=ai_generation,ai_polling,notifications --tries=3 --timeout=300" --name kingai-worker
pm2 save
pm2 startup
```

---

## Post-Deployment Setup

**Database seeding** creates the admin user (`admin@king-ai.com` / `12345678`), default data sources, and template categories. Change the admin password immediately after first login.

**Storage symlink** is required for serving uploaded files and generated assets: `php artisan storage:link`. This creates `public/storage` pointing to `storage/app/public`.

**Firebase service account** JSON must be placed at `storage/app/vidio-king-ai-firebase.json` for push notifications to work.

---

## Queue Workers

The application uses 3 queue channels:

`ai_generation` handles `AiGenerationJob` — sends generation requests to vendor APIs. `ai_polling` handles `AiPollingJob`, `AssetDownloadJob`, and `WebhookTimeoutJob` — polls vendor status, downloads assets, and handles callback timeouts. `notifications` handles push notification delivery.

For better throughput, run separate workers per queue:

```bash
pm2 start "php artisan queue:work --queue=ai_generation --tries=3 --timeout=300" --name kingai-generation
pm2 start "php artisan queue:work --queue=ai_polling --tries=3 --timeout=300" --name kingai-polling
pm2 start "php artisan queue:work --queue=notifications --tries=3 --timeout=60" --name kingai-notifications
pm2 save
```

**Critical:** Queue workers cache code in memory. After any deploy, always run `pm2 restart all` or `php artisan queue:restart`. Failure to restart is the number one cause of old behavior persisting after deployment.

---

## Filament Admin Panel

URL: `https://your-domain.com/admin`. Default login: `admin@king-ai.com` / `12345678`.

The admin panel manages data sources, AI features, AI feature configs (with vendor presets), templates (with metadata, input fields, output options), template categories, users, and vendor status mappings. When creating an AI Feature Config, use the "Load Preset" button for quick Kling configuration.

---

## Configuring AI Vendors

To add a new vendor: create a Data Source in admin, add credentials to `config/vendor-credentials.php` and `.env`, create an AI Feature Config with the vendor's endpoint/auth/schema/response mapping, create a Vendor Status Mapping, then run `php artisan config:cache`.

Completion modes: `polling` (system checks vendor status periodically — use for Vidu) and `callback` (vendor POSTs to `POST /api/webhook/{jobId}` when done — use for Kling). The `{{callback_url}}` placeholder in request body templates is auto-replaced with your webhook URL.

---

## SSL / HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d king-ai.nxtgenitpark.com
sudo systemctl reload nginx
```

For Docker, use a reverse proxy like Traefik or mount certificates into the Nginx container.

---

## CORS Configuration

CORS is handled entirely by Nginx, not Laravel. Laravel's CORS middleware is disabled for API routes to avoid duplicate headers. The allowed origin (`https://king-ai-gamma.vercel.app`) is set in Nginx in both the OPTIONS preflight block and the PHP fastcgi block. To change it, update both locations in the Nginx config.

---

## Storage and File Uploads

Storage layout under `storage/app/public/`: `templates/` for admin-uploaded sample images and preview videos, `generations/{user_id}/{job_id}/` for downloaded vendor assets, and `livewire-tmp/` for temporary Filament uploads.

All 3 layers must agree on upload limits: Nginx `client_max_body_size` at 128M, PHP `upload_max_filesize` at 128M and `post_max_size` at 130M, Livewire `max_file_size` at 100MB, and app validation at 100MB (102400 KB).

---

## Firebase Push Notifications

Notifications fire when AI generation completes or fails, and when templates or categories are created/updated/deleted. Place the Firebase service account JSON at `storage/app/vidio-king-ai-firebase.json`. Mobile clients register FCM tokens via `POST /api/register-device`. Invalid tokens are auto-deactivated on delivery failure.

---

## Scheduled Tasks

The schedule is registered in `bootstrap/app.php` via `->withSchedule(...)` (Laravel 12 style — `app/Console/Kernel.php` is not used and should not be re-introduced):

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('generations:cleanup --days=30')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer();
})
```

Enable the cron on the VPS so `schedule:run` fires every minute:

```
* * * * * cd /var/www/kingai && php artisan schedule:run >> /dev/null 2>&1
```

The cleanup command deletes generation assets older than the specified days, updates job records to mark assets as cleaned up, and removes empty directories. Use `--dry-run` to preview.

To list everything that's scheduled: `php artisan schedule:list`. To verify the cron is actually firing: `php artisan schedule:test`.

---

## Monitoring and Logs

Application logs: `tail -f storage/logs/laravel.log`. Queue status: `pm2 status` and `pm2 logs`. Failed jobs: `php artisan queue:failed`, retry with `php artisan queue:retry all`.

Key things to monitor: queue backlog growth (workers may be overloaded), failed jobs, disk space (generation assets accumulate), vendor API errors (rate limits, timeouts), and webhook timeout events.

---

## Troubleshooting

**Generation stuck in "processing"** — queue worker not running or not restarted after deploy. Check `pm2 status`, then `pm2 restart all`.

**File uploads fail in Filament** — PHP/Nginx upload limits too low. Check `php -i | grep upload_max_filesize` (must be 128M+) and Nginx `client_max_body_size`. Ensure `storage/app/livewire-tmp/` is writable.

**Vendor API returns 429** — rate limited. The system auto-retries with backoff. If persistent, check your vendor plan limits.

**CORS errors** — ensure Nginx CORS headers match your frontend domain exactly and check for duplicate headers.

**Webhook not receiving callbacks** — verify the route is accessible (`curl -X POST https://your-domain.com/api/webhook/test`), check `completion_mode` is `callback`, verify `APP_URL` is correct, and check firewall rules.

**Config changes not taking effect** — run `php artisan config:cache`, `php artisan route:cache`, and `pm2 restart all`.

**`env()` returning null** — never use `env()` in application code, only in config files. After `config:cache`, `env()` returns null everywhere except config files. Use `config()` instead.

---

## Architecture Reference

Request flow for AI generation: client calls `POST /api/generate/{feature}`, AiController validates and dispatches `AiGenerationJob` to the `ai_generation` queue. The job resolves the vendor config, builds the request from template placeholders, calls the vendor API, extracts the task_id, and creates the `ai_jobs` record. For polling mode, it dispatches `AiPollingJob` with progressive backoff. For callback mode, it dispatches `WebhookTimeoutJob` as a safety net and waits for the vendor POST. On completion (from either path), `AssetDownloadJob` downloads assets to local storage, replaces vendor URLs, then fires `AiJobCompletedEvent` which triggers the push notification.

Key design principles: configuration-driven (vendor integrations via admin panel, no code changes), vendor-agnostic (`GenericPollStrategy` for all vendors), same normalization path (polling and webhook both use `GenericPollStrategy::parseResponse()`), graceful degradation (asset download failure doesn't block notifications), and snapshot at generation (template credit_cost captured when job is created).

---

## API Endpoints Reference

### Public (no auth)
```
POST   /api/auth/register               Register new user
POST   /api/auth/login                  Login, returns bearer token
POST   /api/webhook/{jobId}             Vendor callback endpoint
```

### Authenticated (Bearer token)
```
POST   /api/auth/logout                 Logout
POST   /api/register-device             Register FCM device token

GET    /api/users                       List users
GET    /api/users/{id}                  Get user
POST   /api/users                       Create user
PUT    /api/users/{id}                  Update user
DELETE /api/users/{id}                  Delete user

GET    /api/categories                  List categories with templates
POST   /api/categories                  Create category
GET    /api/categories/{id}             Get category
PUT    /api/categories/{id}             Update category
DELETE /api/categories/{id}             Delete category
DELETE /api/categories/{id}/data-sources/{dsId}  Detach data source

GET    /api/templates                   List templates (filterable)
POST   /api/templates                   Create template with metadata
GET    /api/templates/{id}              Get template with resolved metadata
PUT    /api/templates/{id}              Update template
DELETE /api/templates/{id}              Delete template

GET    /api/ai-features                 List AI features
POST   /api/ai-features                 Create AI feature
GET    /api/ai-features/{id}            Get AI feature with configs
PUT    /api/ai-features/{id}            Update AI feature
DELETE /api/ai-features/{id}            Delete AI feature

GET    /api/ai-feature-configs          List configs (filterable)
GET    /api/ai-feature-configs/{id}     Get config detail
POST   /api/ai-feature-configs/static-token    Create static token config
POST   /api/ai-feature-configs/jwt             Create JWT config
PUT    /api/ai-feature-configs/{id}/static-token  Update static token
PUT    /api/ai-feature-configs/{id}/jwt           Update JWT
PATCH  /api/ai-feature-configs/{id}/switch-auth   Switch auth type
DELETE /api/ai-feature-configs/{id}               Delete config

POST   /api/generate/{feature}          Queue AI generation (accepts template_id)
GET    /api/status/{jobId}              Check generation status

POST   /api/upload                      Upload file (image/cover/video)

GET    /api/history                     List generation history (paginated)
GET    /api/history/{jobId}             Generation detail with template metadata
GET    /api/history/{jobId}/download    Download asset file
DELETE /api/history/{jobId}             Hide from history

GET    /api/task-status                 List vendor status mappings
POST   /api/task-status                 Create mapping
GET    /api/task-status/{id}            Get mapping
PUT    /api/task-status/{id}            Update mapping
DELETE /api/task-status/{id}            Delete mapping
```