# PostLink API

Welcome to the PostLink API documentation!

## Installation

1. **Clone the Repository**

   ```bash
   git clone <repository-url>
   cd <repository-directory>
   ```

2. **Install PHP Dependencies**

   Ensure you have PHP 8.1 or later installed. Then, install the composer dependencies:

   ```bash
   composer install
   ```

3. **Install JavaScript Dependencies**

   If the project includes front-end assets, install the Node.js dependencies using npm:

   ```bash
   npm install
   ```

4. **Environment Setup**

   Copy the example environment file and configure your environment variables:

   ```bash
   cp .env.example .env
   ```

   Open the `.env` file and set the necessary configurations for PostgreSQL and Redis, as well as other environment-specific settings.

5. **Generate Application Key**

   ```bash
   php artisan key:generate
   ```

6. **Run Migrations**

   Set up the database by running the migrations:

   ```bash
   php artisan migrate
   ```

## Configuration

- **Database:**  
  Configure your PostgreSQL database credentials in the `.env` file.

- **Queue:**  
  The application uses Redis for queue management. Make sure to set the relevant queue connection in `.env`.

- **Other Services:**  
  The project integrates several composer packages (e.g., `guzzlehttp/guzzle`, `monolog/monolog`, `laravel/tinker` among others) and JavaScript packages (e.g., `axios`, `tailwindcss`, `vite`). Refer to their respective documentation for any advanced configuration or usage.

## Usage

- **Development Server:**  
  To start the Laravel development server, run:

  ```bash
  php artisan serve
  ```

- **Frontend Development:**  
  If you are working with the front-end assets, run the Vite development server:

  ```bash
  npm run dev
  ```

- **Laravel Sail:**  
  If you prefer using Laravel Sail, make sure Docker is installed on your system and then start the containers:

  ```bash
  ./vendor/bin/sail up
  ```

## Testing

The project uses PHPUnit for testing. Run the tests with the following command:

```bash
vendor/bin/phpunit
```

## Technologies Used

- **Backend:**
    - Laravel Framework (v10.x)
    - PHP 8.2
    - PostgreSQL
    - Redis

- **Frontend:**
    - JavaScript
    - Vite
    - Tailwind CSS
    - Laravel Vite Plugin
