# Deploying to cPanel

How to put the OHA platform on AAYMCA's cPanel server, next to the voting system
(`/home/africaym/motion-vote`). Same stack, same approach: the code comes from GitHub,
PHP 8.4 runs it, PostgreSQL holds the data, cron runs the background work. No Node.js
is needed on the server: the built CSS and JavaScript come with the code.

Names used below. Change them if you choose others:

| | |
|---|---|
| cPanel account | `africaym` |
| App folder | `/home/africaym/oha` |
| Address | `https://oha.ymcaafricaalliance.org` |
| PHP 8.4 (command line) | `/opt/cpanel/ea-php84/root/usr/bin/php` |

---

## Part A: once, to go live

### 1. Point the address at the server
At GoDaddy (where `ymcaafricaalliance.org` is managed), add an **A record**:
`oha` → the server's IP. This is the same IP as `vote.ymcaafricaalliance.org`.

### 2. Create the subdomain
cPanel → **Domains** → **Create A New Domain**: `oha.ymcaafricaalliance.org`.
- Untick "Share document root".
- Set **Document Root** to `oha/public`, which is `/home/africaym/oha/public`.

### 3. PHP 8.4 and its extensions
- cPanel → **MultiPHP Manager**: set `oha.ymcaafricaalliance.org` to **PHP 8.4 (ea-php84)**.
- Check the extensions: open cPanel → **Terminal** and run
  `/opt/cpanel/ea-php84/root/usr/bin/php -m`. These must be listed:
  `pdo_pgsql, zip, gd, dom, xml, simplexml, xmlreader, xmlwriter, fileinfo, mbstring, iconv, openssl, curl`.
- If one is missing, it is added in WHM → **EasyApache 4** → PHP Extensions (`ea-php84-php-…`).

### 4. Create the database
cPanel → **PostgreSQL Databases**:
1. Create the database `oha`. cPanel names it `africaym_oha`.
2. Create the user `oha` (`africaym_oha`) with a strong password. Keep the password.
3. Add the user to the database with **all privileges**.

Check the Postgres version in Terminal with `psql --version`. Version 13 or newer is fine.

### 5. Get the code
In cPanel Terminal:
```bash
cd ~
git clone https://github.com/aaymca-news/OHA.git oha
cd oha
alias php=/opt/cpanel/ea-php84/root/usr/bin/php        # PHP 8.4 for this session
php -v                                                   # must say 8.4
php /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader
```
If `/opt/cpanel/composer/bin/composer` is not there, run `which composer` and use that path.

### 6. Settings (`.env`)
```bash
cp .env.example .env
php artisan key:generate
nano .env
```
Set these, and leave the rest as they are:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://oha.ymcaafricaalliance.org
LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_DATABASE=africaym_oha
DB_USERNAME=africaym_oha
DB_PASSWORD=the password from step 4

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=… same as the voting system's .env
MAIL_PORT=…
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_SCHEME=…
MAIL_FROM_ADDRESS=…
MAIL_FROM_NAME="AAYMCA Organizational Health"
```
- **Copy the mail settings from the voting system's `.env`**
  (`/home/africaym/motion-vote/.env`). Copy its `DB_HOST` style too: if it uses
  `localhost` instead of `127.0.0.1`, do the same here.
- **Email certificate.** This server's mail certificate is for its own hostname
  (`205-251-145-90.cprapid.com`), not `africaymca.org`. The voting system turned the
  check off in code. Here, try `MAIL_HOST=205-251-145-90.cprapid.com` first, which keeps
  the check on. If emails still fail with a certificate error, add `MAIL_VERIFY_PEER=false`.
  The connection stays encrypted either way.
- **Never change `APP_KEY` once the site is live.**
- `GOOGLE_SERVICE_ACCOUNT_JSON` stays empty until the Google key exists. See
  `ODP-GOOGLE-DRIVE-PLAN.md`; the key file goes outside `oha/`, e.g. `~/keys/`.

### 7. Build the database
```bash
php artisan migrate --force
php artisan db:seed --force        # in production: the 23 movements only; no sample users, no assessments
```

### 8. The first Super Administrator
A new database has no users, and everyone else is invited from inside the site. So the
first account is made here:
```bash
php artisan oha:create-super-admin raymond@africaymca.org "Raymond Njiru"
```
It prints a link. Open it to set the password; it works once, for 7 days. Run the command
again for a new link. From then on, invite people from **Users & Roles**.

### 9. Speed up, and file permissions
```bash
php artisan optimize          # caches settings, routes, views and events
chmod -R ug+rwX storage bootstrap/cache
```

### 10. Background work (cron)
cPanel → **Cron Jobs** → add both, **Once per minute** (`* * * * *`):
```
cd /home/africaym/oha && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
cd /home/africaym/oha && /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```
The first runs scheduled tasks: reminders, and the Google Drive check once it is
connected. The second sends notifications and emails, which wait in a queue.

### 11. HTTPS
cPanel → **SSL/TLS Status** → run **AutoSSL** for `oha.ymcaafricaalliance.org` once DNS has
reached the server (step 1).

### 12. Check it works
1. Open `https://oha.ymcaafricaalliance.org`. The sign-in page shows the AAYMCA logo.
2. Sign in as the Super Administrator. The dashboard says 0 of 23 movements rated.
3. Invite a second Administrator. If their email arrives, mail and the queue cron work.
4. **National Movements** lists all 23.

### 13. Make the GitHub repository private
On GitHub: aaymca-news/OHA → Settings → General → Danger Zone → **Change visibility** →
Private. The server then needs its own read-only key to pull:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/oha_deploy -N "" -C "cpanel oha deploy"
cat ~/.ssh/oha_deploy.pub
```
1. On GitHub, open the repo → Settings → **Deploy keys** → Add, paste that key, and leave
   "Allow write access" off.
2. Back on the server, add these lines to `~/.ssh/config`:
   ```
   Host github-oha
       HostName github.com
       IdentityFile ~/.ssh/oha_deploy
       IdentitiesOnly yes
   ```
3. Switch the app to pull through that key, and test it:
   ```bash
   cd ~/oha && git remote set-url origin git@github-oha:aaymca-news/OHA.git
   git pull --ff-only     # should say "Already up to date"
   ```

---

## Part B: every update

On the PC: commit, then run `./push-platform.sh`.

On the server (cPanel Terminal):
```bash
cd ~/oha
alias php=/opt/cpanel/ea-php84/root/usr/bin/php
php artisan down --retry=60
git pull --ff-only origin main
php /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

---

## Part C: backups

These two hold everything. Neither is in GitHub:

| What | Where |
|---|---|
| The database | `pg_dump -U africaym_oha -d africaym_oha --no-owner -f ~/backups/oha-$(date +%F).sql` |
| Uploaded files: forms, reports, ODPs, signatures | `/home/africaym/oha/storage/app/private/oha` |

If `pg_dump` fails with an SSL or `pg_hba` error, leave out `-h`, as on the voting server.
cPanel's own **Backup** also includes PostgreSQL databases and home folders. Download a
copy regularly and keep the `.env` file safe: its `APP_KEY` is needed to restore.
