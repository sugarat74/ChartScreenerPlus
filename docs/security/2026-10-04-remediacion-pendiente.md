# Continuación operativa — 2026-10-04

Este documento complementa el informe de producción de las 14:17; no sustituye su matriz ni constituye una nueva aprobación de seguridad. El usuario ha autorizado ejecutar las correcciones y publicar las mejoras.

## Actualización posterior — 17:46 Europe/Madrid

La remediación root fue confirmada por el usuario y revalidada por SSH: padres/código/venv 750/640, .env 640, directorio privado SQLite 2770 y fichero 660. quick_check ok; cola vacía. sudo ya limita NOPASSWD a reinicios/recargas exactos, sin wildcard. Cuatro servicios activos; engine en loopback con health ok. Worker/engine son www-data con Restart=always; cron de aplicación cada minuto confirmado.

Copia privada adicional /home/deploy/chartiko-pre-release-20261004T154604Z: SQLite abierto en copia aislada, integrity_check correcto y recuentos de seis tablas coincidentes. APP_URL HTTPS actualizado y caché regenerada conservando el nombre efectivo de SESSION_COOKIE. CI de PR #1 pasa; test SSH acepta pin esperado y rechaza otro antes de autenticar. La procedencia independiente del pin requiere todavía salida de consola proveedor.

Pendiente: aplicar helper root update-nginx-metadata.sh, contrastar huella pública y activar/verificar producción. El encabezado usa map sobre request_uri para persistir a través de try_files. La nueva copia no establece una política automatizada de backup/retención. El informe original conserva su historial; esta actualización acredita cierre de los permisos observados, no una certificación global.

## Ejecutado

- GitHub autenticado; secretos VPS_HOST/VPS_SSH_KEY presentes en el repositorio y VPS_SSH_KNOWN_HOSTS presente en environment production. Su contenido no se leyó.
- SSH estricto como deploy funciona; ambas identidades disponibles fallan al entrar como root. sudo no interactivo rechaza leer/verificar el script bajo /root.
- Servicios PHP-FPM 8.5, queue, engine y Nginx activos. current: 26fc5946af. Padres de activación/shared/releases: 775 deploy:www-data, por lo que SEC-001 sigue abierto.
- Cola SQLite vacía. Run #1 running desde 2026-10-03 17:34:44; no proceso de datos activo observado. No se modificó ese registro.
- Script revisado subido a /home/deploy/chartiko-remediate-reviewed.sh, modo 0600. SHA256 local/remoto coincidente: 65f1de03a9e73add1635e676fd24a559ceb69b209b698b5c20af4320f49be238. bash -n remoto correcto.
- init.ps1 correcto (154 tests Laravel/985 aserciones, frontend lint/build 133 módulos, 47 tests Python). Verificación de despliegue: seis checks correctos fuera del sandbox; ejecución inicial limitada por ACL de claves temporales Windows.

## Única intervención root necesaria para iniciar la corrección

Desde la consola del proveedor como root:

```bash
install -o root -g root -m 0700 /home/deploy/chartiko-remediate-reviewed.sh /root/chartiko-remediate.sh && echo '65f1de03a9e73add1635e676fd24a559ceb69b209b698b5c20af4320f49be238  /root/chartiko-remediate.sh' | sha256sum -c - && bash /root/chartiko-remediate.sh --allow-stale-ledger
```

El script pausa PHP/worker/cron, guarda copia privada de SQLite/configuración/permisos, traslada SQLite a shared/database, restringe escritura del usuario web y sustituye sudo por comandos systemctl concretos. Recomprueba cola vacía y ausencia de comandos de datos después de pausar. No ejecuta migraciones ni consultas de mercado. Ante configuración incoherente deja servicios pausados y muestra la ruta privada de recuperación; alinea DB_DATABASE/caché con la base privada antes de arrancar. No restaures escritura web sobre código, secretos o padres de activación.

## Después del comando root

1. Revisar salida completa, permisos efectivos, integridad SQLite, sudo limitado y estado/health de los servicios. Mantener backup privado y comprobar restauración en copia aislada.
2. Contrastar desde consola proveedor la huella pública SSH con SHA256:/TrDxOLMZvEZTIV12P4CVrzxsYnJrry2hxw4l07znMM (observada por SSH autenticado; no equivale a comprobación independiente). Confirmar el secret de GitHub mediante el flujo estricto.
3. Alinear APP_URL HTTPS sin cambiar SESSION_COOKIE; aplicar únicamente los cambios Nginx necesarios conservando TLS/Certbot, nginx -t y reload. El script de remediación no realiza estas dos acciones.
4. Completar gates de rama, publicar por main únicamente después de cerrar los bloqueos, observar CI y verificar home, robots/sitemap y guards anónimos en producción.

No se han revalidado firewall del proveedor, restauración de backups, advisories Python ni CSRF autenticado en producción. El informe previo conserva NO APTO hasta nueva evidencia de cierre.
