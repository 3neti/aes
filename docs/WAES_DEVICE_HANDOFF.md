# WAES device access and operations hand-off

## Scope and evidence

Prepared 2026-11-24 for an agent operating from the owner's Mac or another
authorized machine on the appliance's LAN. This is an operations hand-off, not
permission to reset election data, re-provision a working device, or introduce
test ballots without the operator's approval.

Read this together with [Ubuntu offline deployment](UBUNTU_OFFLINE_DEPLOYMENT.md).
That runbook contains the detailed fresh-install recipes; **do not execute its
initial provisioning steps on an existing installation**.

- Repository: `3neti/aes`, `https://github.com/3neti/aes`.
- Mac checkout: `/Users/rli/PhpstormProjects/aes`.
- Device: `waes`, Ubuntu 24.04, administration account `ace`.
- Application: `/var/www/aes`; web, queue, Reverb and scheduler user `www-data`.
- Commissioned stack: Nginx, PHP 8.5-FPM, MySQL, CUPS, hostapd and dnsmasq.
- Last full software deployment, 2026-11-18:
  `e65355298252d9be357d8798b40c630358f7ae9f`, on clean `main`.
- On 2026-11-24 the device was rediscovered on the Mac's new LAN, its SSH host
  fingerprint matched, and its voter page returned HTTP 200. This does **not**
  re-certify all services, hardware, or WebSockets after the network move.
- The local checkout was clean at `e653552` when this hand-off was started.
  Always inspect current device and repository state rather than assuming these
  historical observations are still current.

**Use `deployment/offline-ap/`.** The older `deployment/install-waes-runtime`,
`deployment/systemd/` and `deployment/environment/waes-runtime.env.example`
describe a PostgreSQL / `ace` runtime. Their existence and passing legacy tests
do not make them the profile for this commissioned MySQL / `www-data` appliance.

## 1. Find the device and establish trusted access

### Current and recovery addresses

- Last verified wired address: **192.168.68.74**, on 2026-11-24.
- Mac address at discovery: `192.168.68.51/22`; router `192.168.68.1`.
- LAN mDNS name: **waes.local**, resolved on that network.
- Earlier wired address: `192.168.88.38`; it is historical, not a static setting.
- Known Ethernet link-local IPv6: `fe80::201:2eff:fe70:7d15`.
- Appliance AP: SSID **WAES**, gateway **10.42.0.1/24**.
- AP DNS aliases: `waes.home.arpa`, `waes.consultel.ph`. These are provided by the
  AP's resolver; do not assume the wired router resolves them.

Ethernet `enp1s0` uses DHCP. After moving networks, start on the Mac with:

```bash
/sbin/route -n get default
/usr/sbin/arp -an
/usr/bin/dns-sd -G v4v6 waes.local
```

`dns-sd` keeps running: note the answers and press Ctrl-C. If mDNS fails, check
the router's leases for `waes` or use link-local IPv6 on the same link. Use the
Mac's actual interface name; `en0` was the working interface, not a universal
constant. Do not give the device an arbitrary static IP to work around competing
DHCP servers or a broken uplink.

### Verify identity before entering credentials

The recorded **ED25519** SSH host-key fingerprint is:

```text
SHA256:ZbkrQLoYoiw5bBx0pv+abGcFE3YY9dpquLMkzH3IAbw
```

Public-key discovery from the Mac:

```bash
WAES_HOST=waes.local
/usr/bin/ssh-keyscan -T 5 -t ed25519 "$WAES_HOST" | /usr/bin/ssh-keygen -lf -
/usr/bin/ssh -t -o HostKeyAlgorithms=ssh-ed25519 \
  -o StrictHostKeyChecking=ask "ace@$WAES_HOST"
```

Replace `WAES_HOST` with the newly discovered literal IP if necessary. Compare
the scanned key **and the SSH trust prompt** with the fingerprint above before
accepting. Key scanning alone is not authentication. A mismatch requires
investigation; never use `StrictHostKeyChecking=no`, discard all known hosts, or
silently replace a changed key.

Link-local fallback, from the Mac:

```bash
/usr/bin/ssh -6 -t -o HostKeyAlgorithms=ssh-ed25519 -o StrictHostKeyChecking=ask \
  'ace@fe80::201:2eff:fe70:7d15%en0'
```

Use native **`/usr/bin/ssh`**. A shell function named `ssh` previously mishandled
multiplexing arguments. A new agent must not assume that an old connection,
ControlMaster socket, host-key entry, SSH agent, or sudo timestamp survives.
For a new/changed address, authenticate and establish host trust afresh. A
noninteractive `BatchMode=yes` attempt can fail simply because trust or
authentication has not yet been established.

The operator must enter SSH/sudo passwords through secure terminal prompts, or
provide an already-authorized SSH key through their normal authentication
mechanism. **This hand-off provides no login password, private key, Wi-Fi
password, or application secret.** Never recover credentials from chat/shell
history, print them, place them in command arguments, use `sshpass`, or copy a
private key between agents. Do not install a new authorized key without approval.

An optional reusable connection, **after identity verification**, is:

```bash
/usr/bin/ssh -t -o ControlMaster=auto -o ControlPersist=10m \
  -o 'ControlPath=~/.ssh/waes-%C' "ace@$WAES_HOST"
```

Other agents need their own authorized access to the Mac/LAN and credential
prompt. A remote/cloud agent cannot reach this private LAN merely because it has
the repository; use an approved management path rather than exposing public SSH.

### Identify the host after login

All following shell examples are for **Bash on the Ubuntu device**, unless
explicitly labeled otherwise. `cd /var/www/aes` is intentional: `www-data` cannot
traverse `/home/ace`, and launching PHP from that directory previously caused
`posix_spawn(): Permission denied`.

```bash
hostname
ip -br address
cd /var/www/aes
git --no-pager status --short
git --no-pager branch --show-current
git --no-pager rev-parse HEAD
php --version
node --version
df -h /var/www/aes
```

Do not dump `.env`, `bootstrap/cache/config.php`, systemd environment values,
session files, or complete election/scan records into agent output. Public
fingerprints and queue names are safe; application keys and raw ballot material
are not.

## 2. Current web and appliance map

- `/` redirects to **`/election/role-demo/voter`** at the deployed release.
- Officer workstation: `/election/role-demo/officer`; selectable steps are
  admission, VVPAT printing, and election return.
- Scanner/operator board: `/election/role-demo/precinct-tally`.
- Public scanner board: `/election/role-demo/precinct-tally/public`.
- Captive welcome page: `/hotspot`; health endpoint: `/up`.
- Wired browser entry: `http://waes.local` or the current verified wired IP.
- AP browser entry: `http://10.42.0.1`.

Nginx serves only `/var/www/aes/public` and forwards only `index.php` to
`/run/php/php8.5-fpm.sock`. Reverb listens privately on `127.0.0.1:8080`; Nginx
proxies both `/app/` and `/apps/` over the normal web port.

On the device, the important installed paths are:

- `.env`: `/var/www/aes/.env`, expected owner `ace:www-data`, mode `0640`.
- Laravel writable state: `storage/`, `bootstrap/cache/`; preserve existing
  ACLs and private subdirectory ownership.
- Nginx site: `/etc/nginx/sites-available/aes`, enabled through `sites-enabled`.
- WebSocket snippet: `/etc/nginx/snippets/waes-reverb.conf`.
- PHP overlay: `/etc/php/8.5/fpm/conf.d/99-aes.ini`.
- Scanner environment: `/etc/waes/scanner.env`, `root:www-data`, mode `0640`.
- Wi-Fi secret configuration: `/etc/hostapd/hostapd.conf`, root-only `0600`.
- AP DNS/DHCP: `/etc/waes/dnsmasq-ap.conf`.
- AP firewall: `/etc/waes/waes-ap.nft`.
- AP interface: `/etc/systemd/network/05-waes-ap.network`.
- AP service drop-ins: `/etc/systemd/system/{hostapd,dnsmasq}.service.d/waes-ap.conf`.
- Runtime units: `/etc/systemd/system/waes-*`.

## 3. Safe software updates through Git

### Boundaries

Never run `composer run setup`, initial provisioning, `key:generate`,
`migrate:fresh`, seeding, a demo reset, `git reset --hard`, or `git clean` on this
existing appliance. Preserve `APP_KEY`, `.env`, database, storage, election
evidence, private keys, WPA2 configuration, queue definitions and scanner paths.
Ask before Git staging, commits or pushes. A reviewed fast-forward does not
require any of those operations.

Running offline does not mean deployment dependencies are magically available.
Fetch reviewed source and stage the locked PHP dependencies, built frontend,
fonts and required drivers while connectivity or a trusted offline mirror is
available. Do not start downtime only to discover Composer/npm cannot finish.

### Inspect and pin the candidate, without changing live source

```bash
cd /var/www/aes
git --no-pager status --short
git fetch origin main
git --no-pager log --oneline --max-count=8 origin/main
read -r -p "Reviewed full commit SHA to deploy: " TARGET_REVISION
git cat-file -e "$TARGET_REVISION^{commit}"
git merge-base --is-ancestor "$TARGET_REVISION" origin/main
git merge-base --is-ancestor HEAD "$TARGET_REVISION"
git --no-pager diff --stat HEAD "$TARGET_REVISION"
```

Stop for any failed check, dirty checkout, divergence, unexpected remote, or
unreviewed migration. The origin must be the intended `3neti/aes` repository;
do not embed access tokens in its URL or print credential-bearing remote URLs.
Do not substitute an unreviewed newer `main` after validating a pinned revision.

Test in an **isolated checkout** with its own fresh test key and environment,
SQLite `:memory:`, `DB_URL` empty, array cache/session, file printer drivers and
separate test election storage. Do not copy production `.env` into tests.
Do not export test environment overrides into a shell later used for live
maintenance. Feature tests use `RefreshDatabase`; never run them in the live
checkout or against cached production configuration.

Install test/dev dependencies there, run the relevant focused Pest tests, build
with `npm ci` / `npm run build`, check `npm run types:check`, and run targeted
lint. Prepare a separate production vendor tree with
`composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`;
use `composer check-platform-reqs` on the target platform. Do not use
`composer update` or change lockfiles as an incidental deployment step.

Build frontend assets with the deployed **public** `VITE_REVERB_APP_KEY`, not an
unrelated test application's Reverb key. Never put Laravel `APP_KEY`, database
passwords, Reverb's server secret or other private values into frontend assets.
Keep these build variables scoped to the staging process.

The locked election packages in `e653552` require PHP 8.4+ despite the looser
root Composer PHP constraint; the commissioned PHP 8.5 meets that requirement.
Confirm `/usr/bin/php` used by systemd actually selects the intended CLI version.
The frontend runbook requires Node 22.12+; check the candidate lockfile/toolchain.

### Maintenance, protected backups and deployment

The following is the command sequence, **not an unattended rollback script**.
Arrange an approved maintenance window, a preserved management session, and a
tested root-owned timed rollback before stopping writers. Stop on errors.
Record the previous SHA and original service active/enabled/masked states in the
protected backup. Drain jobs and inspect unfinished print jobs first.

```bash
cd /var/www/aes
git --no-pager rev-parse HEAD
lpstat -W not-completed -o
sudo -u www-data php artisan down --retry=60 --no-interaction
sudo systemctl stop waes-scheduler.timer waes-scheduler.service \
  waes-queue.service waes-scanner.service waes-reverb.service php8.5-fpm.service
```

Keep MySQL, Nginx, CUPS, Ethernet, hostapd, dnsmasq and the AP firewall running.
Verify the application writer processes have exited and in-flight work has
settled before taking the snapshot. PHP-FPM's graceful-drain behavior depends
on the installed unit and stop signal; do not assume every pending request
completed successfully. Stopping only the queue is not sufficient.

Example root-only snapshots, after writers stop; capture installed device
configuration and ACLs as well. Check available space and every exit status:

```bash
BACKUP="/var/lib/waes-releases/$(date -u +%Y%m%dT%H%M%SZ)"
sudo install -d -o root -g root -m 0700 "$BACKUP"
sudo sh -c 'umask 077; mysqldump --single-transaction --routines --triggers --events --databases aes > "$1/database.sql"' sh "$BACKUP"
sudo sh -c 'umask 077; tar --acls --xattrs -cpf "$1/application.tar" -C /var/www/aes .env storage bootstrap/cache vendor public/build' sh "$BACKUP"
sudo sh -c 'umask 077; git -c safe.directory=/var/www/aes -C /var/www/aes archive HEAD > "$1/source.tar"' sh "$BACKUP"
```

The commissioned MySQL root account supports local socket administration; do not
put a database password on the command line. If a different authentication policy
is now installed, stop and obtain authorized credentials securely.

Separately back up Netplan, relevant systemd units/drop-ins, Nginx/PHP overlays,
`/etc/waes`, hostapd and CUPS configuration/PPDs into this root-only directory.
Record integrity hashes privately for `.env` and network/peripheral configuration.
Never redirect an SQL dump to a world-readable or Git-tracked file.

After staged artifacts and backups are confirmed, fast-forward as `ace`:

```bash
cd /var/www/aes
git merge --ff-only "$TARGET_REVISION"
```

Install the tested production vendor tree and `public/build` only, keeping the
old vendor/build/cache trees for rollback. Do not copy staging `.env`, storage,
SQLite databases or development dependencies over production. If installing
in place instead of swapping staged artifacts, complete locked Composer/npm
installation under maintenance and use the existing production configuration.
Prefer prebuilt artifacts to minimize downtime.

Give `www-data` read/traverse access to new source, vendor and assets. Preserve
executable bits and existing storage ACLs; **do not blanket `chown -R` private
storage** to the deployment account. Some Flysystem directories are deliberately
`www-data`-owned and `0700`. Do not expose the entire checkout as world-readable
or give the web account source-write access.

Rebuild generated application artifacts and apply only reviewed migrations.
Preserve the intended database target; review any proposed database/environment
change separately. Rebuild the intended config before migrations so stale
cached configuration cannot silently select a different connection:

```bash
cd /var/www/aes
sudo -u www-data php artisan package:discover --no-interaction
sudo -u www-data php artisan config:cache --no-interaction
sudo -u www-data php artisan migrate --force --no-interaction
sudo -u www-data php artisan route:cache --no-interaction
sudo -u www-data php artisan event:cache --no-interaction
sudo -u www-data php artisan view:cache --no-interaction
sudo -u www-data php artisan migrate:status --no-interaction
```

If changed/removed providers make old bootstrap caches incompatible, preserve
their snapshot and replace only the identified generated bootstrap PHP caches
before package discovery. Do not delete tracked files or flush shared runtime
data. Avoid a blanket `optimize:clear` / `cache:clear` as a deployment shortcut.

Verify the pin, clean Git state and unchanged private configuration. Restore
only the services that were originally running, then lift maintenance:

```bash
sudo systemctl start php8.5-fpm.service
sudo -u www-data php artisan up --no-interaction
sudo systemctl start waes-reverb.service waes-queue.service \
  waes-scanner.service waes-scheduler.timer
curl --fail http://127.0.0.1/up
git --no-pager rev-parse HEAD
git --no-pager status --short
```

The start example assumes all listed services were active beforehand. Restart
long-running processes after code/config changes: otherwise old workers can
continue using old classes or keys. Confirm HTTP, assets, WebSocket frames,
natural heartbeat freshness, printer defaults and AP reachability before marking
the deployment committed and disarming its rollback timer.

### Rollback

Stop application writers again, return to the recorded previous revision
(`git checkout --detach` is preferable to destroying unexpected local changes),
and restore the **matching** vendor, assets and generated bootstrap caches.
Move failed generated trees aside rather than overlaying incompatible vendor
files. Preserve/rebuild the correct permissions, bring the app up, and restart
the original services. Investigate unexpected dirty files rather than forcing
the checkout.

Do **not** automatically restore the database, storage, sessions or `.env` over
newly written data. Additive schema can normally remain while old code runs;
destructive/incompatible migrations need their own reviewed recovery plan.
The full application tar above is a recovery snapshot, not permission to
extract its entire contents onto a live system.

Historical protected backups:

- `/var/lib/waes-releases/e653552-20261118T135748Z` — successful release snapshot.
- `/var/lib/waes-ap/backups/`, `/var/lib/waes-portal/backups/` — earlier
  network/onboarding snapshots.

Successful guarded deployments wrote `committed` markers and disarmed timers.
Old rollback controllers deliberately skip committed snapshots. Do not remove
markers, rerun old launch scripts, or assume temporary `.cache` staging
directories are durable deployment tooling.

## 4. Web server, PHP and database setup/recovery

For a **fresh rebuild**, follow runbook sections 2–3 for packages and credential
creation. On the existing device, inspect rather than reinstall:

```bash
systemctl is-active nginx php8.5-fpm mysql
sudo nginx -t
sudo /usr/sbin/php-fpm8.5 -t
sudo mysqladmin --protocol=socket ping
cd /var/www/aes
sudo -u www-data php artisan migrate:status --no-interaction
```

- Nginx template: `deployment/offline-ap/nginx-aes.conf.example`.
- Proxy template: `deployment/nginx/waes-reverb.conf.example`.
- PHP template: `deployment/offline-ap/php-fpm.ini.example`: 512 MB memory,
  120-second execution budget, 25 MB upload/post limits, `expose_php=Off`.
- Database: MySQL on `127.0.0.1:3306`, database/user `aes`, existing private
  credentials in `.env`; root socket access is for local administration.
- Required extensions/tools include MySQL PDO, SQLite PDO, mbstring, XML, curl,
  zip, GD, intl, bcmath, sodium, Imagick, Ghostscript and `poppler-utils`.
- Production queue/cache/session stores are database-backed. `.env` must keep
  `APP_ENV=production`, `APP_DEBUG=false`; do not seed a default test account.

Only install reviewed templates when rebuilding or deliberately changing that
component. Existing installed configuration wins over examples. Test Nginx/PHP
syntax before a targeted reload/restart; do not restart all networking.

**After a DHCP/network move:** backend publishing stays on loopback, and
`resources/js/lib/browser-websocket-options.ts` makes browser WebSockets
same-origin. However, `REVERB_ALLOWED_ORIGINS` must still allow the actual page
hostname or new literal IP. Review/update that bounded host list privately if
needed, then rebuild config and restart the affected app processes. Do not use
`*` to bypass the check. A changed browser-visible `VITE_*` key requires a new
frontend build; a DHCP change alone should not require hard-coding a new IP in
JavaScript. HTTP working on the new address does not prove Reverb works there.

## 5. Printers: identify, install and validate

Start with hardware identity, **not** saved queue status:

```bash
lsusb
sudo lpinfo --include-schemes usb -v
lpstat -p -v
lpstat -W not-completed -o
lpoptions -p Thermal58
```

The commissioned devices/roles are:

- **HP Laser 108a**, USB `03f0:e82a`, queue **HP_Laser_108a**, A4 ballots.
  The recorded serial-based URI was
  `usb://HP/Laser%20103%20107%20108?serial=CNB1V2S7TF`; rediscover before replacing
  hardware rather than blindly reusing that serial.
- **58 mm Xprinter-compatible thermal**, USB `0483:070b`, queue **Thermal58**,
  control-number receipts. Its misleading model/URI can include “HP Laser”;
  that does **not** make it the HP ballot printer.
- Closeout forms use the **file** driver unless separately commissioned.

### Fresh driver/queue installation

Use runbook section 4 for exact dependencies and manual installation paths.
Do not reinstall working queues as part of a source update.

1. HP: use the reviewed HP Laser 10x ULD `rastertospl` driver, not arbitrary
   HPLIP/PCL. The runbook records HP's archive URL and SHA-256
   `cebb9b7b6125e7406634bb9c2a98b01477d1e11d66c7c90474669de9927bc91d`.
   Verify the archive and x86-64 compatibility, install `libcupsimage2t64`,
   `rastertospl`, its shared library/resources, and the matching PPD.
   Check linked libraries before commissioning.
2. Thermal: reviewed source `https://github.com/klirichek/zj-58`, pinned commit
   `64743565df4379098b68a197d074c86617a8fc0a`. Build as the deployment account
   with CMake `-DRASTER_TYPE=Old`, using the CUPS/image development libraries.
   Install only the built filter and matching `zj58.ppd` to the runbook paths;
   do not blindly run an upstream installer that restarts CUPS.
3. Correlate each discovered URI with USB IDs and the physical device. On Ubuntu
   Bash, collect the non-secret URI into `HP_URI` or `THERMAL_URI` privately at
   the terminal, then create queues **only when installation is intended**:

```bash
read -r -p "Verified HP USB URI: " HP_URI
sudo lpadmin -p HP_Laser_108a -E -v "$HP_URI" \
  -P /usr/share/cups/model/uld-hp/HP_Laser_10x_Series.ppd \
  -o PageSize=A4 -o Resolution=600dpi \
  -o printer-is-shared=false -o printer-error-policy=retry-job

read -r -p "Verified thermal USB URI: " THERMAL_URI
sudo lpadmin -p Thermal58 -E -v "$THERMAL_URI" \
  -P /usr/share/cups/model/consultel/zj58.ppd \
  -o PageSize=X48MMY65MM -o orientation-requested-default=3 \
  -o print-scaling-default=none -o printer-is-shared=false \
  -o printer-error-policy=retry-job
```

The driver/PPD must already exist. Preserve the HP grayscale setting as described
in the runbook and inspect the driver's actual options instead of guessing an
option name. Leave thermal cutter/cash-drawer controls disabled.

Application settings come from the existing `.env`, using the names in
`deployment/offline-ap/application.env.example`:

- `ELECTION_PRINTER_ADAPTER=cups`, `ELECTION_PRINTER_DRIVER=cups`,
  `ELECTION_CUPS_PRINTER=HP_Laser_108a`.
- `ELECTION_CONTROL_NUMBER_PRINTER_DRIVER=cups`,
  `ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal58`.
- `ELECTION_CLOSEOUT_PRINTER_DRIVER=file`.

The receipt PDF is **164 × 120 points**. Keep thermal orientation **portrait
(`3`)** and scaling **none/actual-size**. Leading zeros and all four control
digits must remain legible. Do not restore the old clipped receipt layout.

`CupsBallotPrinter` also requires a passing device-certification report in the
**correct precinct context**, with printer adapter `cups-printer`, status `ready`
and the selected queue name. Use the normal device-health workflow; do not forge
the report. Otherwise it can fall back to file output. File `status=printed`
means PDF generation, not paper printing.

A CUPS queue may say idle with no USB printer attached. A submitted job, an
awaiting-confirmation screen, or even CUPS `completed` is not physical paper/QR
verification. Obtain operator approval before any print test.

### VVPAT confirmation and retry

The role-demo flow reuses its four-digit control number through the voter and
print steps. Do not assume every other application flow has the same semantics.
Pending VVPAT state is persisted and confirmation, not submission, deposits the
sealed record. Resolve a pending print before starting another. Retry must keep
the same release/ballot identity; do not generate another ballot to retry paper.
Do not assume re-rendered PDF bytes are immutable without checking the code.

For an authorized safe paper-out/blocked-job test, record the initial accepted
count and exact job ID. Never induce a mechanical jam. Before a retry, inspect
and cancel **only the identified test job** if needed, and have the operator
clear any printer-buffered copy; otherwise restoring paper can print duplicates.
Never use blanket cancellation or confirm “Yes, printed” before inspecting paper.
Do not reset existing results to perform acceptance.

## 6. Scanner installation, ingestion and privileges

The commissioned LWTEK/Tera scanner was USB **0581:0115**, with stable path:

```text
/dev/input/by-id/usb-LWTEK_Barcode_Scanner_00000000011C-event-kbd
```

Rediscover on hardware replacement:

```bash
lsusb
ls -l /dev/input/by-id/
/usr/bin/python3 -c 'import evdev; print("python3-evdev available")'
```

Use Ubuntu's `python3-evdev` and `/usr/bin/python3`, not an unrelated venv.
Keep USB HID mode unless the real scanner's serial-mode instructions are known.
Never point the bridge at a general keyboard.

For fresh commissioning, use `deployment/offline-ap/scanner.env.example` to
create `/etc/waes/scanner.env` as `root:www-data` `0640`, substituting the actual
by-id path. Preserve an existing correct file. Both that file and the Laravel
environment must agree on:

- `ELECTION_SCANNER_RUNTIME_MODE=evdev`
- `ELECTION_SCANNER_DEVICE` = the actual by-id path
- `ELECTION_SCANNER_INGESTION=precinct`
- `ELECTION_SCANNER_STATION_ID=role-demo-precinct`

The service grants **`SupplementaryGroups=input` only to the scanner process**.
Do not add all of `www-data`, PHP-FPM, Nginx or `ace` to `input`, and do not
globally relax input device modes. Restart the scanner unit after an intentional
configuration change; rebuild Laravel config when its environment changed.

`scripts/waes-scanner-service` runs, from `/var/www/aes`, this pipeline:

```text
scripts/canvassing-scanner-bridge.py
  -> php artisan election:precinct-ballot-scanner-ingest
       --station-id=role-demo-precinct --source=scanner_bridge
```

The Python bridge exclusively grabs that device, emits on its terminator, writes
an idle heartbeat every 30 seconds while connected, and retries a missing device
every 2 seconds. Avoid competing readers. An active systemd service can just be
waiting for an unplugged scanner.

For a separately commissioned canvassing station the wrapper supports
`ELECTION_SCANNER_INGESTION=canvassing`, station `canvassing-demo-city`, and
`election:canvassing-scanner-ingest`. Do not switch the live precinct scanner to
that mode just to test it.

Both ingestion commands accept STDIN or repeated `--payload` values; **neither
has a dry-run mode**. Every real ingestion attempt writes history. Empty input
is not a successful liveness probe. A rejected payload exits nonzero; duplicate
or partial handling is not proof of an accepted ballot.

First verify passively: actual USB/by-id presence, scanner-unit credentials,
natural scanner heartbeat, and existing board events. An authorized diagnostic
scan of unsupported non-Truth text can test rejection/broadcast without adding
a vote, but **still adds a rejected event**. Get approval, keep the audit record,
and do not reset history afterward. A verified duplicate on the same station
can also avoid a tally increment, but do not assume a new station shares the
deduplication scope.

Never use simulator ticks, bulk ballots, fresh real ballot QR payloads, manual
database inserts, or reconstructed/truncated QR text as health probes. A
truncated HID scan previously failed; a proper physical rescan succeeded.

## 7. Queue, scheduler and broadcasts

### Boot-managed runtime processes

The installed units should match `deployment/offline-ap/systemd/`:

- `waes-runtime.target`: wants Reverb, queue and scheduler timer.
- `waes-queue.service`: `www-data`, MySQL-backed worker, command below.
- `waes-reverb.service`: `www-data`, `reverb:start --host=127.0.0.1 --port=8080`.
- `waes-scheduler.timer`: first tick about 30 seconds after boot, then every
  60 seconds; starts oneshot `waes-scheduler.service`.
- `waes-scheduler.service`: `www-data`, `schedule:run --no-interaction`;
  **inactive between runs is normal**.
- `waes-scanner.service`: `www-data` plus service-local `input`; conditionally
  starts only when `/etc/waes/scanner.env` exists.

The queue unit's exact worker command is:

```bash
/usr/bin/php artisan queue:work database --queue=broadcasts,default \
  --sleep=1 --tries=3 --timeout=60 --max-time=3600
```

This is explanatory: **do not launch a second copy** beside the systemd worker.
`broadcasts` has priority, but `default` is also required for queue heartbeats
and application jobs. The database `retry_after` default is 90 seconds, greater
than the 60-second worker timeout; preserve that relationship if tuning.

Source: `routes/console.php` schedules the scheduler heartbeat and queues
`RecordRuntimeHeartbeat('queue')` onto `default` every minute. Only execution
by a worker refreshes that queue heartbeat. Check natural advancement, not just
the worker PID.

### Baseline diagnostics

```bash
systemctl is-active waes-queue waes-reverb waes-scanner waes-scheduler.timer
systemctl list-timers --all --no-pager waes-scheduler.timer
cd /var/www/aes
sudo -u www-data php artisan election:runtime-check --json --no-interaction
sudo -u www-data php artisan queue:failed --no-interaction
```

Heartbeats live under
`/var/www/aes/storage/app/private/runtime/heartbeats/{queue,scheduler,scanner}.json`.
Observe them privately across more than one timer tick; the readiness threshold
is normally **150 seconds**, not the heartbeat helper's standalone 90-second
default. Never manually run `election:runtime-heartbeat queue`, `scheduler`, or
`scanner` to turn a failing check green: it writes synthetic liveness evidence.

The `runtime-check` command checks heartbeat freshness, configured scanner
readability and CUPS queues. `degraded` can exit zero. Its Reverb check is
**configuration-only**, optional, and does not open a WebSocket.

Because only the scanner unit receives `input`, a check run as ordinary
`www-data`/PHP-FPM can fail device readability even when that service can read it.
Inspect the actual unit and heartbeat before diagnosing permissions. If needed,
compare in a temporary diagnostic unit with the same limited input privilege:

```bash
sudo systemd-run --quiet --collect --wait --pipe \
  --uid=www-data --gid=www-data \
  --property=SupplementaryGroups=input \
  --property=WorkingDirectory=/var/www/aes \
  /usr/bin/php artisan election:runtime-check --json --no-interaction
```

This creates a transient diagnostic unit, not a new permanent worker. Do not
“fix” a diagnostic-context difference by widening global keyboard access.
Success proves only the existing readiness predicates with scanner-equivalent
group access: it does not prove a successful evdev grab, ingestion, or event
delivery. Even a fresh scanner heartbeat is reader-liveness evidence, not proof
that the downstream PHP consumer accepted a scan.

Inspect recent, current-boot logs **privately**, for example:

```bash
sudo journalctl -b -u waes-queue -u waes-reverb -u waes-scanner \
  -u waes-scheduler.service --since "10 minutes ago" --no-pager -n 100
```

Logs/failed jobs can contain payloads and identifiers. Redact before sharing;
do not paste `.env`, raw QR contents, selections or complete failed-job records
into chat. Do not `queue:retry all`, clear/flush queues, or erase failed jobs
as a diagnostic shortcut; queued jobs may have physical-print side effects.

### Prove the actual broadcast path

The path is:

```text
USB -> evdev bridge -> precinct ingestion -> persisted scan event
    -> ScannerScanEventRecorded -> database queue "broadcasts"
    -> worker -> Reverb 127.0.0.1:8080 -> Nginx -> browser
```

`ScannerScanEventRecorded` implements `ShouldBroadcast` and
`ShouldDispatchAfterCommit`. Its public channel is
**`election.scanner.role-demo-precinct`** for the commissioned scanner. The Echo
listener uses **`ScannerScanEventRecorded`**; with no custom `broadcastAs()`,
the default raw wire event name is **`App\Events\ScannerScanEventRecorded`**.
Its metadata contains station, scan type,
revision, status, document hash and occurrence time—not the raw ballot.
State endpoints and stored scan rows do contain more sensitive data.

In browser developer tools on `/election/role-demo/precinct-tally/public`, verify:

1. A same-origin WebSocket, not a connection to an old router IP or `localhost`.
2. `pusher:connection_established`, not just HTTP 101.
3. Successful subscription to the expected public scanner channel.
4. For an existing observed or newly **authorized** scan, the event/revision
   arrives and the board requests the updated state without adding an extra vote.

The public precinct board polls about every 15 seconds when connected and every
2 seconds when disconnected; polling can hide a broken broadcast path. A fresh
`default` queue heartbeat does not prove `broadcasts` is draining. There is no
special Artisan “broadcast health” command here; inspect this full chain rather
than inventing one. Do not use ingestion to force an event without approval.

## 8. Access point, DNS and captive welcome

The deployed AP is **WPA2-Personal/CCMP**, SSID **WAES**, PH, channel 6. It is
**not open Wi-Fi**. Only the welcome page is password-free after joining.
The operator supplies the existing Wi-Fi password privately.

Fresh setup is in runbook section 6. Its secure interactive recipe derives a PSK
into a root-only file; use the WPA2 example, not `hostapd.conf.example` (open).
Never overwrite the device's real configuration with the repository placeholder.

The setup consists of:

1. `05-waes-ap.network`: systemd-networkd gives `wlp2s0` `10.42.0.1/24`,
   no DHCP client, no IPv6 RA.
2. Private `/etc/hostapd/hostapd.conf` from the WPA2 template.
3. `/etc/waes/dnsmasq-ap.conf`: Wi-Fi-only DHCP `.50`–`.200`, 12-hour leases,
   gateway/DNS `10.42.0.1`, local aliases and dedicated probe overrides.
4. hostapd/dnsmasq drop-ins and `waes-ap-firewall.service` for correct ordering.
5. `/etc/waes/waes-ap.nft` plus
   `/etc/sysctl.d/90-waes-ap-forwarding.conf` for optional uplink sharing.
6. The old station-client `netplan-wpa-wlp2s0.service` is masked; do not
   re-enable a competing Wi-Fi client manager on this interface.

The runbook installs these files and uses targeted `networkctl reload` /
`networkctl reconfigure wlp2s0`. Preserve the original Ethernet Netplan/DHCP
configuration. Do not run broad `netplan apply`, bridge Wi-Fi and Ethernet,
switch to a NetworkManager hotspot profile, or restart all networking from the
only management session.

Non-secret checks, from the device:

```bash
ip -br address
systemctl is-active hostapd dnsmasq waes-ap-firewall
sudo hostapd_cli -i wlp2s0 status
sudo dnsmasq --test --conf-file=/etc/waes/dnsmasq-ap.conf
sudo nft --check -f /etc/waes/waes-ap.nft
sudo nft list table inet waes_ap_forward
sudo nft list table ip waes_ap_nat
```

Do not print `hostapd.conf` or use a command that dumps its PSK. DNS/DHCP must
not serve Ethernet or conflict with the system resolver. Lease file:
`/var/lib/waes-ap-leases/dnsmasq.leases`; inspect privately if needed.
Only dedicated `waes_ap_forward` / `waes_ap_nat` tables are managed—never flush
the host's firewall. Optional NAT permits uplink Internet but blocks forwarded
access to private Ethernet networks; local AES must work without an uplink.

On a physical tablet, join WAES and open `http://10.42.0.1`, then test the local
names using the AP resolver. `/hotspot` acknowledges onboarding, not a second
login or proof of Internet access. Captive pop-ups are OS-dependent and may be
cached for the 12-hour acknowledgment window; use the explicit URL if absent.
There is no wildcard DNS, HTTPS interception or DHCP option 114.
`local-ttl=0` does not clear a phone's captive-state cache.

When a fresh onboarding test is authorized, clear only the `waes-hotspot:`
namespace if necessary, never the entire application cache or sessions. Avoid
disconnecting Ethernet to test offline behavior until another management path
has been verified.

## 9. Boot persistence and controlled shutdown

First compare installed units with the **offline-ap** templates. For a fresh
rebuild or deliberately repairing enablement—not routine inspection—enable:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now mysql nginx php8.5-fpm cups \
  waes-runtime.target waes-scanner.service \
  waes-ap-firewall.service hostapd.service dnsmasq.service
```

`waes-runtime.target` explicitly wants queue, Reverb and scheduler timer;
**enable the scanner separately** so its WantedBy link is present. `PartOf=`
alone does not pull it into the target. Its environment condition must pass.
Do not enable `waes-scheduler.service` as a persistent daemon; the timer runs it.
USB devices may arrive late: the scanner wrapper reconnects, while CUPS retains
saved queues. Neither proves connected/working peripherals.

Inspection:

```bash
systemctl is-enabled mysql nginx php8.5-fpm cups waes-runtime.target \
  waes-scanner.service hostapd dnsmasq waes-ap-firewall
systemctl is-active mysql nginx php8.5-fpm cups waes-queue waes-reverb \
  waes-scanner waes-scheduler.timer hostapd dnsmasq waes-ap-firewall
```

After an **operator-approved reboot**, rediscover DHCP/mDNS, verify the trusted
host, all hardware, fresh natural heartbeats, web/asset/WebSocket access, local
AP/DNS, and authorized physical operation. “Enabled” before reboot is not proof
of post-boot acceptance. Check `timedatectl status`; wrong offline clock/timezone
can affect expiry, audit timestamps and heartbeat interpretation.

For an explicitly requested shutdown:

```bash
sudo systemctl poweroff
```

Wait for the physical device to finish powering off before unplugging it.
SSH closing by itself does not prove power is off.

## 10. Acceptance record, gaps and first actions for the next agent

On 2026-11-18:

- Verified GitHub release deployed cleanly; private configuration integrity
  checked; backup path recorded above.
- 50 focused PHP tests / 932 assertions passed, plus frontend build, TypeScript,
  targeted lint/format and browser WebSocket-option tests.
- Clean test checkout initially lacked ignored signed research fixtures.
  Public source PDFs, the signed bundle/assignment and public trust key were
  supplied for isolated testing; **no private signing key was copied**.
  Do not assume all research tests work from Git alone.
- Real browser checks covered voter root, three officer steps, readiness POST
  and leading-zero handling, desktop/tablet-emulated layout and real Reverb
  connection. Those are not a substitute for physical tablet/print checks.
- The operator confirmed the physical tablet joined WAES, loaded the voter
  keypad and finalized one authorized demo ballot. The thermal receipt matched
  the control number. Thermal jobs 99/100 completed.
- The operator confirmed an actual HP VVPAT print; HP job 101 completed.
  Before confirmation the accepted count remained 1; after confirmation it was
  2, with no pending VVPAT or unfinished CUPS jobs.
- **Physical fault/jam/paper-out and retry acceptance was explicitly deferred.**
  The HP is enclosed and paper was not accessible. No fault was introduced.
  Automated failure/retry coverage does not validate real hardware recovery.

Earlier commissioning included a working physical scanner rescan after a
truncated HID payload. Scanner hardware was later disconnected/reconnected
during deployment; neither that historical success nor an active listener
proves it is connected now.

Repository-wide lint/format issues and inconclusive PHP static-analysis output
were previously reported. Focused checks do not clear those caveats. Historical
dependency audits also reported high/critical advisories; assess updates as a
separate tested change before Internet exposure or sensitive production use.
This hand-off does not certify election-day readiness or application access
control merely because the Wi-Fi is WPA2.

Suggested first actions:

1. Read this hand-off and repository `AGENTS.md`; discover the current LAN
   address and verify the ED25519 fingerprint.
2. Obtain fresh, authorized SSH/sudo access through the operator; no inherited
   terminal credentials are promised.
3. Run only passive inventory first: source SHA/cleanliness, service state,
   hardware presence, natural heartbeats, HTTP and browser WebSocket checks.
4. Preserve the existing MySQL / `www-data` profile, data, secrets and boot setup.
5. Agree on an acceptance context before any print, scan, vote, retry, reset,
   queue replay, network change, reboot or shutdown. Keep the physical
   fault/retry gap explicit until it is actually tested.
