# Installation

Solar Planner installs as a native MajorDoMo module. It does **not** require
Home Assistant and runs on PHP 7.3 through 8.x.

## 1. Install the module

### Option A — via the module package

1. Build or download `solarplanner.tgz`.
2. On the MajorDoMo host, extract into the MajorDoMo root (`/var/www/html/` on
   the reference RPi4 host):
   ```bash
   sudo tar -xzf solarplanner.tgz -C /var/www/html/
   sudo chown -R www-data:www-data /var/www/html/modules/solarplanner
   ```
3. In the MajorDoMo UI open **Panel → Devices → Solar Planner** (or Settings →
   Modules). The module self-registers in `project_modules` and `plugins` and
   creates its `solarplanner_*` tables on first run (`checkInstalled` /
   `dbInstall`).

### Option B — from the installed extensions / marketplace

If published to a compatible repository, install it from the MajorDoMo
"Installed extensions" screen. The `modinfo.json` provides the metadata.

## 2. Run the background cycle

The planner uses a background PHP cycle, like other MajorDoMo device modules.

```bash
php /var/www/html/scripts/cycle_solarplanner.php
```

Run it under a supervisor (systemd or MajorDoMo's cycle manager) so it restarts
automatically. A single `flock` guard prevents overlapping runs.

Example systemd unit (`/etc/systemd/system/mdm-solarplanner.service`):

```ini
[Unit]
Description=MajorDoMo Solar Planner cycle
After=network-online.target mysql.service php7.3-fpm.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php /var/www/html/scripts/cycle_solarplanner.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now mdm-solarplanner
```

## 3. Raspberry Pi 4 (Raspbian buster) notes

The reference host is an RPi4 (8 GB) on Raspbian 10 buster, PHP 7.3-FPM,
MariaDB 10.3, Nginx, with a deliberately small SD partition. To protect the
microSD and keep the 24/7 smart-home responsive:

- **tmpfs for writes.** The host already mounts `/var/lib/php/sessions` and
  `/var/www/html/cms/debmes` on tmpfs. Point the planner's debug log (and any
  file store) at a tmpfs path. Set the module `DATA_DIR` to a tmpfs-backed
  directory if you use the `FileForecastStore`; the default SQL store writes to
  MariaDB.
- **Do not increase write frequency.** Keep the 60 s telemetry / 15 min plan
  defaults. More frequent planning wastes CPU and SD writes without improving a
  weather forecast that updates far less often.
- **Respect Netdata.** Do not stop `/usr/sbin/netdata`; the dashboard hook
  depends on it and stopping it can exhaust PHP-FPM workers.
- **No partition resize.** The small partition is intentional for fast cloning.
  The module adds only small tables; prune `solarplanner_telemetry` with a cron
  if needed (history is otherwise append-only).
- **Nginx/auth untouched.** The module serves under the existing MajorDoMo auth;
  it does not add any public, unauthenticated endpoint and never performs a
  dangerous apply over a plain GET URL.

## 4. Configure

Open the module **Settings** tab and fill the parameters. Coordinates and API
keys are stored privately and masked in the UI. See `configuration.md`.

Until all required parameters are confirmed, only `observe`/`shadow` modes are
available and the UI lists the blocking parameters.
