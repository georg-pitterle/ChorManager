# Gemini Code Intelligence Context: ChorManager

This document provides a comprehensive overview of the ChorManager project, its structure, and its development conventions to be used as a context for AI-powered development assistance.

## Project Overview

ChorManager is a web application designed for choir management. It provides functionalities for managing members, events, attendance, finances, and internal projects.

*   **Project Name:** ChorManager
*   **Type:** PHP Web Application
*   **Primary Language:** PHP 8.5

### Core Technologies

*   **Backend Framework:** [Slim 4](https://www.slimframework.com/)
*   **Database:** MariaDB (version 11.8 specified in DDEV)
*   **ORM:** [Illuminate Database](https://laravel.com/docs/10.x/database) (Eloquent)
*   **Dependency Injection:** [PHP-DI](https://php-di.org/)
*   **Templating:** [Twig](https://twig.symfony.com/) via `slim/twig-view`
*   **Database Migrations:** [Phinx](https://phinx.org/)
*   **Emailing:** [PHPMailer](https://github.com/PHPMailer/PHPMailer)

### Architecture

The application follows a typical Model-View-Controller (MVC) like pattern facilitated by the Slim framework.

*   **`src/`**: The main application directory.
    *   **`Controllers/`**: Contains the controller classes that handle application logic.
    *   **`Models/`**: Contains the Eloquent ORM models that define the database schema and relationships.
    *   **`Middleware/`**: Contains custom middleware for authentication and role-based access control.
    *   **`Routes.php`**: Defines all application routes and maps them to controller actions. This file is the primary entry point for understanding the application's features and API surface.
    *   **`Dependencies.php`**: Configures the PHP-DI container, wiring together services and dependencies.
*   **`public/`**: The web server document root, containing the front controller (`index.php`) and static assets (CSS, JS, images).
*   **`templates/`**: Contains Twig templates for the views.
*   **`db/migrations/`**: Contains the Phinx database migration files.

## Building and Running

This project is configured to use [DDEV](https://ddev.com/) for a consistent local development environment.

### Prerequisites

1.  [Docker](https://www.docker.com/products/docker-desktop/)
2.  [DDEV](https://ddev.com/get-started/)

### First-Time Setup

1.  **Start DDEV:**
    ```bash
    ddev start
    ```
    This will read the `.ddev/config.yaml` file, download the necessary Docker images, and start the project containers (web and database).

2.  **Install Dependencies:**
    ```bash
    ddev composer install
    ```

3.  **Run Database Migrations:**
    ```bash
    ddev exec ./vendor/bin/phinx migrate
    ```
    Or using the composer script alias:
    ```bash
    ddev composer migrate
    ```

The application will be available at the URL provided by `ddev start` (usually `https://chormanager.ddev.site`).

### Day-to-Day Commands

*   **Start the environment:** `ddev start`
*   **Stop the environment:** `ddev stop`
*   **Access the application shell:** `ddev ssh`
*   **Run a composer command:** `ddev composer <command>`

## Development Conventions

### Database

*   Database schema changes **must** be made via Phinx migrations.
*   To create a new migration, run: `ddev exec ./vendor/bin/phinx create MyNewMigration`
*   After creating the migration file in `db/migrations/`, edit it to define the `up()` and `down()` methods.
*   Apply the migration with `ddev exec ./vendor/bin/phinx migrate`.

### Coding Style

* Use PSR12 Format
* No lines longer than 130 characters


*   The project uses [PHP_CodeSniffer](httpss://github.com/squizlabs/PHP_CodeSniffer) to enforce coding standards, with rules defined in `phpcs.xml`.
*   **Check for violations:**
    ```bash
    ddev composer phpcs
    ```
*   **Automatically fix violations (where possible):**
    ```bash
    ddev composer phpcbf
    ```
It is recommended to run the checker before committing changes.
