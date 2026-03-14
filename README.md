

### Datenbank Migration:
./vendor/bin/phinx migrate

## Deployment

### Docker

To deploy the application using Docker:

1. Build and run the containers:
   ```bash
   docker-compose up --build
   ```

2. The application will be available at http://localhost

### GitHub Actions

The repository includes a GitHub Actions workflow that builds the Docker image, pushes it to GitHub Container Registry, and deploys it to a server.

To set up deployment:

1. Set the following secrets in your GitHub repository:
   - `SERVER_HOST`: The IP address or hostname of your server
   - `SERVER_USER`: The SSH username
   - `SERVER_SSH_KEY`: The private SSH key for authentication

2. Ensure your server has Docker and Docker Compose installed, and the repository cloned with the `docker-compose.yml` file.

3. On push to the `main` branch, the workflow will build the image, push it to GitHub Container Registry, and deploy it to your server.