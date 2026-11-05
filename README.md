# Manibela Backend

The backend API for **Manibela**, a vehicle rental marketplace that connects customers with vehicle owners.

Customers can discover and book cars or motorcycles, while vehicle owners can manage their listings and booking requests.

## Project Overview

Manibela is designed to support:

- Customer registration and authentication
- Vehicle owner accounts
- Car and motorcycle listings
- Vehicle search and filtering
- Booking requests and management
- Owner and customer dashboards
- Administrative management

## Technology Stack

| Technology | Purpose |
|---|---|
| Laravel 13 | Backend framework |
| PHP 8.4 | Programming language |
| MySQL 8.0 | Database |
| Docker | Development environment |
| Laravel Sanctum | API authentication (planned) |
| GitHub Actions | CI/CD (planned) |
| Render | Backend deployment (planned) |

## Architecture

```text
Next.js Frontend (Planned)
          |
          | REST API
          v
    Laravel Backend
          |
          v
       MySQL 8.0
```

## Requirements

- Docker Desktop
- Docker Compose
- Git

PHP and Composer are provided through Docker.

## Local Development Setup

### 1. Clone the repository

```bash
git clone YOUR_REPOSITORY_URL
cd manibela-backend
```

### 2. Configure environment variables

Copy the example environment file:

```bash
cp .env.example .env
```

For Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Configure the database settings:

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=manibela
DB_USERNAME=manibela_user
DB_PASSWORD=your_local_database_password
```

Use credentials matching your Docker Compose configuration.

### 3. Start Docker services

```bash
docker compose up -d --build
```

### 4. Generate the application key

```bash
docker compose exec app php artisan key:generate
```

### 5. Run database migrations

```bash
docker compose exec app php artisan migrate
```

### 6. Access the application

| Service | URL |
|---|---|
| Laravel Application | http://localhost:8000 |
| Health Check | http://localhost:8000/up |
| MySQL | localhost:3306 |

## Useful Commands

### Docker

```bash
# Start containers
docker compose up -d

# Stop containers
docker compose down

# View running containers
docker compose ps

# View application logs
docker compose logs -f app

# Access Laravel container
docker compose exec app bash
```

### Laravel

```bash
# Check Laravel version
docker compose exec app php artisan --version

# Check migration status
docker compose exec app php artisan migrate:status

# Run migrations
docker compose exec app php artisan migrate

# Clear configuration cache
docker compose exec app php artisan config:clear

# List available routes
docker compose exec app php artisan route:list
```

## Development Status

**Current Phase:** Backend Foundation

| Module | Status |
|---|---|
| Laravel initialization | Completed |
| Docker environment | Completed |
| MySQL configuration | Completed |
| Database migrations | Completed |
| Health check endpoint | Completed |
| GitHub repository | Pending |
| API authentication | Pending |
| User roles and permissions | Pending |
| Vehicle management API | Pending |
| Booking API | Pending |
| Automated testing | Pending |
| CI/CD pipeline | Pending |
| Production deployment | Pending |

## Development Guidelines

- Follow REST API conventions.
- Keep business logic organized and maintainable.
- Validate incoming requests.
- Apply authorization checks to protected resources.
- Write automated tests for important features.
- Never commit `.env` files, passwords, API keys, or other secrets.
- Do not deploy untested changes to production.

## Project Structure

```text
manibela-backend/
├── app/
├── bootstrap/
├── config/
├── database/
├── docker/
│   └── php/
│       └── Dockerfile
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── .dockerignore
├── .env.example
├── .gitignore
├── compose.yaml
├── artisan
├── composer.json
└── README.md
```

## License

Private project. All rights reserved.