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
   `SANCTUM_STATEFUL_DOMAINS=www.chartiko.com`, `SESSION_SECURE_COOKIE=true`,
   Dueño `deploy:www-data`, permisos 640.
   No escribas a mano `LEGAL_OWNER_NAME`, `LEGAL_CONTACT_EMAIL`, `LOG_CHANNEL`, `LOG_STACK` ni
   `LOG_DAILY_DAYS`: `configure-legal-env.py` los fija en cada despliegue (nombre del responsable y
   email de contacto, que publican la política de privacidad; canal `daily` con 14 días, la retención
   de logs que declara esa política). El aviso legal solo se publica cuando además se añaden a mano
   `LEGAL_OWNER_TAX_ID` y `LEGAL_OWNER_ADDRESS` (opcional `LEGAL_OWNER_REGISTRY`); tras cambiarlos,
   `php artisan config:cache` y recargar PHP-FPM. Ver `docs/specs/legal-privacy-publication.md`.
   **Copias SQLite retiradas (2026-10-18):** antes de publicar la privacidad se borraron, como root, las
   cinco copias SQLite con datos personales (`shared/database/database.sqlite`, los
   `database.before.sqlite` de `/root/chartiko-pgsql-cutover-20261006T183353Z`,
   `/root/chartiko-pgsql-cutover-20261006T184407Z` y `/root/chartiko-security-20261004T153313Z`, y
   `/home/deploy/chartiko-pre-release-20261004T154604Z/database.sqlite`). Esos directorios se conservan:
   solo guardan copias de configuración, `.env` y permisos. Ya no es posible volver a SQLite.
4. GitHub > Settings > Environments > `production`: restringe las ramas de despliegue a `main`.
   Secrets de Actions: `VPS_HOST` (IP), `VPS_SSH_KEY` (clave de deploy) y
   `VPS_SSH_KNOWN_HOSTS` (línea known_hosts con la clave pública del VPS).
   Claves de proveedores (entorno `production`): `TYPESAFE_API_KEY` (Jev) y `AI_API_KEY`
   (ficha IA). En cada despliegue se instalan en `shared/.env` mediante
   `configure-jev-env.py` y `configure-ai-env.py`, sin imprimirlas. Si falta alguna, el despliegue falla.
   Opcional: `FRED_API_KEY` (series macro FRED, paso 8). Si existe, `configure-macro-env.py` la instala
   con `MACRO_ENABLED=true`; si no existe, ese paso no hace nada y el despliegue no falla.
   `configure-fundamentals-env.py` activa los fundamentales SEC y fija `SEC_USER_AGENT`. No usa
   ningún secreto, porque el contacto que exige la SEC no es confidencial.
   `configure-legal-env.py` fija el responsable y el email de la política de privacidad y los logs
   diarios con 14 días (paso 3). Tampoco usa secretos y no toca NIF, domicilio ni registro.
   Obtén la clave desde la consola del proveedor con
   `cat /etc/ssh/ssh_host_ed25519_key.pub` y contrasta su huella con
   `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` por ese canal independiente.
   Formato del secret: `<VPS_HOST> ssh-ed25519 <clave-pública>`.
   CI falla si falta el secret o cambia la clave. No uses `ssh-keyscan` sin verificación.
5. HTTPS: `certbot --nginx -d www.chartiko.com`.
6. Push a `main`: tests -> despliegue automático. Rollback manual: apuntar `current` a una release anterior.
   La migración `2026_10_27_000001` rellena industria y país de sede solo para Instrumentos existentes a partir del CSV confirmado; el `screener:refresh` ya incluido copia ambos campos a los Candidatos. No se reejecuta el seeder completo del S&P 500 durante el despliegue, para conservar cambios del Admin.
   El despliegue ejecuta `php artisan screener:refresh` (filas precalculadas del Screener) tras la
   comprobación de PgBouncer, antes de activar (si falla, la release no se activa), y otra vez tras activar
   (no fatal). Las ingestas (`ingestion:run`, consultas de Admin, `ingestion:scrape`) refrescan solas; si
   lanzas a mano `indicators:compute`, `signals:detect` o `patterns:detect`, ejecuta después
   `php artisan screener:refresh`. Tras un rollback manual (cambiar `current` sin `deploy.sh`), ejecuta
   también `php artisan screener:refresh`; una release anterior a `screener-precomputed` ignora la tabla.
7. Contexto de mercado (`market-context-sectors`): tras `migrate`, cada despliegue ejecuta
   `php artisan db:seed --class=MarketContextUniverseSeeder --force` (sin red e idempotente: universo
   `market-context` con SPY y los 11 ETF sectoriales SPDR, `kind = etf`). A partir de ahí,
   `ingestion:pipeline` ingiere esos ETF, calcula sus indicadores y ejecuta `market-context:compute`
   (las 5 últimas sesiones) antes de `screener:refresh`; un fallo de ese bloque se registra pero no cambia
   el código de salida ni salta el Screener ni las alertas. No ejecutes nunca
   `ingestion:pipeline --universe=market-context`: correría señales y patrones sobre los ETF; para ese
   universo usa los comandos sueltos de abajo. **Una sola vez** tras el primer despliegue,
   rellena al menos un año de historia (como `deploy`, en `current`):

   ```bash
   php artisan ingestion:run --universe=market-context
   php artisan indicators:compute --universe=market-context
   php artisan market-context:compute --from=$(date -d '1 year ago' +%F)
   ```

   Las fechas más antiguas del relleno pueden tener `new_highs`/`new_lows` nulos (exigen 251 sesiones
   previas). Los ETF salen de la misma fuente no oficial que las acciones (mismo riesgo de licencia).
8. Series macro FRED (`macro-series-fred`, `docs/specs/macro-series-fred.md`): desactivadas por defecto.
   Se activan con `MACRO_ENABLED=true` y una clave de la API de FRED en `FRED_API_KEY`. La clave es gratuita:
   crea una cuenta en `https://fredaccount.stlouisfed.org` y pide una en *API Keys* (32 letras minúsculas y
   dígitos). Hay dos formas de instalarla; nunca va al repositorio ni a la línea de comandos:
   - **A mano** en `shared/.env` (`FRED_API_KEY="…"` y `MACRO_ENABLED=true`). El siguiente despliegue ejecuta
     `config:cache`; para aplicarla antes, `php artisan config:cache` como `deploy` (con `umask 007`) y recarga
     PHP-FPM. Sin el secret, ningún despliegue toca esas líneas.
   - **Con el secret** `FRED_API_KEY` del entorno `production`: en cada despliegue
     `configure-macro-env.py` la recibe por stdin, valida el formato, escribe `FRED_API_KEY` y
     `MACRO_ENABLED=true` y no imprime nada. Sustituye una clave puesta a mano.

   `MACRO_NFCI_ENABLED` y `MACRO_VIXCLS_ENABLED` siguen en `false` (Chicago Fed y Cboe no permiten uso
   comercial sin permiso). Con la clave puesta, `macro:refresh` corre cada día a las 10:00 (Nueva York, también
   en fin de semana) y como etapa aislada de `ingestion:pipeline` tras el contexto de mercado. **La primera
   ejecución rellena 5 años por sí sola**: 12 series, 24 peticiones espaciadas 0,6 s (unos 15–30 s). Cada
   ejecución tiene un presupuesto de 300 s: si FRED falla o va lento, las series pendientes quedan para la
   siguiente. Un fallo se guarda como código en `macro_series.last_error` (p. ej. `unavailable`,
   `invalid_key`) y nunca cambia el código de salida del pipeline ni salta el Screener o las alertas.
   Comprobación (como `deploy`, en `current`): `php artisan macro:refresh --series=DGS10` imprime
   `DGS10: N stored (…), latest AAAA-MM-DD`; `invalid_key` indica una clave mal copiada. Nada de FRED se
   muestra todavía en la web (lo hará `market-context-ui`, con el aviso que exigen los términos de FRED).
9. Entorno de mercado (`market-regime`, `docs/specs/market-regime.md`): sin configuración ni variables nuevas.
   `ingestion:pipeline` ejecuta `market-regime:compute` tras la etapa macro y antes de `screener:refresh`
   (siempre, aunque las series macro estén desactivadas); un fallo se registra pero no cambia el código de
   salida ni salta el Screener ni las alertas. **El primer pipeline tras el despliegue rellena la historia
   por sí solo**: con la tabla vacía calcula todas las sesiones desde la primera instantánea de amplitud
   (unas 252 desde 2025-10-22); después recalcula las 5 últimas. Comprobación (como `deploy`, en `current`,
   tras ese pipeline): `count(*)`, `min(date)` y `max(date)` de `market_regime_snapshots` (unas tantas filas
   como sesiones desde 2025-10-22, `min(date)` = 2025-10-22), y anota la distribución de `regime` para la
   calibración. Si en esa primera ejecución faltaban datos macro (filas `insufficient` por falta de curva o
   liquidez), relanza `php artisan market-regime:compute --from=2025-10-22`. Se muestra en `/mercado`
   (punto 10).
10. Contexto de mercado (`market-context-ui`, `docs/specs/market-context-ui.md`): página `/mercado`,
   `GET /api/market-context` (+ historia) y la pestaña Admin «Contexto de mercado». **Desactivada para el
   público por defecto** (`MARKET_CONTEXT_PUBLIC=false`): solo los administradores pueden activar la pestaña
   «Mercado», abrir la página (con el aviso «Vista previa») y consultar la API. Los demás ven la pestaña
   desactivada; la página responde 404 a usuarios sin acceso (401 a visitantes). Tras el despliegue, como Admin:
   abre `/mercado` y `/admin/market-context`, compara el
   entorno (fecha 2026-10-22 o posterior, etiqueta y puntuación), la distribución y las fechas de cada serie
   con la base de datos, y abre cada historia en 1 y 3 años. **Decisión del titular (2026-10-23, opción b):**
   no se activa hasta que el aviso legal (`/aviso-legal`, que ya incluye la cláusula de las condiciones de uso
   de FRED) esté publicado, es decir, con NIF y domicilio configurados. Para activarla entonces: pon
   `MARKET_CONTEXT_PUBLIC=true` en `shared/.env`, ejecuta `php artisan config:cache` como `deploy`
   (con `umask 007`) y comprueba la página sin sesión. La caché del contexto se renueva sola cuando termina
   cada uno de los tres comandos; los botones «Recalcular» de la pestaña Admin usan la cola `database`.

   **Solo usuarios registrados (`market-context-patterns`, decisión del titular 2026-10-23).** Activar
   `MARKET_CONTEXT_PUBLIC` significa que lo ven los **usuarios registrados**, nunca los visitantes: sin sesión,
   la pestaña «Mercado» permanece visible pero desactivada; `/mercado`, la franja «Contexto de mercado» del
   gráfico y los filtros «Contexto sectorial» del Screener muestran un aviso para iniciar sesión, y toda la API
   de contexto responde 401.
   El acceso es una única habilidad, `view-market-context` (`AppServiceProvider`), punto de entrada de un
   futuro plan de pago. La migración añade `screener_rows.sector_trend` y `sector_rs_rank` (las rellena el
   `screener:refresh` del despliegue). Tras el despliegue, con la bandera aún apagada: como Admin, abre el
   gráfico de una acción (p. ej. `/instruments/AAPL`), compara su franja con la tabla de sectores de
   `/mercado` y prueba un filtro «Contexto sectorial» en el Screener; como usuario normal, comprueba que no
   aparece nada. Al comprobar la página «sin sesión» tras activar la bandera, espera el aviso de inicio de
   sesión, no los datos.

   Si ejecutas `php artisan market-context:compute` a mano (p. ej. un relleno con `--from`), ejecuta después
   `php artisan screener:refresh` para que las columnas sectoriales del Screener se actualicen (el pipeline
   diario y el botón «Recalcular» de Admin ya lo hacen).
11. Universos del Screener (`universe-registry`, `docs/specs/multi-market-universes.md`): la migración
   `2026_10_28_000001_*` añade mercado de cotización y divisa a instrumentos y universos y marca `sp500` como
   universo del Screener. `SCREENER_UNIVERSES_PUBLIC` (lista separada por comas, por defecto `sp500`) decide qué
   universos ven los visitantes; un universo sembrado que no esté en la lista solo lo ve un Admin (vista
   previa). Es también el interruptor de retirada: quita el slug de `shared/.env`, ejecuta
   `php artisan config:cache` como `deploy` y ese universo desaparece al instante de toda superficie no Admin
   (`GET /api/universes`, `?universe=` responde 404, Saved Screeners; las alertas sobre él se omiten) sin
   borrar datos; volver a añadirlo lo restaura. Esta función no siembra ningún índice (el Nasdaq-100 se siembra en el punto 12). Tras
   desplegar: `GET /api/universes` sin sesión devuelve solo `sp500` y `GET /api/screener` no cambia salvo los
   campos añadidos `market` y `currency`.

12. Nasdaq-100 (`universe-nasdaq100`, `database/data/nasdaq100.md`): cada despliegue ejecuta
   `php artisan db:seed --class=Nasdaq100UniverseSeeder --force` tras el de Market Context (sin red e
   idempotente; el S&P 500 ya debe estar sembrado). Crea el universo `nasdaq100` (US, USD, posición 1) con
   101 valores: los 86 que ya están en el S&P 500 son el mismo Instrumento (mismas velas y misma fila del
   Screener) y solo ganan pertenencia; se crean 15 Instrumentos nuevos. Sigue **sin ser público**: lo ve solo un
   Admin hasta que se añada a `SCREENER_UNIVERSES_PUBLIC`. Pasos de salida a producción, en orden:

   1. Desplegar (migra y siembra). Comprobar con sesión de Admin: `GET /api/universes` lista `sp500` y
      `nasdaq100`; sin sesión solo `sp500`.
   2. Primera ingesta: la lanza el `ingestion:pipeline` programado (16:30 Nueva York) o a mano como `deploy`,
      en `current`: `php artisan ingestion:pipeline --force`. El Nasdaq-100 se procesa tras `sp500` con
      `--except-universe=sp500`, así que solo descarga los 15 miembros nuevos (unos 2 años de velas cada uno).
      Solo esa parte, si hace falta: `php artisan ingestion:run --universe=nasdaq100 --except-universe=sp500`,
      luego `indicators:compute`, `signals:detect` y `patterns:detect` con los mismos `--universe` y
      `--except-universe`, y `php artisan screener:refresh`.
   3. Verificar: la última Ingestion Run del universo `nasdaq100` tiene `total` = 15 y sin fallos; en Admin la
      tabla de frescura de universos muestra `nasdaq100` como `fresh`; con sesión de Admin,
      `/screener?universe=nasdaq100` lista Candidatos (101 valores en total, los solapados con S&P 500 incluidos).
   4. Hacer público: en `shared/.env` poner `SCREENER_UNIVERSES_PUBLIC=sp500,nasdaq100` y ejecutar
      `php artisan config:cache` como `deploy`. Anotarlo en `PROGRESS.md`. Para retirarlo, quitar el slug y
      volver a ejecutar `config:cache` (interruptor de la entrada 11). No cambies el valor por defecto de
      `.env.example` ni de `config/universes.php`.
   5. Rebalanceo (revisión anual en diciembre y sustituciones puntuales): procedimiento en
      `database/data/nasdaq100.md`; la siembra del despliegue aplica el CSV nuevo.

Al cambiar `APP_NAME` en un VPS existente, conserva el nombre efectivo de
`SESSION_COOKIE` para evitar invalidar sesiones por la marca. Actualiza el entorno
compartido y reconstruye la caché de configuración durante el release.

## Permisos y actualización de un VPS existente

Código, directorio de activación, releases, `.env` y venv pertenecen a `deploy`
y no admiten escritura de `www-data`. Solo `shared/storage`, `shared/database`
y `bootstrap/cache` de cada release admiten escritura del servicio.
`release-permissions.sh` aplica esta política antes de activar cada release.

**Histórico (época SQLite, ya sin uso: producción usa PostgreSQL y las copias SQLite se
borraron el 2026-10-18).** Para el antiguo SQLite en `shared/database.sqlite` se usaba desde consola root
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

### Logs de Laravel

- Canal `daily` (`LOG_STACK=daily`, fijado por `configure-legal-env.py`): un fichero
  `shared/storage/logs/laravel-AAAA-MM-DD.log` por día (UTC), creado con modo `0660`; el directorio
  `2770 deploy:www-data` le da el grupo `www-data`, así que `deploy` (artisan del despliegue) y
  `www-data` (PHP-FPM, cola, cron) pueden escribir y rotar.
- `logs:prune` (programado a diario, `prune-server-logs`, 03:25 UTC) borra los `laravel-AAAA-MM-DD.log`
  con fecha de hace `LOG_DAILY_DAYS` (14) días o más; nunca toca otros ficheros. Monolog limita el
  número de ficheros, no los días, por eso hace falta esta poda para cumplir los 14 días declarados.
- El antiguo `laravel.log` único (sin rotación desde 2026-10-04) se borra tras el primer despliegue
  con el canal `daily`, en cuanto exista el primer `laravel-AAAA-MM-DD.log`.

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
6. Borra `/root/chartiko-pgsql.env`. Se planeaba conservar `shared/database/database.sqlite` sin
   cambios al menos 30 días como origen de rollback; por decisión del propietario se borró antes,
   el 2026-10-18, junto con las demás copias SQLite (paso 3 del despliegue).

**Rollback manual: retirado el 2026-10-18.** Ya no es posible volver a SQLite: el origen de
rollback y los `database.before.sqlite` se borraron (el `env.before` sigue en
`/root/chartiko-pgsql-cutover-*`, pero sin la base no sirve). Ante un fallo de PostgreSQL, restaura
el último volcado diario (ver backups). Procedimiento histórico, ya **no aplicable** (en producción la carpeta válida era
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

## Correo transaccional con Brevo

La configuración vigente es [alerts-email-golive](../docs/specs/alerts-email-golive.md).
El transporte de Brevo usa su API transaccional con `textContent`. El servicio
convierte el mensaje recibido a HTML y añade un píxel de apertura incluso con
`contactPixelTrackingConsent=false`; también reescribe enlaces del HTML. El
propietario aceptó el píxel el 2026-10-24. Los mensajes se envían con
`textContent`, cuyos enlaces se comprobaron directos en el buzón de prueba;
los textos de privacidad informan del píxel. Queda pendiente una comprobación
independiente de SPF/DKIM/DMARC en un destinatario fiable.
La clave de API se instala sólo en `shared/.env`; el token MCP no
se usa en la aplicación. Los registros DNS de Brevo están publicados en
Cloudflare. No se modifican los MX ni el SPF del dominio.

Tras desplegar el transporte, instala la clave desde el equipo del propietario:

```powershell
(Get-Content -LiteralPath 'C:\laragon\www\claveapibrevo.txt' -Raw).Trim() | ssh -i "$HOME\.ssh\alphapulse_deploy" deploy@51.222.158.148 'python3 /var/www/alphapulse/current/deploy/configure-mail-env.py --api-key-stdin --apply'
```

El script activa ambos interruptores cuando la clave está instalada. Si falta,
usa el mailer `log` y deja ambos desactivados.
`php artisan mail:test <dirección>` envía una prueba sin activar las funciones.
Para suspenderlas: `python3 deploy/configure-mail-env.py --flags-off --apply`
como `deploy` en el servidor. No copies la clave a Git ni la muestres en logs.

### Confirmación del email al registrarse (`auth-email-verification`)

La migración marcó como verificadas todas las cuentas existentes. Con la clave Brevo instalada, el script de correo activa la confirmación de nuevas cuentas; sin clave, las cuentas nuevas nacen verificadas, no se envía ningún email y `GET /api/legal` devuelve `account_email: null`.

- **Se activa** después de probar la entrega con Brevo, con `AUTH_EMAIL_VERIFICATION_ENABLED=true` además de `MAIL_MAILER=brevo`, `BREVO_API_KEY` y `MAIL_PROVIDER*`. Solo surte efecto si `MAIL_PROVIDER` y `MAIL_PROVIDER_LOCATION` están rellenas y `MAIL_MAILER` no es `log` ni `array` (con `log`, las direcciones y los enlaces acabarían en `laravel.log`). Sube `updated_at` en `config/legal.php` al activar la función, ejecuta `config:cache` y **reinicia `alphapulse-queue`**: los emails de confirmación salen de la cola.
- **Prueba:** registra una cuenta de prueba, comprueba que solo ve «Confirma tu correo», que llega un email (es/en, con el píxel de Brevo comunicado, Reply-To `contacto@`) y que su enlace `https://www.chartiko.com/verificar-correo/…` confirma la cuenta (también en una ventana privada).
- **Marcha atrás:** `AUTH_EMAIL_VERIFICATION_ENABLED=false`, `config:cache` y reinicia el worker. Las cuentas pendientes pasan a tener acceso completo al momento; no hace falta tocar la base de datos.
