# Deploying to cPanel

How to put the OHA platform on AAYMCA's cPanel server, next to the voting system
(`/home/africaym/motion-vote`). Same stack, same approach: the code comes from GitHub,
PHP 8.4 runs it, PostgreSQL holds the data, cron runs the background work. No Node.js
is needed on the server: the built CSS and JavaScript come with the code.

## Ground rules: the main site and the voting system are never touched

The cPanel account (`africaym`) also runs the main website, `africaymca.org` (WordPress,
in `public_html`), and the voting system (`motion-vote`). Deploying OHA adds things
**beside** them and changes nothing of theirs:

| Never | Instead |
|---|---|
| Put anything in `public_html`, or change any file there | OHA lives in its own folder, `/home/africaym/oha` |
| Accept cPanel's suggested document root (`public_html/…`), or tick "Share document root" | Set the document root to `oha/public` |
| Change the PHP version of `africaymca.org`, of the voting site, or the account's default | Set PHP 8.4 for the OHA domain only, in MultiPHP Manager |
| Touch the WordPress MySQL database or the voting system's PostgreSQL database | OHA gets its own new PostgreSQL database and user |
| Edit or delete existing cron jobs | Only add OHA's two |
| Edit or delete existing DNS records | Only add one new record for the OHA address |
| Run commands inside `public_html` or `motion-vote` | Every command below starts with `cd ~/oha` |

If a cPanel screen offers to change something for "all domains" or for the account,
stop and check first.

Names used below. Change them if you choose others:

| | |
|---|---|
| cPanel account | `africaym` |
| App folder | `/home/africaym/oha` |
| Address | `https://oha.ymcaafricaalliance.org` |
| PHP 8.4 (command line) | `/opt/cpanel/ea-php84/root/usr/bin/php` |

---

## Part A: once, to go live

### 1. Choose the address, the way the voting site's was chosen
cPanel → **Domains**: find the row whose document root is `/home/africaym/motion-vote/public`.
That is the voting site's address. Give OHA the same kind of address, e.g. `oha.` instead
of `vote.` on the same parent domain. This guide uses `oha.ymcaafricaalliance.org`.

Then point that address at the server. Look where the parent domain's DNS is managed
(the voting runbook says GoDaddy for `ymcaafricaalliance.org`). Add **one** A record:
`oha` → the same IP as the voting address. If the parent domain's DNS is in cPanel's own
**Zone Editor** instead, cPanel adds the record itself in step 3.

### 2. Get the code from GitHub, as for the voting system
cPanel → **Git™ Version Control** → **Create**:
- **Clone a Repository**: on
- **Clone URL**: `https://github.com/aaymca-news/OHA.git`
- **Repository Path**: `oha`, which is `/home/africaym/oha`. It sits beside `motion-vote`,
  never inside `public_html`.
- **Repository Name**: `OHA`

This must come **before** step 3. cPanel only clones into a folder that does not exist
yet, and creating the domain first would create `oha/public`.

When it has finished, the list shows OHA next to the voting repository. In File
Manager, `/home/africaym/oha` holds `app`, `public` and the rest of the code. It has no
`vendor` folder and no `.env` yet; steps 4 and 6 add them.

### 3. Create the domain
cPanel → **Domains** → **Create A New Domain** → `oha.ymcaafricaalliance.org`.
- **Untick** "Share document root (/home/africaym/public_html)". This is what keeps OHA
  out of the main site.
- Set **Document Root** to `oha/public`, which is `/home/africaym/oha/public`. It already
  exists, from step 2.

### 4. PHP 8.4, its extensions, and the PHP libraries
- cPanel → **MultiPHP Manager**: tick **only** `oha.ymcaafricaalliance.org` and set it to
  **PHP 8.4 (ea-php84)**, the same as the voting site. Leave every other row as it is.
- Check the extensions. Open cPanel → **Terminal** and run
  `/opt/cpanel/ea-php84/root/usr/bin/php -m`. These must be listed:
  `pdo_pgsql, zip, gd, dom, xml, simplexml, xmlreader, xmlwriter, fileinfo, mbstring, iconv, openssl, curl`.
  The voting system already needs most of them. `zip` (for reading the Excel forms) is
  the one most likely missing.
- This server runs **CloudLinux** (the `.cagefs` and `.cl.selector` folders). If an
  extension is missing, don't use "Select PHP Version": it changes PHP for the whole
  account. Ask the host (JaguarPC) to enable it for **ea-php84**, the way the voting
  site's PHP was set up. Adding an extension does not change the other sites.
- Install the PHP libraries (the `vendor` folder, which is not in GitHub). In Terminal:
  ```bash
  cd ~/oha
  alias php=/opt/cpanel/ea-php84/root/usr/bin/php        # PHP 8.4 for this session
  php -v                                                   # must say 8.4
  php /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader
  ```
  If `/opt/cpanel/composer/bin/composer` is not there, run `which composer` and use that path.

### 5. Create the database
cPanel → **PostgreSQL Databases**:
1. Create the database `oha`. cPanel names it `africaym_oha`.
2. Create the user `oha` (`africaym_oha`) with a strong password. Keep the password.
3. Add the user to the database with **all privileges**.

Check the Postgres version in Terminal with `psql --version`. Version 13 or newer is fine.

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
DB_PASSWORD=the password from step 5

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
5. `https://oha.ymcaafricaalliance.org/error_log` and `…/.env` both answer **403 Forbidden**
   or **404 Not Found**, never a file.
6. `https://africaymca.org` and the voting site still open and work as before.

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

On the server, bring the new code in. Either use cPanel → **Git™ Version Control** → OHA →
**Manage** → **Pull or Deploy** → **Update from Remote**, or run the `git pull` line below.
Then, in Terminal:
```bash
cd ~/oha
alias php=/opt/cpanel/ea-php84/root/usr/bin/php
php artisan down --retry=60
git pull --ff-only origin main      # skip if you used "Update from Remote"
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
