# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12 + React 19 + Inertia.js starter kit with TypeScript, Tailwind CSS 4, and Laravel Fortify for authentication. The project uses:
- **Backend**: Laravel 12 with Inertia.js for server-side rendering
- **Frontend**: React 19 with TypeScript, Vite, and Tailwind CSS 4
- **UI Components**: Radix UI primitives with custom shadcn/ui-style components
- **Testing**: Pest PHP for backend tests
- **Routing**: Laravel Wayfinder for type-safe routing between backend and frontend
- **Auth**: Laravel Fortify with two-factor authentication support

## Development Commands

### Setup
```bash
composer setup
```
Runs the full setup: installs dependencies, copies .env, generates app key, runs migrations, and builds frontend assets.

### Development Server
```bash
composer dev
```
Starts all development services concurrently:
- Laravel development server (php artisan serve)
- Queue worker
- Log viewer (pail)
- Vite dev server with HMR

### Development with SSR
```bash
composer dev:ssr
```
Runs development mode with server-side rendering enabled.

### Testing
```bash
composer test
# Or directly:
php artisan test

# Run specific test file:
php artisan test tests/Feature/Auth/RegistrationTest.php

# Run specific test method:
php artisan test --filter test_registration_screen_can_be_rendered
```

### Linting & Formatting
```bash
npm run lint          # Run ESLint with auto-fix
npm run format        # Format code with Prettier
npm run format:check  # Check formatting without modifying
npm run types         # Type check TypeScript without emitting
```

### Building
```bash
npm run build         # Build frontend assets
npm run build:ssr     # Build with SSR support
```

### Code Quality
```bash
./vendor/bin/pint     # Format PHP code (Laravel Pint)
```

## Architecture

### Frontend Structure

**Inertia Pages** (`resources/js/pages/`): Each file corresponds to a server-rendered page. Pages are resolved dynamically via `resolvePageComponent` in `app.tsx`.

**Layouts** (`resources/js/layouts/`):
- `app/` - Main authenticated application layout
- `auth/` - Authentication pages layout
- `settings/` - Settings pages layout with sidebar navigation

**Components** (`resources/js/components/ui/`): Reusable UI components based on Radix UI primitives (button, dialog, dropdown-menu, input, etc.)

**Type-Safe Routing**: Laravel Wayfinder generates TypeScript route definitions from Laravel routes. The generated actions are in `resources/js/actions/` and mirror the backend controller structure. Import routes like:
```typescript
import { ProfileController } from '@/actions/App/Http/Controllers/Settings';
```

**Shared Data**: Global props are configured in `HandleInertiaRequests` middleware and include: `name`, `quote`, `auth.user`, `sidebarOpen`.

### Backend Structure

**Controllers** (`app/Http/Controllers/`): Organized by feature (e.g., Settings/)

**Middleware**:
- `HandleInertiaRequests` - Shares global data with all Inertia pages
- `HandleAppearance` - Manages theme/appearance preferences

**Routes**:
- `routes/web.php` - Public and authenticated routes
- `routes/settings.php` - Settings-related routes (included in web.php)

**Authentication**: Laravel Fortify handles authentication, registration, password reset, email verification, and two-factor authentication. Custom actions are in `app/Actions/Fortify/`.

### Database

Uses SQLite by default. Migrations are in `database/migrations/` and include:
- User authentication tables
- Cache, jobs, and queue tables
- Two-factor authentication columns

Run migrations: `php artisan migrate`

### Testing

Tests use Pest PHP with SQLite in-memory database. Test structure:
- `tests/Feature/` - Feature tests including auth flows and settings
- `tests/Unit/` - Unit tests

PHPUnit config automatically sets testing environment variables.

## Key Patterns

**React Compiler**: Enabled via `babel-plugin-react-compiler` for automatic memoization.

**Inertia.js**: Server-side props are passed to React components. Use `Inertia.render('page-name', props)` in Laravel controllers.

**Wayfinder Forms**: Type-safe form actions generated from Laravel routes. Enable with `formVariants: true` in vite.config.ts.

**Theme System**: Dark/light mode handled via `use-appearance` hook, initialized on app load.

## Environment

Copy `.env.example` to `.env` and configure:
- Database connection (default: SQLite)
- Mail settings for authentication emails
- App name, URL, and environment settings
