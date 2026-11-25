# Ubuntu offline WAES deployment

This runbook describes the Ubuntu 24.04 deployment commissioned in November 2026: Nginx, PHP 8.5-FPM, MySQL, local Reverb, a standalone Wi-Fi AP with password-free captive onboarding, an HID barcode scanner, HP Laser 108a ballot printing, and a 58 mm receipt printer.

Use the matching templates in `deployment/offline-ap/`. The older `deployment/install-waes-runtime`, `deployment/systemd/`, and `deployment/environment/waes-runtime.env.example` describe a different PostgreSQL/`ace` runtime. Do not mix the two profiles.

For current access details, safe upgrades, runtime diagnostics, and dated acceptance evidence, read [WAES device hand-off](WAES_DEVICE_HANDOFF.md). Fresh-install commands below must not be replayed on an existing installation.

## Profile and boundaries

- Application: `/var/www/aes`, deployed by `ace`; runtime workers use `www-data`.
- Ethernet: `enp1s0`, DHCP. Its address may change when moving networks.
- AP: `wlp2s0`, SSID **WAES**, Philippines, 2.4 GHz channel 6. The commissioned device now uses WPA2-Personal/AES; separate open and WPA2 examples are provided.
- AP address: **10.42.0.1/24**; DHCP pool **10.42.0.50–10.42.0.200**.
- Local application: **http://10.42.0.1**, redirecting to `/election/role-demo/voter` at the `e653552` release.
- Local DNS aliases: `waes.home.arpa` and `waes.consultel.ph`; these overrides apply only through the AP resolver.
- AP clients may use Ethernet Internet connectivity when present. AES itself must remain usable without that uplink.
- Ballots use `HP_Laser_108a`; control-number receipts use `Thermal58`; closeout forms remain file-based.

Adapt interface names, PHP socket paths, radio country/channel, and hardware identifiers to the actual device. Check AP support using `iw list`; this profile used the Intel `iwlwifi` adapter. Never assign an arbitrary static Ethernet address to hide competing DHCP servers.

Choose the Wi-Fi security variant explicitly. The optional open example does not provide wireless encryption; the WPA2 example requires a locally derived key. The welcome page remains password-free onboarding, not a second login or an Internet-access gate. Preserve/add application authentication separately when required by the deployment.

## 1. Back up and establish recovery access

Before changing a headless device, keep a verified Ethernet SSH session open. If IPv4 networks disagree, the device's verified link-local IPv6 address can provide recovery access on the same LAN. Compare the SSH host-key fingerprint against the commissioning record; do not disable host-key checks.

Back up the existing application `.env`, `storage/`, database, Netplan, affected systemd units/drop-ins, AP configuration and firewall rules. Backups containing keys or credentials must be root-only. Record the previous service enable/mask states and arrange a timed rollback before activating Wi-Fi changes.

Do not restart all networking or run a broad `netplan apply` from the only remote session. The AP steps below target only `wlp2s0`.

## 2. Install the application stack

Provision PHP 8.5 from a reviewed package source, Composer 2, and Node 22 (at least 22.12 for the frontend toolchain). Ubuntu's default Node package may be too old. For official binary archives, verify the published SHA-256 checksums before installation.

Run on the Ubuntu device:

```bash
sudo apt-get update
sudo apt-get install -y \
  nginx mysql-server git curl ca-certificates unzip acl \
  php8.5-cli php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml \
  php8.5-curl php8.5-zip php8.5-gd php8.5-intl php8.5-bcmath \
  php8.5-sqlite3 php8.5-imagick \
  ghostscript poppler-utils zbar-tools python3-evdev \
  cups cups-client cups-filters hostapd dnsmasq nftables iw
```

Imagick is needed for QR images; Ghostscript is needed for receipt PDFs and PDF text extraction. The 512 MB PHP-FPM memory profile avoids the default 128 MB limit encountered during precinct initialization.

Check out the intended reviewed release into `/var/www/aes`. Keep the application key, database and election artifacts on upgrades; do not run initial provisioning against an existing installation.

```bash
cd /var/www/aes
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs
node --version
```

### Initial credentials and MySQL

For a fresh installation only, generate secrets in environment variables, never in command arguments or logs:

```bash
export AES_DB_PASSWORD=$(openssl rand -hex 32)
export AES_REVERB_KEY=$(openssl rand -hex 16)
export AES_REVERB_SECRET=$(openssl rand -hex 32)
```

Create `.env` from `.env.example`, merge the non-secret overlay in `deployment/offline-ap/application.env.example`, and set:

- `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=aes`, `DB_USERNAME=aes`.
- `DB_PASSWORD` from `AES_DB_PASSWORD`.
- A unique `REVERB_APP_ID`; `REVERB_APP_KEY` and `REVERB_APP_SECRET` from their generated variables.
- The current Ethernet IP in `REVERB_ALLOWED_ORIGINS` if browsers will use that literal IP.

Use an editor or a script reading the environment to write these values without displaying them. Give `.env` mode `0640`, owned by `ace:www-data`. Never overwrite an existing `APP_KEY` during an upgrade.

Provision the dedicated MySQL account without putting its password on the process command line:

```bash
sudo --preserve-env=AES_DB_PASSWORD python3 - <<'PY'
import os
import re
import subprocess

password = os.environ["AES_DB_PASSWORD"]
if not re.fullmatch(r"[0-9a-f]{64}", password):
    raise RuntimeError("Expected a generated database password")
sql = (
    "CREATE DATABASE aes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    f"CREATE USER 'aes'@'127.0.0.1' IDENTIFIED BY '{password}';"
    "GRANT ALL PRIVILEGES ON aes.* TO 'aes'@'127.0.0.1';"
)
result = subprocess.run(["mysql", "--batch"], input=sql, text=True, capture_output=True)
if result.returncode:
    raise RuntimeError("MySQL provisioning failed; inspect locally without exposing SQL credentials")
PY
unset AES_DB_PASSWORD AES_REVERB_KEY AES_REVERB_SECRET
```

This deliberately does not replace an existing database or account. Leave unrelated PostgreSQL services alone.

Finish fresh provisioning and build assets while dependencies are available:

```bash
php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction
npm ci
npm run build
php artisan optimize --no-interaction
```

Do not seed the default test user. Generated frontend assets and fonts are served locally; deployment/build-time downloads do not make an installed appliance depend on Internet access.

## 3. Permissions, Nginx and persistent workers

Give the web/runtime account read access to source and write access only where Laravel needs it:

```bash
sudo chown -R ace:www-data /var/www/aes
sudo chmod -R g+rX,g-w,o-rwx /var/www/aes
sudo setfacl -R -m u:ace:rwX,u:www-data:rwX storage bootstrap/cache
sudo setfacl -R -d -m u:ace:rwx,u:www-data:rwx storage bootstrap/cache
sudo chmod 0640 .env
```

Install `deployment/offline-ap/nginx-aes.conf.example` as `/etc/nginx/sites-available/aes`, and the existing `deployment/nginx/waes-reverb.conf.example` as `/etc/nginx/snippets/waes-reverb.conf`. Enable the site, disabling only the fresh Nginx default symlink if necessary; preserve other virtual hosts and backups. Serve only `public/`, deny dotfiles, and execute only `index.php`.

Install the PHP-FPM overlay and this profile's units:

```bash
sudo install -m 0644 deployment/offline-ap/php-fpm.ini.example /etc/php/8.5/fpm/conf.d/99-aes.ini
sudo install -m 0644 deployment/offline-ap/systemd/waes-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo nginx -t
sudo systemd-analyze verify /etc/systemd/system/waes-{queue,reverb,scanner,scheduler}.service
sudo systemctl enable --now mysql nginx php8.5-fpm waes-runtime.target waes-scanner.service
sudo systemctl restart php8.5-fpm
sudo systemctl reload nginx
```

The scanner unit is intentionally skipped until `/etc/waes/scanner.env` exists. Queue, scheduler and scanner order against MySQL, not PostgreSQL. Only the scanner process receives supplementary `input` access; do not add the entire web server or login account to that group.

Always run maintenance from `/var/www/aes`. Starting a `www-data` PHP process with `/home/ace` as its working directory caused `posix_spawn(): Permission denied`, because that account cannot traverse the private home directory.

## 4. Configure printers

Identify hardware and exact CUPS URIs before creating queues:

```bash
lsusb
sudo lpinfo --include-schemes usb -v
lpstat -p -v
```

Use USB serial-based URIs when available. Generic thermal firmware may advertise incomplete or misleading model strings: correlate the URI with USB manufacturer/product IDs and the physical paper width. Do not infer a driver from a misleading model substring.

### HP Laser 108a

The commissioned device used USB ID `03f0:e82a` and HP's **Laser 10x Series** ULD driver, not a generic PCL or HPLIP queue.

- HP-hosted archive: `https://ftp.hp.com/pub/softlib/software13/printers/CLP150/uld-hp_V1.00.39.12_00.15.tar.gz`.
- SHA-256 of the inspected archive: `cebb9b7b6125e7406634bb9c2a98b01477d1e11d66c7c90474669de9927bc91d`.
- Install `libcupsimage2t64`; inspect the archive and install only the needed x86-64 `rastertospl`, `libscmssc.so`, `HP_Laser_10x_Series.ppd`, and `10xsc.cts` resources. The filter goes under `/usr/lib/cups/filter/`; its shared library uses `/opt/smfp-common/printer/lib/`. Register the PPD/model resources under `/usr/share/cups/model/uld-hp/`.
- Create queue **HP_Laser_108a** with the discovered HP USB URI, A4, grayscale/600 DPI, no sharing, and retry-job error policy.
- If `ipp-usb` prevents discovery, inspect its status first. Use a model-specific exclusion only when needed; do not globally disable support for other USB printers.

Verify linked libraries and perform offline PDF conversion before an authorized physical test. Installing drivers does not itself commission AES's per-precinct printer-readiness gate.

```bash
read -r -p "Paste the verified HP USB URI: " HP_URI
sudo lpadmin -p HP_Laser_108a -E -v "$HP_URI" \
  -P /usr/share/cups/model/uld-hp/HP_Laser_10x_Series.ppd \
  -o PageSize=A4 -o Resolution=600dpi \
  -o printer-is-shared=false -o printer-error-policy=retry-job
```

### Xprinter-compatible 58 mm receipts

The thermal device used USB ID `0483:070b`, generic manufacturer Xprinter, and a confirmed **58 mm / approximately 2.25-inch** roll. It does not provide a useful model/serial identity.

The reviewed source driver is `https://github.com/klirichek/zj-58`, commit `64743565df4379098b68a197d074c86617a8fc0a`. Install `build-essential cmake libcups2-dev libcupsimage2-dev`, build as the deployment user, not root, and use `-DRASTER_TYPE=Old`. Install the built `rastertozj` into `/usr/lib/cups/filter/` and matching `zj58.ppd` into `/usr/share/cups/model/consultel/` manually; the upstream install target restarts CUPS unnecessarily.

Create **Thermal58** with its discovered non-HP USB URI and these queue defaults:

```bash
read -r -p "Paste the verified thermal USB URI: " THERMAL_URI
sudo lpadmin -p Thermal58 -E -v "$THERMAL_URI" \
  -P /usr/share/cups/model/consultel/zj58.ppd -o PageSize=X48MMY65MM \
  -o orientation-requested-default=3 -o print-scaling-default=none \
  -o printer-is-shared=false -o printer-error-policy=retry-job
lpoptions -p Thermal58 -l
```

The matching PPD must already be supplied when creating the queue. Leave cutter and cash-drawer controls disabled.

**Portrait (`3`) is required.** The receipt PDF is 164 × 120 points; without an explicit queue default, CUPS can auto-rotate application jobs even though a manual portrait test succeeded.

The renderer centers the number and fine print on the 58 mm page while preserving number font size and leading zeros. Do not reuse the earlier 100-point, x=4 layout: it clipped the first digit against the driver's hardware margin.

The application overlay routes only control-number receipts to Thermal58. Keep ballots on the HP and closeout forms file-based unless separately requested.

### AES printer readiness

After selecting CUPS and the actual HP queue, run the normal device-health check in the relevant precinct context. `CupsBallotPrinter` requires a passing report whose printer adapter is `cups-printer`, status is `ready`, and name matches the selected queue. A stale simulated report causes file fallback. Never hand-edit `passed` or bypass this gate.

The file printer's `status=printed` means PDF generation, not paper output. Confirm real CUPS job IDs and job-state, then have a person inspect paper/QR readability. A reprint must reuse the existing PDF and record a separate audit event; do not create or deposit another vote.

## 5. Commission the HID barcode scanner

Find its stable keyboard-event path:

```bash
lsusb
ls -l /dev/input/by-id/
```

Keep USB HID mode if the model's serial-mode programming instructions are unavailable. `scripts/waes-scanner-service` captures only the configured scanner through evdev, exclusively grabs it, and pipes completed payloads into `election:precinct-ballot-scanner-ingest`. Scanner keystrokes should not also type into tty1.

Copy `deployment/offline-ap/scanner.env.example` to `/etc/waes/scanner.env`, substitute the real scanner by-id path, and use owner `root:www-data`, mode `0640`. Set the same device path in application `.env`; keep station `role-demo-precinct` and ingestion `precinct` for the role-demo precinct-tally screen.

```bash
cd /var/www/aes
php artisan config:cache --no-interaction
sudo systemctl enable --now waes-scanner.service
sudo journalctl -u waes-scanner.service --no-pager -n 20
sudo -u www-data php artisan election:runtime-check --no-interaction
```

An active listener can be retrying an unplugged device. Confirm a fresh scanner heartbeat and an actual accepted or duplicate event on `/election/role-demo/precinct-tally`. The bridge reconnects when the same device returns.

If a scan lacks `truth://`, compare it with the stored QR/PDF before blaming the ballot. A truncated HID payload was observed; a physical rescan succeeded. Never reconstruct missing payload text, manually inject votes, or weaken validation to accept a truncated scan.

## 6. Activate the standalone Wi-Fi AP

Perform this from the preserved Ethernet session with backup/rollback prepared. These commands do not change Ethernet addressing or replace the original Netplan file.

### Prepare a private WPA2 configuration

For a fresh WPA2 setup, create `/root/waes-hostapd.conf` from `hostapd-wpa2.conf.example` and substitute its placeholder privately. The example intentionally cannot start until a real key is supplied. Keep existing device credentials on updates; do not overwrite a secured device with the open example.

This recipe prompts through the controlling terminal, never through command arguments. It stores only a derived PSK in a root-only file:

```bash
sudo python3 - <<'PY'
import getpass
import hashlib
import hmac
import os
from pathlib import Path
import re
import tempfile

with open("/dev/tty", "r+") as terminal:
    if not os.isatty(terminal.fileno()):
        raise RuntimeError("A secure interactive terminal is required")
    os.environ["WAES_WIFI_PASSWORD"] = getpass.getpass("New WAES Wi-Fi password: ", stream=terminal)
    confirmation = getpass.getpass("Confirm WAES Wi-Fi password: ", stream=terminal)
if not re.fullmatch(r"[\x20-\x7e]{8,63}", os.environ["WAES_WIFI_PASSWORD"]):
    raise RuntimeError("WPA2 requires 8-63 printable ASCII characters")
if not hmac.compare_digest(os.environ["WAES_WIFI_PASSWORD"], confirmation):
    raise RuntimeError("Passwords did not match")
os.environ["WAES_WIFI_PSK"] = hashlib.pbkdf2_hmac(
    "sha1", os.environ.pop("WAES_WIFI_PASSWORD").encode("ascii"), b"WAES", 4096, 32
).hex()
template = Path("/var/www/aes/deployment/offline-ap/hostapd-wpa2.conf.example").read_text()
configuration = template.replace("SET_LOCALLY_DO_NOT_COMMIT_REAL_KEYS", os.environ.pop("WAES_WIFI_PSK"))
with tempfile.NamedTemporaryFile("w", dir="/root", prefix=".waes-hostapd-", delete=False) as output:
    output.write(configuration)
    private = Path(output.name)
private.chmod(0o600)
os.replace(private, "/root/waes-hostapd.conf")
PY
```

Use the private file for WPA2 installation below. Only if an open AP is explicitly wanted, select `hostapd.conf.example` instead. Wi-Fi password changes briefly disconnect clients; forget the previous WAES profile and reconnect with the new password. Never copy the resulting private file or key back into Git.

```bash
PROFILE=/var/www/aes/deployment/offline-ap
HOSTAPD_CONFIG=/root/waes-hostapd.conf
sudo install -d -o root -g www-data -m 0750 /etc/waes
sudo install -d /etc/hostapd /etc/systemd/system/hostapd.service.d /etc/systemd/system/dnsmasq.service.d
sudo install -m 0644 "$PROFILE/05-waes-ap.network" /etc/systemd/network/05-waes-ap.network
sudo install -m 0600 "$HOSTAPD_CONFIG" /etc/hostapd/hostapd.conf
sudo install -m 0644 "$PROFILE/dnsmasq-ap.conf.example" /etc/waes/dnsmasq-ap.conf
sudo install -m 0644 "$PROFILE/hostapd.service.conf" /etc/systemd/system/hostapd.service.d/waes-ap.conf
sudo install -m 0644 "$PROFILE/dnsmasq.service.conf" /etc/systemd/system/dnsmasq.service.d/waes-ap.conf
sudo install -m 0644 "$PROFILE/waes-ap.nft" /etc/waes/waes-ap.nft
sudo install -m 0644 "$PROFILE/90-waes-ap-forwarding.conf" /etc/sysctl.d/90-waes-ap-forwarding.conf
sudo install -d -o dnsmasq -g "$(id -gn dnsmasq)" -m 0750 /var/lib/waes-ap-leases
sudo dnsmasq --test --conf-file=/etc/waes/dnsmasq-ap.conf
sudo nft --check -f /etc/waes/waes-ap.nft
sudo systemctl mask --now netplan-wpa-wlp2s0.service
sudo systemctl unmask hostapd.service
sudo systemctl daemon-reload
sudo networkctl reload
sudo networkctl reconfigure wlp2s0
sudo nmcli radio wifi on
sudo systemctl enable --now waes-ap-firewall.service hostapd.service dnsmasq.service
```

The scanner/runtime step installs `waes-ap-firewall.service` along with the other profile units. If performing AP-only setup, install that unit explicitly first.

The profile avoids the DNS-port conflict by listening on `10.42.0.1`, not the system resolver's loopback addresses. The dnsmasq service drop-in suppresses resolver registration hooks so host DNS does not loop back into itself. DHCP remains confined to `wlp2s0`; it must never compete with Ethernet's DHCP server.

`local-ttl=0` prevents resolver-side caching of locally supplied portal records. It does not override a phone's own captive-state cache. A client acknowledged within the 12-hour onboarding window normally receives a success probe instead of another pop-up. When forcing a fresh onboarding test, clear only the `waes-hotspot:` cache namespace—not the entire application cache, sessions, votes, or scan history.

Only dedicated `waes_ap_forward` and `waes_ap_nat` tables are managed. Do not flush the host's firewall. IPv4 forwarding/NAT allows AP clients through `enp1s0` when an uplink exists; it blocks forwarded access to private Ethernet networks and does not bridge DHCP domains.

## 7. Local URLs, WebSockets and welcome portal

The server publishes Reverb events to **127.0.0.1:8080**, independent of Internet DNS. Nginx proxies `/app/` and `/apps/`. Browser WebSockets use the current page hostname/protocol/port through `browser-websocket-options.ts`; do not rebuild a fixed router IP into the client. Keep a bounded `REVERB_ALLOWED_ORIGINS` list and test the actual `pusher:connection_established` event, not just HTTP 101.

The password-free welcome page is **http://10.42.0.1/hotspot**. Supported probe domains are listed in `config/hotspot.php`; keep the DNS template synchronized with that list.

- A new AP client receives an HTTP redirect from its supported probe to `/hotspot`.
- The local, script-free page asks for no credentials.
- **Connect to WAES** acknowledges onboarding and opens `/election/role-demo`.
- Acknowledgment is cached locally for up to 12 hours, scoped to the real socket address and available Wi-Fi ARP identity. Forwarded-IP headers do not confer AP-client status.
- Normal `/` redirects to the voter UI at `/election/role-demo/voter`. Ethernet clients are not captive-portal clients.

Only dedicated probe-domain DNS records are overridden. No wildcard DNS, normal-browsing redirect, HTTPS interception, or Internet authentication gate is added. Probe completion means onboarding is complete; it does not prove upstream Internet availability.

No DHCP option 114 is advertised: the standards-based CAPPORT API requires trusted HTTPS. Pop-ups remain OS-dependent and may be suppressed by captive-detection caches, private DNS, VPNs or different probes. Forget/rejoin WAES for a fresh-device test; the manual IP/portal URL remains the fallback. Browser-camera access may require a separate local HTTPS deployment; USB scanning does not.

## 8. Verification and acceptance

```bash
systemctl is-enabled nginx mysql php8.5-fpm cups waes-runtime.target waes-scanner hostapd dnsmasq waes-ap-firewall
systemctl is-active nginx mysql php8.5-fpm cups waes-queue waes-reverb waes-scheduler.timer hostapd dnsmasq waes-ap-firewall
sudo hostapd_cli -i wlp2s0 status
ip -br address
lpstat -p -v
curl --fail http://10.42.0.1/up
```

Confirm AP IP `10.42.0.1`, an unchanged Ethernet lease, no DNS/DHCP listeners serving Ethernet, working local-name resolution, and active worker/scheduler heartbeats. Database migrations must all be applied. Treat historical boot-time connection errors separately from current failures.

Use a phone/tablet to join **WAES**, disable mobile data, and open the role-demo page. Verify captive welcome/button behavior, a newly generated receipt, a ballot print, and a real Truth QR scan. Ask before sending test print jobs. CUPS `completed` is not a substitute for physical paper/QR confirmation.

After Wi-Fi access is confirmed, disconnect only the Ethernet uplink and repeat local page, asset and WebSocket checks. Internet failure is expected in this test; local AES must still work. Do not disconnect the only management connection until another verified path exists. Validate a real reboot separately with explicit operator approval.

### Automated checks

Run tests in an isolated checkout with its own test environment and development dependencies, not the cached production application. Feature tests use `RefreshDatabase`; clear configuration caches and use the repository's in-memory SQLite/test-storage settings.

```bash
php artisan config:clear --no-interaction
php artisan test --compact \
  tests/Feature/HotspotPortalTest.php \
  tests/Feature/ControlNumberReceiptLayoutTest.php \
  tests/Feature/Election/ControlNumberReceiptPrintingTest.php \
  tests/Feature/OfflineDeploymentProfileTest.php
node --experimental-strip-types --test tests/Frontend/browser-websocket-options.test.mjs
npm run types:check
```

The combined portal, receipt and deployment-profile suite passed 31 tests and 239 assertions; the browser host-selection suite passed 3 tests. Controller static analysis, formatting, route caching, TypeScript checking, DNS syntax, systemd unit verification, and frontend production build also passed.

## 9. Updates, rollback and known limitations

Deploy reviewed source while preserving `.env`, the existing application key, database and election artifacts. Reinstall locked production dependencies, build frontend assets when needed, rebuild Laravel caches, and restart only affected long-running workers. Changes to `VITE_*` values require rebuilding assets.

Restore pre-change files and recorded service mask/enable states on failure. Rebuild application caches after restoring source/config and restart only the affected service. Remove only this profile's firewall tables when undoing it; never flush unrelated firewall rules. Keep Ethernet DHCP and the original Netplan configuration intact.

This appliance's guarded deployments stored protected snapshots under `/var/lib/waes-ap/backups/` and `/var/lib/waes-portal/backups/`. Successful deployments disarmed their timers and wrote `committed` markers; their automatic rollback scripts intentionally skip committed snapshots. Do not remove markers or rerun old staging scripts blindly.

Known commissioning findings:

- Two reachable DHCP servers handed out different subnets. Removing the competing router allowed the Ethernet client to obtain the correct lease; no static host address was needed. Reserve the desired wired DHCP lease on the selected router for address stability.
- The built browser bundle and Reverb origins initially contained an old private IP. Same-origin browser options plus loopback backend publishing removed that dependency.
- Optional Internet sharing is configured, but a phone join and actual uplink test are distinct from server-only checks.
- Printers/scanner must be connected after moving the appliance. Saved queues can report idle even with USB hardware absent.
- Dependency audits reported high/critical advisories, including Laravel. Review and update dependencies in a separate tested change before Internet-facing or sensitive production use; this deployment did not silently change lockfiles.

Never commit `.env`, caches, session/CSRF material, private keys, downloaded driver binaries, backups or election records.
