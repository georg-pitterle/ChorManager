

## Database Migration
./vendor/bin/phinx migrate

## Development Seed Data

For local development and feature validation, the project provides a Dev-only seed command.

### Security Guards

- Seeding is only allowed when `APP_ENV` is set to `development`, `dev`, or `local`.
- `ALLOW_DEV_SEED=1` must be set explicitly.
- Without both conditions, the seed command aborts.

### Run Seed Data

Recommended with DDEV:

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed --years=3 --seed=20260321
```

Alternative (composer script):

```bash
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 composer seed:dev -- --mode=append --years=3 --seed=20260321
```

Available modes:

- `append`: add additional seed data.
- `reset-and-seed`: truncate seed-relevant tables and create a fresh dataset (Dev only).

### Seed Report Credentials

The seed report now includes `credentials_by_role` with one demo login per role:

- Admin
- Vorstand
- Chorleitung
- Stimmvertretung
- Ersatzvertretung
- Mitglied

Each entry contains `role`, `email`, `password_plain`, and `user_id`.
These credentials are generated for Dev-only workflows and must never be used in production.

### Requirement for New Features

Every newly implemented feature must include matching seed data updates so the feature is testable immediately in a fresh Dev environment.
At minimum, update the Dev seed implementation in `src/Services/DevSeedService.php` and include realistic sample records for the new domain model.

## Deployment

### Docker

To deploy the application using Docker:

1. Build and run the containers:
   ```bash
   docker-compose up --build
   ```

2. The application will be available at http://localhost

