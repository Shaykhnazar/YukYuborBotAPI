
# Project Overview

This project is a Laravel-based API named **PostLink API**. It serves as a backend for a messaging and delivery platform, likely integrated with a Telegram bot or a web application. The API handles user authentication, chat functionalities, delivery and send requests, location services, and integration with Google Sheets for data management.

## Key Technologies

*   **Backend:** Laravel 11, PHP 8.2
*   **Database:** PostgreSQL
*   **Caching:** Redis
*   **Frontend:** Vite, Tailwind CSS
*   **Real-time:** Laravel Reverb (WebSocket server)
*   **Authentication:** Telegram WebApp Authentication
*   **Dependencies:**
    *   `guzzlehttp/guzzle`: HTTP client
    *   `revolution/laravel-google-sheets`: Google Sheets integration
    *   `smskin/laravel-tgwebapp-auth`: Telegram WebApp authentication
    *   `predis/predis`: Redis client

## Architecture

The application follows a standard Laravel project structure.

*   **Controllers:** Handle incoming API requests. Key controllers include `ChatController`, `DeliveryController`, `SendRequestController`, `LocationController`, and `GoogleSheetsController`.
*   **Models:** Define the database schema and relationships. Notable models are `User`, `Chat`, `DeliveryRequest`, `SendRequest`, `Location`, and `Response`.
*   **Services:** Contain business logic. `GoogleSheetsService`, `TelegramNotificationService`, and `Matcher` are key services.
*   **Routes:** API endpoints are defined in `routes/api.php`.
*   **Jobs:** Asynchronous tasks like updating Google Sheets are handled by jobs.
*   **Providers:** `AppServiceProvider` registers custom services.

# Building and Running

1.  **Install Dependencies:**
    ```bash
    composer install
    npm install
    ```

2.  **Environment Setup:**
    ```bash
    cp .env.example .env
    ```
    *   Configure database, Redis, and other services in the `.env` file.

3.  **Generate Application Key:**
    ```bash
    php artisan key:generate
    ```

4.  **Run Migrations:**
    ```bash
    php artisan migrate
    ```

5.  **Start Development Servers:**
    *   **Backend:**
        ```bash
        php artisan serve
        ```
    *   **Frontend (Vite):**
        ```bash
        npm run dev
        ```
    *   **Real-time Server (Reverb):**
        ```bash
        php artisan reverb:start
        ```

# Development Conventions

*   **Testing:** The project uses PHPUnit for testing. Run tests with:
    ```bash
    vendor/bin/phpunit
    ```
*   **API Authentication:** API routes are protected by Telegram WebApp authentication middleware (`tg.init` and `auth:tgwebapp`). For local development, a separate middleware (`tg.init.dev`) is used to bypass authentication.
*   **Google Sheets:** The application uses the `revolution/laravel-google-sheets` package to interact with Google Sheets. The `GoogleSheetsService` encapsulates the logic for this integration.
*   **Real-time Communication:** Laravel Reverb is used for real-time features like chat. The broadcasting routes are defined in `routes/channels.php` and the authentication for private and presence channels is handled in `routes/api.php`.
*   **Code Style:** While not explicitly defined, the code follows standard Laravel conventions. It is recommended to use a linter like `laravel/pint` to maintain a consistent code style.
