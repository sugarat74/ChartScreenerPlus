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
   `DB_DATABASE=/var/www/alphapulse/shared/database.sqlite`,
   `SANCTUM_STATEFUL_DOMAINS=www.chartiko.com`, `SESSION_SECURE_COOKIE=true`.
   Dueño `deploy:www-data`, permisos 640.
4. GitHub > Settings > Secrets > Actions: `VPS_HOST` (IP) y `VPS_SSH_KEY` (contenido de `alphapulse_deploy`).
5. HTTPS: `certbot --nginx -d www.chartiko.com`.
6. Push a `main`: tests -> despliegue automático. Rollback manual: apuntar `current` a una release anterior.

Al cambiar `APP_NAME` en un VPS existente, conserva el nombre efectivo de
`SESSION_COOKIE` para evitar invalidar sesiones por la marca. Actualiza el entorno
compartido y reconstruye la caché de configuración durante el release.
