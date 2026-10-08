# Despliegue de Chartiko en VPS (OVH, Ubuntu 26.04)

Dominio público: `https://www.chartiko.com`. `/var/www/alphapulse` y servicios
`alphapulse-*` conservan identificadores internos compatibles con el VPS existente.

1. Local: genera una clave solo para CI: `ssh-keygen -t ed25519 -f alphapulse_deploy -N ""`
2. VPS (como root): sube la carpeta `deploy/` (`scp -r deploy root@IP:/root/`) y ejecuta
   `bash /root/deploy/setup-server.sh www.chartiko.com` (o la IP).
   Añade `alphapulse_deploy.pub` a `/home/deploy/.ssh/authorized_keys`.
3. VPS: crea `/var/www/alphapulse/shared/.env` (copia `.env.example`) con:
   `APP_NAME=Chartiko`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.chartiko.com`,
   `APP_KEY` (`php artisan key:generate --show`),
   el bloque PostgreSQL de `.env.example` (`DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`,
   `DB_PORT=6432`, `DB_DIRECT_PORT=5432`, `DB_DATABASE=chartiko`, `DB_USERNAME=chartiko`,
   `DB_PASSWORD` = la contraseña dada a `postgresql-add-project.sh`; ver más abajo),
   `SANCTUM_STATEFUL_DOMAINS=www.chartiko.com`, `SESSION_SECURE_COOKIE=true`.
   Dueño `deploy:www-data`, permisos 640.
4. GitHub > Settings > Environments > `production`: restringe las ramas de despliegue a `main`.
   Secrets de Actions: `VPS_HOST` (IP), `VPS_SSH_KEY` (clave de deploy) y
   `VPS_SSH_KNOWN_HOSTS` (línea known_hosts con la clave pública del VPS).
   Obtén la clave desde la consola del proveedor con
   `cat /etc/ssh/ssh_host_ed25519_key.pub` y contrasta su huella con
   `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` por ese canal independiente.
   Formato del secret: `<VPS_HOST> ssh-ed25519 <clave-pública>`.
   CI falla si falta el secret o cambia la clave. No uses `ssh-keyscan` sin verificación.
5. HTTPS: `certbot --nginx -d www.chartiko.com`.
6. Push a `main`: tests -> despliegue automático. Rollback manual: apuntar `current` a una release anterior.

Al cambiar `APP_NAME` en un VPS existente, conserva el nombre efectivo de
`SESSION_COOKIE` para evitar invalidar sesiones por la marca. Actualiza el entorno
compartido y reconstruye la caché de configuración durante el release.

## Permisos y actualización de un VPS existente

Código, directorio de activación, releases, `.env` y venv pertenecen a `deploy`
y no admiten escritura de `www-data`. Solo `shared/storage`, `shared/database`
y `bootstrap/cache` de cada release admiten escritura del servicio.
`release-permissions.sh` aplica esta política antes de activar cada release.

Para el antiguo SQLite en `shared/database.sqlite`, usa desde consola root
`bash /ruta/revisada/remediate-permissions.sh`: guarda configuración y modos,
traslada la base al directorio dedicado, actualiza únicamente `DB_DATABASE` y
reconstruye su caché, con una pausa de PHP-FPM/worker y del cron de esta aplicación.
Rechaza una Ingestion Run activa. No ejecuta migraciones, consultas de mercado ni
un despliegue completo. Conserva los backups privados y sigue el rollback del script
si falla una comprobación; nunca restaures escritura del servicio sobre código o `.env`.

Si existe un registro `running` obsoleto, `--allow-stale-ledger` permite continuar
solo con cola database vacía, registro iniciado hace más de seis horas y sin comandos
de datos activos; lo comprueba de nuevo después de pausar servicios. No modifica
el estado del registro. Una ejecución real o jobs pendientes bloquean la operación.

Verificación local (Git Bash requerido): `.\scripts\verify-deploy-security.ps1`.
Prueba claves ausentes/incorrectas y el modo SSH estricto. Admite `-HostName`,
`-IdentityPath` y `-KnownHostsLine` para comprobar el pin contra un VPS autorizado;
no modifica la aplicación ni dispara consultas.

En un sitio existente administrado por Certbot, `update-nginx-metadata.sh` añade
el encabezado noindex de las rutas de aplicación sin sustituir TLS ni rutas.
Se ejecuta como root, conserva copia privada del archivo, comprueba `nginx -t`
y restaura la configuración anterior si falla la validación o recarga. La plantilla
usa `$request_uri` para mantener el encabezado tras el fallback interno de la SPA.

## PostgreSQL y PgBouncer

Producción usa PostgreSQL (paquete de la distribución, solo `localhost`) detrás de
un PgBouncer compartido en `127.0.0.1:6432` en modo `transaction`. Cada proyecto
tiene su base y su rol propios (sin superusuario, sin `CREATEDB`/`CREATEROLE`,
`PUBLIC` sin acceso). El entorno local y `init.ps1` siguen en SQLite.

- **Tráfico de ejecución** (PHP-FPM, worker, scheduler): `DB_PORT=6432` (PgBouncer).
- **Tráfico administrativo** directo a `5432`: con `DB_DIRECT_PORT=5432`, Laravel
  marca la conexión como *pooled*, emula los *prepared statements* y envía
  `migrate`, `db:wipe`, `db:show` y `php artisan db` al endpoint directo. La copia
  `db:copy-sqlite-to-pgsql` y `pg_dump` también van directos.
- Ajustes de sesión (`search_path`, `timezone`) están en el rol (`ALTER ROLE`), no en
  un `SET` del cliente, para que valgan en cualquier conexión del pool.

### Instalación (servidor nuevo o existente)

`setup-server.sh` instala `postgresql pgbouncer php-pgsql`, aplica
`install-pgbouncer.sh` y deja el backup diario. En un VPS ya en marcha, desde la
consola root y con la carpeta `deploy/` revisada:

```bash
apt-get install -y postgresql pgbouncer php-pgsql
systemctl reload php*-fpm
bash deploy/install-pgbouncer.sh            # pgbouncer.ini + pg_hba.conf; conserva proyectos
openssl rand -hex 32 | tee /dev/tty | bash deploy/postgresql-add-project.sh chartiko
install -o root -g root -m 0700 deploy/backup-postgresql.sh /usr/local/sbin/chartiko-backup-postgresql
echo '30 3 * * * root /usr/local/sbin/chartiko-backup-postgresql chartiko >> /var/log/chartiko-postgresql-backup.log 2>&1' \
  > /etc/cron.d/chartiko-postgresql-backup
```

Guarda la contraseña mostrada en un gestor de secretos; solo se escribe en
`shared/.env` y en el fichero temporal de la migración. `userlist.txt` guarda el
verificador SCRAM (nunca la contraseña), `postgres:postgres` `0640`.
Comprobaciones: `ss -ltnp | grep -E ':(5432|6432)'` (solo `127.0.0.1`/`::1`) y
`sudo -u postgres psql -c '\du chartiko'`.

### Añadir otro proyecto

`openssl rand -hex 32 | tee /dev/tty | bash deploy/postgresql-add-project.sh <proyecto>`:
crea rol y base, revoca `PUBLIC`, añade una línea a `/etc/pgbouncer/databases.ini`
y el verificador a `userlist.txt`, recarga PgBouncer y restaura la copia si falla.
Reejecutarlo rota la contraseña. Añade `<proyecto>` a la línea del cron de backup.

### Operación de PgBouncer

- Estado: `systemctl status pgbouncer`;
  `sudo -u postgres psql -h /var/run/postgresql -p 6432 -d pgbouncer -c 'SHOW POOLS;'`
  (`cl_waiting`/`maxwait` > 0 de forma sostenida => subir `default_pool_size`).
  Conexiones reales: `sudo -u postgres psql -c "select count(*) from pg_stat_activity where usename = 'chartiko';"`
  (como máximo `max_db_connections` = 20 más sesiones administrativas directas).
- PgBouncer es dependencia de ejecución: si cae, cae el sitio. Bypass sin tocar
  datos: en `shared/.env` `DB_PORT=5432` y borrar `DB_DIRECT_PORT`, luego como
  `deploy` `php artisan config:cache` en `current` y `sudo systemctl reload php*-fpm`
  y `sudo systemctl restart alphapulse-queue`. Revertir igual.
- `deploy.sh` lee el driver de `shared/.env`: con `pgsql` no exige el fichero SQLite
  y, antes de activar la release, comprueba la ruta pooled con `db:monitor`.

### Migración a PostgreSQL (una vez, ventana de mantenimiento)

1. Prepara `/root/chartiko-pgsql.env` (`root`, `0600`) con exactamente
   `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=6432`, `DB_DIRECT_PORT=5432`,
   `DB_DATABASE=chartiko`, `DB_USERNAME=chartiko`, `DB_PASSWORD=<la del paso anterior>`.
2. Despliega antes la release que contiene `db:copy-sqlite-to-pgsql` (push a `main`).
3. Ensayo: `bash deploy/migrate-to-postgresql.sh --dry-run /root/chartiko-pgsql.env`.
   Crea el esquema en la base PostgreSQL aún sin uso y valida cada valor de una
   instantánea SQLite (tabla, id y columna de cada problema, nunca el valor). No
   toca el sitio, `.env`, servicios, cron ni el fichero SQLite.
4. Ventana: `bash deploy/migrate-to-postgresql.sh /root/chartiko-pgsql.env`
   (`--allow-stale-ledger` con el mismo criterio que `remediate-permissions.sh`).
   Pausa cron, sitio (`artisan down`), worker y engine; rechaza jobs pendientes o
   una Ingestion Run activa; guarda backup SQLite privado con `integrity_check` y
   conteos en `/root/chartiko-pgsql-cutover-<fecha>/`; ejecuta `migrate` y la copia
   transaccional (con `shared/.env` aún en SQLite); solo entonces cambia `shared/.env`
   y la caché de configuración; reanuda y hace smoke (`/api/screener` y
   `/api/instruments/NVDA` 200, `/api/watchlist` y `/api/admin/ping` 401,
   `ingestion-pipeline` programado, `SHOW POOLS`). Un fallo antes del cambio reanuda
   en SQLite; un fallo después restaura `.env`/caché automáticamente.
5. Tras la ventana, a mano: login de Admin, un job de cola procesado
   (`php artisan queue:work --once` o lanzar una consulta Admin) y comparar los
   conteos de `copy.txt` con `counts.before.txt` (68,136 Daily Bars en la última
   comprobación). Las sesiones no se copian: los usuarios inician sesión de nuevo.
6. Borra `/root/chartiko-pgsql.env`. Conserva `shared/database/database.sqlite` sin
   cambios al menos 30 días como origen de rollback.

**Rollback manual** (después de completar la migración; en producción la carpeta válida es
`/root/chartiko-pgsql-cutover-20261006T184407Z`, la `…181456Z` quedó vacía y la `…183353Z` es el
primer intento revertido): como root,
`cp -a /root/chartiko-pgsql-cutover-<fecha>/env.before /var/www/alphapulse/shared/.env`,
luego como `deploy` `php artisan config:cache` en `current`, `systemctl reload php*-fpm`
y `systemctl restart alphapulse-queue`. Todo lo escrito en PostgreSQL desde la
migración se pierde al volver: mantén la ventana corta y verifica antes de reabrir.
Ensaya este rollback en el dry-run o en un clon antes de la ventana real.

- **Reinicio automático:** `install-pgbouncer.sh` instala
  `/etc/systemd/system/pgbouncer.service.d/restart.conf` (`Restart=on-failure`, `RestartSec=2`);
  compruébalo con `systemctl show -p Restart pgbouncer`.
- **Contraseñas en logs:** `postgresql-add-project.sh` fija la contraseña con `ALTER ROLE … PASSWORD`;
  mantén `log_statement` en `none` (`ddl`, `mod` y `all` registran esa sentencia en los logs de
  PostgreSQL). Además, con `log_min_error_statement=error` (por defecto) una sentencia que falle se
  registra con su texto: si `postgresql-add-project.sh` falla, revisa y limpia
  `/var/log/postgresql/postgresql-18-main.log`.

### Backups

`chartiko-backup-postgresql` (cron diario 03:30, root) guarda `pg_dump -Fc` en
`/var/backups/chartiko-postgresql` (`0700`, ficheros `0600`), valida el volcado con
`pg_restore --list` y conserva 14 días. Prueba de restauración (en un momento sin
ingesta, porque compara conteos con producción):
`/usr/local/sbin/chartiko-backup-postgresql --restore-test chartiko`. Copia fuera del
VPS: pendiente de configurar por el operador (p. ej. `rclone`/`restic` a un
almacenamiento externo con credenciales solo en el VPS); nunca en el repositorio.

### Administración remota

PostgreSQL y PgBouncer no se exponen: usa un túnel SSH
(`ssh -L 15432:127.0.0.1:5432 deploy@<VPS>`) y conecta a `127.0.0.1:15432`.
