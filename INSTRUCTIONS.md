# King AI Backend API Documentation

## Table of Contents
- [Project Structure](#project-structure)
- [API Endpoints](#api-endpoints)
- [Authentication](#authentication)
- [API Usage Examples](#api-usage-examples)
- [Environment Variables](#environment-variables)
- [Installation](#installation)
- [Testing](#testing)

## Project Structure

### Directory Structure
```
king-ai-backend/
├── app/                    # Application core
│   ├── Console/            # Artisan commands
│   ├── Contracts/          # Interfaces
│   ├── Events/             # Event classes
│   ├── Exceptions/         # Custom exceptions
│   ├── Http/               # Controllers, Middleware, Requests
│   ├── Jobs/               # Queue jobs
│   ├── Listeners/          # Event listeners
│   ├── Models/             # Eloquent models
│   ├── Providers/          # Service providers
│   ├── Services/           # Business logic services
│   ├── Strategies/         # Strategy pattern implementations
│   └── Traits/             # Reusable traits
├── config/                # Configuration files
├── database/              # Migrations, seeders, factories
├── public/                # Publicly accessible files
├── resources/             # Views, language files, etc.
├── routes/                # Route definitions
├── storage/               # Logs, compiled views, caches
└── tests/                 # Test files
```

### Database Models
- `User` - Application users
- `AiFeature` - AI features configuration
- `AiFeatureConfig` - Configuration for AI features
- `AiJob` - Background AI processing jobs
- `Template` - Content templates
- `TemplateCategory` - Categories for templates
- `TemplateContent` - Content for templates
- `DataSource` - Data sources for templates
- `Device` - User devices
- `ViduTemplate` - Template variations

## API Endpoints

### Authentication
- `POST /api/auth/register` - Register a new user
- `POST /api/auth/login` - Login user
- `POST /api/auth/logout` - Logout user (requires authentication)
- `POST /api/register-device` - Register a user device (requires authentication)

### Users
- `GET /api/users` - List all users (admin only)
- `GET /api/users/{id}` - Get user by ID
- `POST /api/users` - Create new user
- `PUT /api/users/{id}` - Update user
- `DELETE /api/users/{id}` - Delete user

### Categories
- `GET /api/categories` - List all categories
- `POST /api/categories` - Create new category
- `GET /api/categories/{id}` - Get category by ID
- `PUT /api/categories/{id}` - Update category
- `DELETE /api/categories/{id}` - Delete category
- `DELETE /api/categories/{template_category_id}/data-sources/{datasource_id}` - Detach data source from category

### Templates
- `GET /api/templates` - List all templates
- `POST /api/templates` - Create new template
- `GET /api/templates/{id}` - Get template by ID
- `PUT /api/templates/{id}` - Update template
- `DELETE /api/templates/{id}` - Delete template

### AI Features
- `GET /api/ai-features` - List all AI features
- `POST /api/ai-features` - Create new AI feature
- `GET /api/ai-features/{id}` - Get AI feature by ID
- `PUT /api/ai-features/{id}` - Update AI feature
- `DELETE /api/ai-features/{id}` - Delete AI feature

### AI Generation
- `POST /api/generate/{feature}` - Generate content using AI
- `GET /api/status/{jobId}` - Check status of AI generation job

### File Upload
- `POST /api/upload` - Upload files

## API Usage Examples

### User Registration
**Request:**
```http
POST /api/auth/register
Content-Type: application/json

{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "yourpassword",
    "password_confirmation": "yourpassword"
}
```

**Response (201 Created):**
```json
{
    "user": {
        "name": "John Doe",
        "email": "john@example.com",
        "updated_at": "2025-10-22T11:30:00.000000Z",
        "created_at": "2025-10-22T11:30:00.000000Z",
        "id": 1
    },
    "token": "1|XyZ123..."
}
```

### Generate AI Content
**Request:**
```http
POST /api/generate/text-summary
Authorization: Bearer your_token_here
Content-Type: application/json

{
    "text": "Long text to be summarized...",
    "max_length": 200
}
```

**Response (202 Accepted):**
```json
{
    "job_id": "abc123-def456-ghi789",
    "status": "processing",
    "message": "Request is being processed"
}
```

### Check Job Status
**Request:**
```http
GET /api/status/abc123-def456-ghi789
Authorization: Bearer your_token_here
```

**Response (200 OK):**
```json
{
    "status": "completed",
    "result": "This is the summarized text..."
}
```

## Environment Variables

Copy `.env.example` to `.env` and configure the following variables:

```
APP_NAME=KingAI
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=king_ai
DB_USERNAME=root
DB_PASSWORD=
```

## Installation

1. Clone the repository
2. Install dependencies: `composer install`
3. Copy `.env.example` to `.env` and configure
4. Generate app key: `php artisan key:generate`
5. Run migrations: `php artisan migrate`
6. Start the server: `php artisan serve`

## Testing

Run the test suite:

```bash
php artisan test
```
