# WAES Linux Appliance Runtime Report

Prepared by Warp (agent) for Codex. Read-only investigation of the physical appliance at `waes.consultel.ph` (LAN `192.168.2.70`). No device state was changed during this investigation. All command output below is sanitized: no `.env` secrets, Reverb credentials, passwords, private keys, or voter data are included.

## 1. OS, architecture, PHP, service user, app path, web server, supervision

```text
$ cat /etc/os-release
PRETTY_NAME="Ubuntu 24.04.4 LTS"
VERSION="24.04.4 LTS (Noble Numbat)"
VERSION_CODENAME=noble

$ uname -a
Linux waes.consultel.ph 6.8.0-142-generic #142-Ubuntu SMP PREEMPT_DYNAMIC ... x86_64 GNU/Linux

$ lscpu
Architecture: x86_64
CPU(s): 4
Model name: Intel(R) Celeron(R) N5105 @ 2.00GHz

$ php -v
PHP 8.4.25 (cli) (built: Aug 28 2026) — /usr/bin/php

$ ls -la /var/www/aes | head -5
drwxrwxr-x 21 ace ace 4096 ... .
stat -c "%U:%G" /var/www/aes → ace:ace
```

- **App path**: `/var/www/aes`, owned by `ace:ace` (not `www-data`).
- **Web server**: nginx 1.24.0, active, listening on port 80 only (see §5).
- **PHP execution**: `php8.4-fpm.service` (active), **not** `php artisan serve`, not Octane.
- **PHP-FPM pool** (`/etc/php/8.4/fpm/pool.d/www.conf`):
  ```
  user = ace
  group = ace
  listen = /run/php/php8.4-fpm.sock
  listen.owner = www-data
  listen.group = www-data
  ```
  The pool **runs PHP as `ace`**, not `www-data`. The socket itself is owned by `www-data` (nginx's user) purely so nginx can connect to it — the actual PHP worker process, and therefore all file writes from Laravel, happen as `ace`.
- **SSH/admin user**: `ace` (uid 1000), groups: `ace, adm, cdrom, sudo, dip, plugdev, lxd, lpadmin, input`.
- **Process supervision**: plain systemd only. No supervisord, no custom `waes-*` units (see §7). Background services present are stock Ubuntu/desktop daemons (`colord`, `fwupd`, `thermald`, `upower`, `multipathd`, `unattended-upgrades`, `tpdaemon` for a Turboprint-driven printer, `ipp-usb`) plus `postgresql@16-main`, `hostapd`, `dnsmasq`, `cups`, `nginx`, `php8.4-fpm`.

## 2. Boot services, queue workers, scheduler/cron, scanner ingestion

```text
$ systemctl list-unit-files | grep -i waes
(no output)

$ ls /etc/systemd/system/ | grep -i waes
(no output)

$ ls /etc/waes
ls: cannot access '/etc/waes': No such file or directory

$ ps aux | grep -i reverb
(no output)

$ ps aux | grep -i "queue:work"
(no output)

$ crontab -l   # as ace
no crontab for ace

$ cat /etc/cron.d/*   # stock Debian/Ubuntu entries only
30 3 * * 0 root ... e2scrub_all_cron
10 3 * * * root ... e2scrub_all
09,39 * * * * root ... php sessionclean
5-55/10 * * * * root ... debian-sa1
59 23 * * * root ... debian-sa1

$ php artisan tinker --execute '... jobs/failed_jobs count'
0 pending, 0 failed
```

- **None** of the proposed `waes-queue.service`, `waes-reverb.service`, `waes-runtime.target`, `waes-scheduler.service/.timer` are installed. No Reverb process, no `queue:work` process, no scheduler cron/timer of any kind runs on this device today.
- The `jobs` table is empty and stays empty — not because a worker drains it, but because **no listener in the codebase implements `ShouldQueue`** (confirmed via `grep` in `app/Listeners`), so all event listeners (including `PrintIssuedRoleDemoControlNumber`) execute synchronously in the HTTP request. The missing queue worker is currently a dormant gap, not an active bug — but any future `ShouldQueue` job or `Bus::dispatch()`/queued mailable would silently never run.
- No scanner ingestion process (`canvassing-scanner-ingest`, `precinct-ballot-scanner-ingest`, or the evdev bridge script) is currently running as a persistent service; it is invoked ad hoc over SSH per prior sessions, not boot-managed.

## 3. QR scanner: USB identity, connection mode, permissions

```text
$ lsusb
Bus 001 Device 002: ID 0581:0215 Racal Data Group
Bus 001 Device 004: ID 03f0:e82a HP, Inc HP Laser 108a
Bus 001 Device 009: ID 0483:070b STMicroelectronics USB Printer Port
...

$ cat /proc/bus/input/devices   (scanner entry)
I: Bus=0003 Vendor=0581 Product=0215 Version=0110
N: Name="Scanner Barcode"
H: Handlers=sysrq kbd event3 leds

$ udevadm info --query=all --name=/dev/input/event3
S: input/by-id/usb-Scanner_Barcode_0215-event-kbd
ID_INPUT_KEYBOARD=1
ID_BUS=usb
ID_TYPE=hid
ID_USB_DRIVER=usbhid
(no serial/tty properties of any kind)

$ ls -la /dev/input/
crw-rw---- 1 root input 13, 67 ... event3
(all /dev/input/event* nodes: root:input, mode 660)

$ ls -la /dev/input/by-id/
usb-Scanner_Barcode_0215-event-kbd -> ../event3
```

- **VID:PID**: `0581:0215`. `lsusb`'s generic vendor-database name ("Racal Data Group") is a red herring from a reused/rebranded VID; the kernel's own HID descriptor correctly reports it as `"Scanner Barcode"`.
- **Connection mode**: USB HID keyboard class **only**. `ID_USB_INTERFACES=:030101:030100:` (both HID interfaces are keyboard/boot-keyboard class). There is **no** CDC-ACM/serial interface exposed — this scanner cannot be switched to a virtual-COM mode at the USB descriptor level as currently enumerated.
- **Stable path**: `/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd` (symlink to whatever `/dev/input/eventN` the kernel assigns on that boot/replug — the raw `eventN` number is **not** stable across reconnects, the `by-id` symlink is).
- **Permissions**: `root:input`, mode `0660`. `ace` is a member of the `input` group, so `ace` can open the device node directly (confirmed working via the evdev bridge script in prior sessions).
- **No custom udev rule** targets this device (`/etc/udev/rules.d/` has only `59-smfp_hp.rules`, unrelated). It relies entirely on the stock `usbhid` kernel driver's default `root:input 0660` permissions.

## 4. How scanner data appears on Linux

This was established through extensive live testing in prior sessions on this exact device (not re-derived here, since the hardware/config hasn't changed):

- The scanner is a **keyboard-wedge** device: it emits standard Linux **binary input events** (`struct input_event` records) on `/dev/input/event3`, exactly like a physical keyboard. It does **not** produce a plain text stream on its own — there is no tty/serial layer involved.
- Because it is HID-class only, its keystrokes are delivered by the kernel to whatever has **console/input focus** (an active local X11/Wayland session or the currently active virtual terminal). On this appliance there is no local graphical session most of the time (only an SSH session with no seat, confirmed via `loginctl list-sessions` showing session 201 with `SEAT -`), so keystrokes typed at the physical scanner have nowhere conventional to land in an SSH shell — they must be read directly from the evdev device node.
- The project's `scripts/canvassing-scanner-bridge.py` solves this by opening `/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd` directly via the `evdev` Python module, decoding US-QWERTY keycodes, and emitting one line per scan on stdout for piping into `election:canvassing-scanner-ingest` / `election:precinct-ballot-scanner-ingest`.
- **Terminator**: inconsistent across configurations observed in the field. Most scans terminate with Enter (`CR`/`LF`, `KEY_ENTER`/`KEY_KPENTER`), but at least one configuration observed in this project's history sent a raw `ETX` (`0x03`, i.e. Ctrl-C) as the suffix instead of Enter. On a real allocated TTY with default line-discipline signal handling (`ISIG`), that `0x03` byte is intercepted by the kernel as `SIGINT` and kills the foreground process **before** it ever reaches the application — this is why the `election:*-scanner-ingest` commands treat `CR`, `LF`, **and** `ETX` all as valid line terminators (see `app/Console/Commands/IngestCanvassingScannerPayloads.php` / `IngestPrecinctBallotScannerPayloads.php`), and why the evdev-bridge approach (which never goes through a tty line discipline at all) is the robust path, not a raw `ssh -t ... | php artisan ...` pipeline.
- **Unplug/reconnect behavior**: on replug, the kernel assigns a new `eventN` node (observed jumping between `event3` here and other indices in earlier sessions depending on enumeration order), but the `by-id` symlink (`usb-Scanner_Barcode_0215-event-kbd`) is regenerated pointing at the new node, so any consumer that opens the device via the `by-id` path (as the bridge script does) survives a replug transparently — a consumer hard-coded to `/dev/input/eventN` would not.

## 5. Nginx: HTTPS and WebSocket proxying

```text
$ ls -la /etc/nginx/sites-enabled/
aes -> /etc/nginx/sites-available/aes   (only site enabled)

$ cat /etc/nginx/sites-available/aes
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/aes/public;
    ...
    location = /generate_204 { return 302 http://waes.consultel.ph/election/role-demo/voter; }
    location = /gen_204 { return 302 ...; }
    location = /hotspot-detect.html { return 302 ...; }
    location = /connecttest.txt { return 302 ...; }
    location = /ncsi.txt { return 302 ...; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}

$ grep -rl "listen 443\|ssl_certificate" /etc/nginx/
/etc/nginx/snippets/snakeoil.conf   (stock unused snippet only)

$ which certbot   → (not installed)
```

- **There is no HTTPS server block at all.** The site serves plain HTTP on port 80 only. The only TLS-related file on the box is the unused stock `snakeoil.conf` snippet; certbot/Let's Encrypt tooling is not installed.
- **No `/app/` or `/apps/` WebSocket proxy locations exist** — the `deployment/nginx/waes-reverb.conf.example` snippet has never been included into the live config.
- The `/generate_204`, `/gen_204`, `/hotspot-detect.html`, `/connecttest.txt`, `/ncsi.txt` redirects are OS captive-portal probe endpoints (Android/iOS/Windows), all redirected to the role-demo voter entry point — this is a deliberate captive-portal UX so a device joining the appliance's own Wi-Fi hotspot (see below) is bounced straight into the voting flow.
- This appliance **also runs its own Wi-Fi access point** for voter devices: `hostapd` (SSID `WAES2028`, open/no WPA, channel 6, interface `wlo1`) + `dnsmasq` (`dhcp-range=192.168.88.10-192.168.88.250`, `address=/#/192.168.88.1` — wildcard DNS pointing every hostname at itself, the standard captive-portal trick). This is separate from the wired/LAN interface used for SSH access (`192.168.2.70`).

## 6. CUPS queues and permissions

```text
$ systemctl is-active cups → active

$ lpstat -p -d
printer Canon_G1010_series is idle.
printer HP is idle.
printer PDF is idle.
printer Thermal80 now printing Thermal80-252.  Waiting for printer to become available.
system default destination: HP

$ lpstat -v
device for HP: usb://HP/Laser%20103%20107%20108?serial=CNB1V2S7TF
device for Thermal80: usb://Printer%20/USB%20Printer%20Port?serial=3C78EF0F0A06
device for Canon_G1010_series: tpu://Canon/G1010_series/SN=829EBE
device for PDF: cups-pdf:/

$ getent group lpadmin lp
lpadmin:x:116:root,ace
lp:x:7:root

$ lpstat -o Thermal80
Thermal80-252  ace  3072  Sun Sep 27 15:10:14 2026
Thermal80-253  ace  3072  Sun Sep 27 15:13:36 2026
Thermal80-254  ace  3072  Sun Sep 27 15:14:17 2026
Thermal80-256  ace  3072  Sun Sep 27 15:16:42 2026
```

- **Live operational issue observed during this investigation** (not fixed, per instructions): the `Thermal80` queue has **4 stuck jobs** dated ~15 hours before this report, all queued within ~6 minutes of each other shortly after the most recent reboot (`Sun Sep 27 14:44:08 2026`, confirmed via `last reboot`). The queue is not erroring — it just shows "Waiting for printer to become available" indefinitely. This strongly suggests the thermal printer did not re-enumerate cleanly after that reboot (or was briefly disconnected) and nobody power-cycled it or ran `cancel`/`cupsenable` afterward. CUPS gives **no proactive alert** for this state; it's only visible by running `lpstat`.
- `ace` is in `lpadmin` (printer administration) but **not** in `lp` (raw local-printer-device group) — irrelevant here since both printers are accessed over USB via CUPS backends (`usb://...`), not raw `/dev/usb/lp*` nodes (which don't exist on this box: `ls /dev/usb/lp*` → no such file).
- Both `HP` (laser) and `Thermal80` use vendor/generic PPDs already installed system-wide (`Thermal80`'s options confirm the `X70MMY65MM`-family media sizes and 203×203dpi resolution consistent with prior findings that the physical roll is a 2.25in/~58mm class, not the 80mm the queue name implies).

## 7. Do the proposed `deployment/systemd/` units match reality?

**No — several assumptions are materially wrong.**

| Assumption in `deployment/` | Reality on the device |
| --- | --- |
| `User=www-data` / `Group=www-data` in all four unit files | PHP actually runs as `ace:ace` (confirmed via php-fpm pool config and file ownership of `/var/www/aes`) |
| `EnvironmentFile=-/etc/waes/runtime.env` | `/etc/waes/` does not exist |
| `WorkingDirectory=/var/www/aes` | ✅ Correct |
| `ExecStart=/usr/bin/php ...` | ✅ Correct binary path |
| `ReadWritePaths=/var/www/aes/storage /var/www/aes/bootstrap/cache` | Consistent with a `www-data`-run process needing narrow write access under `ProtectSystem=full`; **but** since the real runtime user (`ace`) already owns the entire `/var/www/aes` tree, `ProtectSystem=full` + narrow `ReadWritePaths` would be a **behavior change** (currently nothing restricts writes) — needs deliberate testing before enabling, not just a user/group swap |
| `waes-runtime.env.example`: `APP_ENV=production`, `BROADCAST_CONNECTION=reverb`, `REVERB_SCHEME=https`, `REVERB_PORT=443` | Live `.env` has `APP_ENV=local`, `APP_DEBUG=true`, `BROADCAST_CONNECTION=log` (Reverb is not in use at all), `APP_URL=http://localhost` |
| `waes-reverb.conf.example` nginx include (`/app/`, `/apps/` → `127.0.0.1:8080`) | Not included anywhere in the live nginx config; no HTTPS server block exists for it to live in even if it were |
| Implied HTTPS-only Reverb (`REVERB_SCHEME=https`) | nginx has no `listen 443` anywhere on the box; certbot isn't installed |
| None of the four units currently installed | Confirmed via `systemctl list-unit-files` and `ls /etc/systemd/system/` — zero `waes-*` units exist |

The `deployment/` directory is a **design proposal that has never been applied to this physical appliance**. The device currently runs on ad hoc SSH-driven deployment (`git pull` + `composer install` + `npm run build`, as done repeatedly in this project's operational history) with synchronous PHP-FPM request handling only — no background workers, no broadcasting, no HTTPS.

## 8. Deployment / boot / permission / networking / recovery lessons learned

- **SSH host key instability on the public hostname.** `waes.consultel.ph`'s SSH host key has changed multiple times across sessions, sometimes to a key that turns out to be a genuinely different, unrelated host (SSH auth outright refused, no password fallback) rather than the same device on a new IP. The reliable fallback has been to have someone physically on the Consultel office LAN connect via the stable internal IP (`192.168.2.70`) directly. Treat unexpected host-key changes on the public hostname with suspicion, not routine trust-on-first-use, until verified.
- **Wi-Fi AP / DHCP self-hosting.** The appliance is both the web server *and* the voters' Wi-Fi access point/DHCP/DNS server (`hostapd` + `dnsmasq` on `wlo1`, subnet `192.168.88.0/24`). This is a separate network from the wired LAN (`192.168.2.0/24`) used for administration — don't confuse the two when troubleshooting connectivity.
- **USB device re-enumeration.** All USB peripherals (scanner, both printers) can shift `/dev/...` node numbers across reboots/replugs; only `by-id` paths are stable. Anything that hardcodes `/dev/input/eventN` or similar will silently break after a reboot.
- **CUPS fails silently.** A disconnected/misbehaving printer does not surface as an obvious error; jobs just accumulate in "waiting for printer to become available" indefinitely. `lpstat -p -d` / `lpstat -o <queue>` needs to be part of any post-reboot health check.
- **Physical thermal-printer firmware quirks** (documented at length in this project's history, not re-verified in this pass): a full power cycle of the thermal printer has previously been required to clear garbled/corrupted output after heavy back-to-back testing; the print head's real usable width/length is narrower than the CUPS-declared media size and required application-side layout changes (narrower page, left-aligned content) rather than driver-side fixes.
- **Group membership drives capability, not code.** `ace` needed to be added to the `input` group (one-time, requires re-login) before the evdev scanner bridge could open the device node; this is an appliance provisioning step, not something the application can arrange for itself.
- **`APP_DEBUG=true` in what is effectively a production kiosk appliance** is a live risk (verbose error pages, stack traces) that predates this investigation and should be flagged for a deliberate decision, not silently "fixed" as part of unrelated work.

---

## Confirmed facts
- Ubuntu 24.04.4 LTS, kernel 6.8.0-142-generic, x86_64, Intel Celeron N5105, 7.5GB RAM.
- App at `/var/www/aes`, owned by and served as `ace:ace` via `php8.4-fpm` (not `www-data`).
- nginx 1.24.0 serves HTTP only on port 80; no HTTPS, no certbot, no WebSocket proxy config present.
- No `waes-*` systemd units, no `/etc/waes/runtime.env`, no Reverb process, no queue worker, no app-level cron/timer exist on the device.
- Live `.env`: `APP_ENV=local`, `APP_DEBUG=true`, `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=database` (unused — 0 jobs, no listeners are `ShouldQueue`), `BROADCAST_CONNECTION=log` (not `reverb`), `SESSION_DRIVER`/`CACHE_STORE=database`.
- QR scanner: USB HID keyboard-class only (VID:PID `0581:0215`, kernel name `"Scanner Barcode"`), no serial interface, node at `/dev/input/event3` with stable symlink `/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd`, permissions `root:input 0660`, `ace` is in the `input` group.
- CUPS active; queues `HP` (laser, default), `Thermal80`, `Canon_G1010_series`, `PDF` all USB-backed; `ace` is in `lpadmin`.
- **Live issue found (not fixed):** 4 stuck jobs on `Thermal80` since shortly after the last reboot (`Sep 27 14:44`).
- Appliance self-hosts a Wi-Fi AP (`hostapd`/`dnsmasq`, SSID `WAES2028`, open, `192.168.88.0/24`) separate from the wired LAN (`192.168.2.0/24`).

## Differences from repository assumptions
- `deployment/systemd/*.service` assume `User=www-data`/`Group=www-data`; the real runtime user is `ace`.
- `deployment/environment/waes-runtime.env.example` assumes production-mode, HTTPS Reverb broadcasting; live config is debug-mode with `log`-driver broadcasting and no Reverb at all.
- `deployment/nginx/waes-reverb.conf.example` assumes an existing HTTPS server block to include it into; none exists.
- None of the four proposed systemd units, nor `/etc/waes/`, have ever been installed on this appliance — deployment today is manual `git pull` + `composer install` + `npm run build` over SSH, not systemd-managed.

## Recommended repository changes
1. Update all four `deployment/systemd/waes-*` unit files to `User=ace`/`Group=ace` (or add a note that the appliance's actual service account must be substituted at install time), and drop or rewrite the `ProtectSystem=full` + `ReadWritePaths` hardening only after confirming it doesn't break the app's actual write patterns (the whole tree is currently writable by the runtime user).
2. Either (a) provide and document the missing `/etc/waes/runtime.env` provisioning step, or (b) point `EnvironmentFile` at the app's real `.env` and drop the separate env file entirely, since none currently exists.
3. Rewrite `waes-runtime.env.example` to reflect a realistic target state (decide deliberately whether to keep `BROADCAST_CONNECTION=log`/no-Reverb for this appliance class, or actually provision Reverb + HTTPS — right now the example describes a state nobody has built).
4. Add an nginx HTTPS server block (or explicitly scope the project to HTTP-only for this appliance class) before the Reverb proxy snippet can be meaningfully included anywhere.
5. Add a documented, idempotent "install these systemd units" step (or script) to the deployment process, since currently nothing manages Reverb/queue/scheduler as a service at all — today the app functionally works without them (no queued jobs, no broadcasting in use), so this is a decision point, not an emergency fix.

## Proposed scanner bridge approach
Continue with the existing `scripts/canvassing-scanner-bridge.py` (evdev-based) approach; it already matches the hardware reality confirmed here:
- Target the stable path `/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd` rather than any `eventN` index.
- Require the operating user to be in the `input` group (already true for `ace`).
- Keep treating `CR`, `LF`, and `ETX` (`0x03`) all as valid scan terminators in the ingestion commands, since the terminator sent by scanners in the field has been observed to vary.
- If this bridge should run continuously rather than ad hoc, it would need a new systemd unit (not part of the current `deployment/systemd/` set) running as `ace` with `SupplementaryGroups=input` — this does not exist today and would need to be added deliberately.

## Commands Codex can reproduce safely (all read-only)
```bash
ssh ace@waes.consultel.ph 'cat /etc/os-release; uname -a'
ssh ace@waes.consultel.ph 'systemctl list-unit-files | grep -i waes'
ssh ace@waes.consultel.ph 'grep -E "^user|^group|^listen" /etc/php/8.4/fpm/pool.d/www.conf'
ssh ace@waes.consultel.ph 'cat /etc/nginx/sites-available/aes'
ssh ace@waes.consultel.ph 'udevadm info --query=all --name=/dev/input/event3'
ssh ace@waes.consultel.ph 'lpstat -p -d; lpstat -v; lpstat -o Thermal80'
ssh ace@waes.consultel.ph 'grep -E "^APP_ENV|^BROADCAST_CONNECTION|^QUEUE_CONNECTION" /var/www/aes/.env'
```

## Unresolved questions
1. Should this appliance class ever run Reverb/HTTPS/a queue worker, or is the current synchronous, log-broadcast, HTTP-only setup the intended production shape for air-gapped kiosk deployments? The `deployment/` proposal and the live device currently disagree on this in a way that needs a product decision, not just a code fix.
2. Who is responsible for clearing the stuck `Thermal80` print queue and confirming the printer physically re-enumerated correctly after reboots — is there an intended operator runbook for this, or should the app itself surface CUPS queue health somewhere?
3. Is `APP_DEBUG=true` on this device intentional for the current demo phase, or an oversight that should be tracked separately?
4. Should the standing SSH host-key instability on the public `waes.consultel.ph` hostname be investigated as a DNS/port-forward configuration issue, given it has recurred multiple times and once pointed at a completely unrelated host?
