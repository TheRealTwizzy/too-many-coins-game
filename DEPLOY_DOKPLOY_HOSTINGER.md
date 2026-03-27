# Deploy Too Many Coins on Dokploy + Hostinger MySQL

This guide deploys the app on a Dokploy-managed Ubuntu 24.04 VPS, using:
- Dockerfile build for the app container
- PHP 8.3 + Apache (serving HTML/CSS/JavaScript frontend and PHP API)
- External MySQL database hosted on Hostinger

## 1. Prerequisites

- Dokploy installed and reachable on your VPS
- Hostinger MySQL database created
- Hostinger DB remote access enabled for your VPS public IP
- Repository connected to Dokploy (GitHub/GitLab)

## 2. Hostinger Database Setup

In Hostinger hPanel:
1. Create database, user, and password.
2. Enable remote MySQL access.
3. Add your VPS public IP to allowed hosts.
4. Note these values:
   - `DB_HOST`
   - `DB_PORT` (usually `3306`)
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`

## 3. Create App in Dokploy

1. Create a new Application in Dokploy.
2. Source: select your Git repository and branch.
3. Build method: Dockerfile.
4. Dockerfile path: `Dockerfile`.
5. Exposed container port: `80`.
6. Public domain: set your domain/subdomain.

## 4. Environment Variables in Dokploy

Set these env vars in the Dokploy app:

- `DB_HOST=<hostinger mysql host>`
- `DB_PORT=3306`
- `DB_NAME=<database name>`
- `DB_USER=<database user>`
- `DB_PASS=<database password>`
- `TMC_TIME_SCALE=60`
- `TMC_INIT_SECRET=<strong-random-secret>`

Optional:
- `TZ=UTC`

## 5. First Deploy

Run deploy in Dokploy. After it is healthy, initialize schema/data once.

### Option A: Run in Dokploy terminal (recommended)

```bash
php /app/init_db.php
```

### Option B: HTTP init endpoint (only if needed)

```text
https://your-domain/api/index.php?action=init_db&secret=YOUR_TMC_INIT_SECRET
```

Do not leave weak init secrets. Rotate or remove access after initialization.

## 6. Health Checks

Use one of these paths in Dokploy:
- `/`
- `/api/index.php?action=game_state`

If your app requires auth for some endpoints, use `/` for health checks.

## 7. DNS and SSL

1. Point your domain A record to the VPS IP.
2. Configure SSL in Dokploy for your domain.
3. Verify HTTPS and API reachability:
   - `https://your-domain/`
   - `https://your-domain/api/index.php?action=game_state`

## 8. Troubleshooting

- `Database connection failed`:
  - Verify Hostinger allows your VPS IP.
  - Confirm `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- Static frontend works but API fails:
  - Check Dokploy app logs for PHP errors.
  - Confirm env vars are present in runtime, not only build time.
- Init returns forbidden:
  - Set `TMC_INIT_SECRET` and use it in the init URL, or run CLI init.

## 9. Stack Clarification

This deployment uses:
- Dockerfile
- PHP
- MySQL
- HTML/CSS
- JavaScript (frontend client)

If by "JAVA" you intended JVM Java, that is not used by this codebase.
