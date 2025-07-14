# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TX Work Order is a Laravel-based work order management system designed for property management companies. It handles work orders, vendor management, tenant communications, and integrates with external services like Twilio (SMS), Asana (task management), and PropertyWare.

## Tech Stack

- **Backend**: Laravel 12.x, PHP 8.2+
- **Frontend**: Vue.js 3 with Inertia.js
- **UI**: Radix Vue components, Tailwind CSS
- **Database**: MySQL (production), SQLite (local development)
- **Authentication**: Laravel Jetstream with Fortify
- **Permissions**: Spatie Laravel Permission

## Essential Commands

### Development
```bash
# Start all development services (server, queue, logs, vite)
composer run dev

# Or run services individually:
php artisan serve              # Start development server
php artisan queue:listen       # Start queue worker
php artisan pail              # Watch application logs
npm run dev                   # Start Vite dev server
```

### Build & Testing
```bash
# Build frontend assets
npm run build

# Run PHP tests
php artisan test
php artisan test --filter TestName

# Code formatting (PHP)
./vendor/bin/pint
./vendor/bin/pint --test      # Check without fixing
```

### Database
```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan db:seed
```

## Architecture Overview

### Directory Structure
- `app/Http/Controllers/` - HTTP controllers organized by feature area
- `app/Models/` - Eloquent models with relationships defined
- `app/Services/` - Business logic services (Asana, Twilio, PropertyWare integrations)
- `resources/js/Pages/` - Vue.js page components for Inertia.js
- `resources/js/Components/` - Reusable Vue components
- `resources/js/Components/ui/` - UI components based on Radix Vue
- `routes/web.php` - Main application routes
- `routes/api.php` - API endpoints

### Key Models & Relationships
- **WorkOrder** - Central entity, belongs to Building, has many Tasks
- **Building** - Has many Units, WorkOrders, belongs to Owner
- **User** - Can be Owner, Tenant, or Vendor (role-based)
- **Task** - Belongs to WorkOrder, can sync with Asana
- **Vendor** - Handles work order assignments

### External Service Integrations
- **Twilio** (`app/Services/TwilioService.php`) - SMS notifications
- **Asana** (`app/Services/AsanaService.php`) - Task synchronization
- **PropertyWare** (`app/Services/PropertyWareService.php`) - Property management sync

### Frontend Architecture
- Uses Inertia.js for SPA-like experience without API
- Page components receive props from Laravel controllers
- Shared layout components in `resources/js/Layouts/`
- Form handling with vee-validate and zod schemas
- Calendar integration using qalendar package

## Development Guidelines

### When Adding Features
1. Follow Laravel conventions for controllers, models, and migrations
2. Use existing UI components from `resources/js/Components/ui/`
3. Maintain consistent Tailwind CSS styling patterns
4. Add appropriate permissions using Spatie Permission package

### Testing Approach
- Feature tests in `tests/Feature/` for HTTP endpoints
- Unit tests in `tests/Unit/` for services and models
- Test database: `txrenter_test` (MySQL)
- Always run tests before committing major changes

### Common Patterns
- Form requests for validation (`app/Http/Requests/`)
- Service classes for external integrations
- Vue composables for shared frontend logic
- Inertia shared data via `HandleInertiaRequests` middleware