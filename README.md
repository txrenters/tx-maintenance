# TX Work Order Management System

A Laravel-based work order management system designed for property management companies, handling work orders, vendor management, tenant communications, and third-party integrations.

## Features

- **Work Order Management**: Create, track, and manage maintenance requests
- **Multi-tenant Architecture**: Support for multiple property owners and buildings
- **Vendor Management**: Assign and track vendor work assignments
- **Real-time Communications**: SMS notifications via Twilio integration
- **Task Synchronization**: Asana integration for task management
- **Property Management**: PropertyWare integration for property data sync
- **Role-based Access**: Owner, Tenant, and Vendor roles with permissions

## Tech Stack

- **Backend**: Laravel 12.x, PHP 8.2+
- **Frontend**: Vue.js 3 with Inertia.js
- **UI Components**: Radix Vue, Tailwind CSS
- **Database**: MySQL (production), SQLite (development)
- **Authentication**: Laravel Jetstream with Fortify
- **Authorization**: Spatie Laravel Permission

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- MySQL 8.0+ (production) or SQLite (development)
- Redis (optional, for queues)

## Installation

1. Clone the repository:
```bash
git clone [repository-url]
cd tx_work_order
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install JavaScript dependencies:
```bash
npm install
```

4. Copy the environment file and configure:
```bash
cp .env.example .env
php artisan key:generate
```

5. Configure your database in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=txrenter
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

6. Run database migrations and seeders:
```bash
php artisan migrate --seed
```

7. Build frontend assets:
```bash
npm run build
```

## Development

Start all development services:
```bash
composer run dev
```

This command runs:
- Laravel development server
- Queue worker for background jobs
- Vite dev server for hot module replacement
- Application log viewer

Alternatively, run services individually:
```bash
php artisan serve       # Start Laravel server
php artisan queue:listen # Start queue worker
npm run dev            # Start Vite dev server
php artisan pail       # Watch application logs
```

## Testing

Run the test suite:
```bash
php artisan test
```

Run specific tests:
```bash
php artisan test --filter TestName
```

## Code Style

Format PHP code using Laravel Pint:
```bash
./vendor/bin/pint
```

Check formatting without making changes:
```bash
./vendor/bin/pint --test
```

## Project Structure

```
tx_work_order/
├── app/
│   ├── Http/
│   │   ├── Controllers/    # HTTP controllers by feature
│   │   └── Requests/       # Form request validation
│   ├── Models/             # Eloquent models
│   └── Services/           # Business logic and integrations
├── resources/
│   ├── js/
│   │   ├── Pages/          # Inertia.js page components
│   │   ├── Components/     # Reusable Vue components
│   │   └── Layouts/        # Layout components
│   └── views/              # Blade templates
├── routes/
│   ├── web.php             # Web routes
│   └── api.php             # API routes
└── tests/
    ├── Feature/            # Feature tests
    └── Unit/               # Unit tests
```

## External Services

Configure these services in your `.env` file:

### Twilio (SMS)
```env
TWILIO_SID=your_account_sid
TWILIO_TOKEN=your_auth_token
TWILIO_FROM=your_twilio_number
```

### Asana (Task Management)
```env
ASANA_TOKEN=your_personal_access_token
ASANA_WORKSPACE_ID=your_workspace_id
```

### PropertyWare
```env
PROPERTYWARE_API_URL=your_api_url
PROPERTYWARE_API_KEY=your_api_key
```

## License

[Your License Here]

## Support

For support and questions, please [contact information].