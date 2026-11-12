# Too Many Coins — test deployment from zero

A from-nothing checklist for standing up `test.too-many-coins.com` on a Hostinger
VPS with Dokploy, deploying from `TheRealTwizzy/too-many-coins-game` `main`.
Each step says where it happens. `DEPLOY_DOKPLOY.md` is the reference this is
condensed from; section numbers below point into it.

Starting state this assumes: the domain `too-many-coins.com` exists (DNS at
Hostinger), and nothing else — no VPS, no Dokploy, no GitHub App.

## 1. Hostinger: VPS

- [ ] Buy a VPS: Ubuntu 24.04, KVM 2 or larger (2 vCPU / 8 GB is comfortable for
      web + worker + MySQL on one box).
- [ ] Set the root password / SSH key in the VPS panel.
- [ ] Write down the public IPv4 address. It is used in steps 2 and 7.

## 2. Hostinger: DNS

In hPanel → Domains → `too-many-coins.com` → DNS / Nameservers. Add or edit:

| Type | Name   | Points to | TTL     |
|------|--------|-----------|---------|
| A    | `test` | VPS IP    | default |
| A    | `@`    | VPS IP    | default |
| A    | `www`  | VPS IP    | default |

- [ ] Remove any parking A/CNAME records Hostinger added for `@` and `www`.
- [ ] Wait for propagation; `nslookup test.too-many-coins.com` must return the VPS
      IP before step 7 (Let's Encrypt needs it).

## 3. VPS: install Dokploy

- [ ] SSH in as root and run:

      ```bash
      curl -sSL https://dokploy.com/install.sh | sh
      ```

- [ ] Open `http://<VPS IP>:3000`, create the Dokploy admin account.
- [ ] Optional but recommended: Dokploy → Settings → Server → set a domain for
      the panel itself so port 3000 is no longer exposed in the clear.

## 4. GitHub: connect the repository

Dokploy → Settings → Git providers → GitHub → **Create GitHub App**.

- [ ] GitHub asks where to install it: choose the `TheRealTwizzy` account and
      grant access to `too-many-coins-game` only.
- [ ] Back in Dokploy the provider should list the repository. This also installs
      the webhook that triggers a deploy on every push to `main`.

Nothing else is needed on GitHub. There are no Actions or secrets to configure.

## 5. Dokploy: project and database (section 2, option A)

- [ ] Create project `too-many-coins`.
- [ ] Create Service → Database → MySQL 8.0, name `tmc-db`.
  - Database name `too_many_coins`, user `tmc`, generate a strong password.
  - Do **not** expose a public port.
  - Confirm a volume is mounted at `/var/lib/mysql` (a redeploy of the
    database without one destroys the data).
- [ ] Deploy it. The internal hostname is the service name, `tmc-db`.

## 6. Dokploy: web application (section 3)

- [ ] Create Service → Application, name `too-many-coins-test`.
  - Provider: GitHub, repository `TheRealTwizzy/too-many-coins-game`, branch
    `main`.
  - Build type: Dockerfile, path `Dockerfile`.
  - Container port: `80`.
- [ ] Environment tab: paste `deploy/dokploy/web.env.example`, replace every
      `CHANGE-ME`. Generate `TMC_INIT_SECRET` with `openssl rand -hex 32`.
- [ ] Do **not** deploy yet; DNS must be live first (step 2) or the certificate
      request fails.

## 7. Dokploy: domain and certificate (section 7)

- [ ] Web app → Domains → Add: host `test.too-many-coins.com`, path `/`,
      container port `80`, HTTPS on, certificate Let's Encrypt.
- [ ] Deploy the web app. Watch Deployments until the build is green.
- [ ] `https://test.too-many-coins.com/` loads (it will say the database is not
      initialised until step 9; that is expected).

## 8. Dokploy: tick worker (section 8)

- [ ] Create Service → Application, name `too-many-coins-worker`, same
      repository, branch and Dockerfile.
  - No domain, no exposed port.
  - Replicas: `1`. More than one only produces `advisory_lock_busy` log spam.
  - Advanced → Run / Command: `/app/docker/worker-entrypoint.sh`.
- [ ] Environment tab: paste `deploy/dokploy/worker.env.example`, fill in the
      same five `DB_*` values as the web app.
- [ ] Do not deploy until step 9 has run `init_db.php`.

## 9. First boot: schema, migrations, worker (sections 5 and 4.7)

In the web app → Terminal:

- [ ] `php /app/init_db.php` — base schema and seed data. Expect
      "Boost/drop migration loaded" in the output.
- [ ] `php /app/tools/migrate.php --dry-run` — lists what will run.
- [ ] `php /app/tools/migrate.php` — applies and records them.
- [ ] `php /app/tools/migration_status.php` — expect `pending: 0` and no
      `FAILED` or `CHECKSUM DRIFT` section.
- [ ] Deploy the worker. Its log must show `[tick-worker] starting` and a
      `[tick-worker] timing (...)` line containing `time_scale=1`.
- [ ] Back in the web terminal:
      `php /app/tools/runtime_readiness_check.php --observe-seconds=15 --pretty`
      must report neither `blocked` nor `degraded`.

## 10. Mail and first admin (sections 4.6 and 9)

- [ ] Web terminal: `php /app/tools/mail_selftest.php you@example.com`. If the
      mail does not arrive, fix `TMC_SMTP_*` before anyone registers; an
      unconfirmed account can do nothing.
- [ ] Register your own account on the site and confirm the email.
- [ ] Web terminal: `php /app/tools/admin.php promote <YourHandle>`, then
      `php /app/tools/admin.php staff` to confirm.
- [ ] To keep the lane private while testing:
      `php /app/tools/admin.php gate on --reason="pre-test lockdown"`, and create
      testers with `php /app/tools/admin.php tester create <handle>`.

## 11. Done when

- `https://test.too-many-coins.com/api/index.php?action=game_state` returns JSON.
- `server_state.last_tick_processed_at` advances every few seconds (watch the
  worker log or `runtime_readiness_check.php`).
- A push to `main` shows up as a new deployment in Dokploy within a minute.

## When something fails

Section 10 of `DEPLOY_DOKPLOY.md` covers the usual ones: `Database connection
failed` (wrong `DB_HOST`, or an alias such as `MYSQL_USER` silently supplying a
stale value, section 4.10), worker not ticking, `maintenance_lockdown` on every
action, and private client IPs behind the proxy.
