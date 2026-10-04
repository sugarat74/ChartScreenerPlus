# Despliegue de Chartiko en VPS (OVH, Ubuntu 24.04)

Dominio público: `https://www.chartiko.com`. `/var/www/alphapulse` y servicios
`alphapulse-*` conservan identificadores internos compatibles con el VPS existente.

1. Local: genera una clave solo para CI: `ssh-keygen -t ed25519 -f alphapulse_deploy -N ""`
2. VPS (como root): sube la carpeta `deploy/` (`scp -r deploy root@IP:/root/`) y ejecuta
   `bash /root/deploy/setup-server.sh www.chartiko.com` (o la IP).
   Añade `alphapulse_deploy.pub` a `/home/deploy/.ssh/authorized_keys`.
3. VPS: crea `/var/www/alphapulse/shared/.env` (copia `.env.example`) con:
   `APP_NAME=Chartiko`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.chartiko.com`,
   `APP_KEY` (`php artisan key:generate --show`),
   `DB_DATABASE=/var/www/alphapulse/shared/database/database.sqlite`,
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
