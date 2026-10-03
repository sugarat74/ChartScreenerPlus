# Despliegue en VPS (OVH, Ubuntu 24.04)

1. Local: genera una clave solo para CI: `ssh-keygen -t ed25519 -f alphapulse_deploy -N ""`
2. VPS (como root): sube la carpeta `deploy/` (`scp -r deploy root@IP:/root/`) y ejecuta
   `bash /root/deploy/setup-server.sh tu-dominio.com` (o la IP).
   Añade `alphapulse_deploy.pub` a `/home/deploy/.ssh/authorized_keys`.
3. VPS: crea `/var/www/alphapulse/shared/.env` (copia `.env.example`) con:
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-dominio.com`,
   `APP_KEY` (`php artisan key:generate --show`),
   `DB_DATABASE=/var/www/alphapulse/shared/database.sqlite`,
   `SANCTUM_STATEFUL_DOMAINS=tu-dominio.com`, `SESSION_SECURE_COOKIE=true`.
   Dueño `deploy:www-data`, permisos 640.
4. GitHub > Settings > Secrets > Actions: `VPS_HOST` (IP) y `VPS_SSH_KEY` (contenido de `alphapulse_deploy`).
5. HTTPS: `certbot --nginx -d tu-dominio.com`.
6. Push a `main`: tests -> despliegue automático. Rollback manual: apuntar `current` a una release anterior.
