# Comprobaciones de bajo impacto

Selecciona comandos según herramientas, sistema y alcance. Son ejemplos de lectura,
no un script para ejecutar indiscriminadamente. Registra lo ejecutado y sus límites.
No imprimas comandos de procesos completos: pueden contener tokens/contraseñas.

## Windows / PowerShell 5.1

```powershell
Get-NetTCPConnection -State Listen | Select-Object LocalAddress, LocalPort, OwningProcess
Get-NetUDPEndpoint | Select-Object LocalAddress, LocalPort, OwningProcess
Get-NetFirewallProfile | Select-Object Name, Enabled, DefaultInboundAction, DefaultOutboundAction
Get-NetFirewallRule -PolicyStore ActiveStore -Enabled True -Direction Inbound | Select-Object DisplayName, Action, Profile
```

Correlaciona PID con nombre mediante `Get-Process -Id <PID>`, sin CommandLine.
Las reglas requieren también filtros de puerto, dirección remota, programa y perfil
efectivo (`Get-NetFirewallPortFilter`, `Get-NetFirewallAddressFilter`, etc. sobre las
reglas relevantes). El listado de nombres no prueba una política efectiva.

```powershell
Test-NetConnection -ComputerName "host-autorizado.example" -Port 443 -InformationLevel Detailed
Get-Acl -LiteralPath "C:\ruta\privada\archivo" | Format-List Owner, AccessToString
```

Usa Test-NetConnection en pocos puertos y desde una posición identificada. No
conviertas un inventario local en un escaneo externo completo. Acceso denegado al
firewall/ACL = NO VERIFICADO. No solicites elevación automáticamente.

## Linux (solo en el servidor Linux objetivo)

```text
ss -lntup
ufw status verbose
nft list ruleset
stat -c '%a %U %G %n' /ruta/archivo
getfacl /ruta/archivo
```

Elige el firewall instalado, no todos. Inspeccionar reglas puede requerir acceso
adicional; no ejecutes `sudo` ni cambies políticas sin el acceso acordado.
`ss` puede mostrar procesos solo con permisos suficientes. Revisa además security
groups/ACL del proveedor y reglas creadas por Docker que alteren el filtrado.

## Contenedores

```text
docker ps --format "table {{.Names}}\t{{.Ports}}\t{{.Status}}"
docker port NOMBRE
```

Evita `docker inspect`/`docker compose config` completos: pueden revelar entorno
secreto. Si necesitas mounts, capabilities o usuario, consulta solo esos campos.
Un `EXPOSE` del Dockerfile no publica por sí solo el puerto.

## HTTP / TLS

Para cabeceras **seleccionadas y sin cookies crudas**, en PowerShell 5.1:

```powershell
$response = $null
try {
    $response = Invoke-WebRequest -UseBasicParsing -Uri "https://host-autorizado.example/" -Method Head -MaximumRedirection 0 -TimeoutSec 15
} catch {
    if ($_.Exception.Response) { $response = $_.Exception.Response }
    else { throw "Sin respuesta HTTP; registrar el error de transporte sanitizado." }
}
[int]$response.StatusCode
foreach ($name in @('Content-Type', 'Content-Length', 'Strict-Transport-Security', 'Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Cache-Control', 'Vary', 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials')) {
    '{0}: {1}' -f $name, $response.Headers[$name]
}
```

HEAD es solo preliminar: hay servidores que responden diferente a GET. Comprueba
redirecciones paso a paso, solo dentro del alcance. No envíes credenciales a HTTP
ni a otro host durante una redirección. Para cookies, informa nombre y atributos
Secure/HttpOnly/SameSite/domain/path, nunca su valor. Para comprobar rutas privadas
usa un cliente capaz de limitar bytes en streaming; no descargues cuerpos completos
con `Invoke-WebRequest` si podrían contener un backup/base de datos. Un Range puede
ser ignorado: corta la lectura en el cliente y usa timeout.

Comparar con una ruta aleatoria inexistente ayuda a identificar fallback SPA. No
imprimas cuerpos secretos ni cabeceras Set-Cookie/Location sin sanitizar.

Si OpenSSL está disponible, verificar certificado/SNI y negociación de protocolos
con `s_client` sobre el host autorizado. Un handshake válido con el protocolo por
defecto no prueba rechazo de TLS obsoleto: requiere comprobaciones separadas. No
instales un scanner para rellenar un estado; documenta la verificación pendiente.

## Dependencias

Ejecutar donde residan los manifiestos del release revisado:

```powershell
composer audit --no-dev --format=json
npm --prefix frontend audit --omit=dev --json
```

Python, si `pip-audit` ya existe en el entorno de auditoría: auditar el lock/export
de producción usando opciones compatibles con esa versión, preferiblemente
`--no-deps --disable-pip` sobre un requirements totalmente fijado y sin líneas
ejecutables/editables. No permitir instalaciones/resolución arbitraria durante la
auditoría. Registrar paquetes no cubiertos y revisar aparte los de build/desarrollo.

Estas consultas pueden comunicar nombres/versiones al proveedor de advisories;
respetar restricciones de salida/paquetes privados. Conservar exit code y distinguir
hallazgos de errores de autenticación/red. No usar `npm audit fix`, `composer update`
ni `pip install` como parte de la comprobación.
