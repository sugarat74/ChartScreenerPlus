# Decisiones de arquitectura móvil

## Tecnología

Comparar React Native + Expo, React Native sin framework y Capacitor según las
pantallas reales, módulos nativos, experiencia táctil, equipo y mantenimiento.
React Native recomienda un framework para aplicaciones nuevas [S1]. Expo reduce
la configuración de navegación, módulos y builds, pero no convierte DOM/Tailwind
en vistas nativas. Capacitor reutiliza una UI web dentro de un runtime nativo [S7];
puede convenir cuando conservar esa UI es prioritario.

Con Expo, elegir una combinación compatible de SDK, React Native, React, Node y
módulos; consultar versiones y lockfiles al implementar. Usar development builds
para verificar módulos propios [S3]. Si se adopta CNG, mantener app config/plugins
como fuente y generar android/ios; no editar a mano archivos que se regenerarán.
Versionar proyectos nativos solo si el equipo decide asumir su mantenimiento [S4].

Reutilizar DTO, contratos, validadores puros, serializadores, catálogos y tokens de
diseño. Adaptar navegación, controles, almacenamiento, accesibilidad y transporte.
No importar el módulo web completo si lee document, window, cookies o Vite env.
Evitar una biblioteca de componentes universales antes de demostrar su valor.

## Git y dependencias

Monorepo: un main integrable, ramas cortas por feature, releases independientes
por cliente. Android/iOS suelen ser targets de la misma app React Native.
Worktrees permiten trabajar simultáneamente sin mezclar cambios; no renombrar,
borrar ni reutilizar los ajenos. No usar ramas permanentes web/android/ios.

Preservar ubicaciones actuales cuando moverlas rompa deploys y herramientas.
Introducir paquetes compartidos con API pública y exports; evitar imports a src
de otra aplicación. Mantener las dependencias en una sola dirección:
clientes -> paquetes puros; clientes -> API; API -> servicios internos.

Si se eligen npm workspaces: acordar una raíz, migrar lockfiles de forma explícita,
conservar los comandos de assets existentes y usar instalación reproducible desde
raíz. Excluir prototipos. Probar Metro, Vite, TypeScript y resolución de dependencias;
evitar React duplicado dentro de cada app y módulos nativos duplicados [S2].
No introducir Turborepo/Nx sin necesidad medida de orquestación/caché.

En repos separados: publicar contratos/SDK versionados, automatizar pruebas de
consumidores y documentar orden de releases. No copiar tipos manualmente entre repos.

## API y autenticación

Mantener reglas, ranking y persistencia en el backend existente. Modelar contratos
HTTP con OpenAPI y fixtures; los tipos generados no sustituyen validación de entrada,
autorización ni pruebas del servidor. Versionar el contrato antes de distribuir
clientes que pueden permanecer meses sin actualizar. Cambios aditivos primero;
retirar un contrato mediante una política documentada de soporte.

En Laravel, Sanctum admite sesión web y tokens móviles [S5]. Mantener cookies/CSRF
para la SPA; añadir emisión de token por dispositivo, expiración y revocación.
Sanctum no proporciona por sí solo un flujo OAuth de refresh tokens: escoger
reautenticación al expirar o diseñar ese flujo aparte. Usar roles/ownership además
de abilities. Nunca confiar en el nombre del dispositivo para autorización.
Revisar endpoints de registro/logout existentes: pueden exigir sesión.

En móvil, guardar tokens con SecureStore, no en AsyncStorage ni logs; tratar errores
de lectura, expiración y revocación [S6]. Eliminar caché privada al cambiar cuenta.
El logout remoto revoca la credencial, el local borra la copia; si no hay red,
explicar que la revocación remota queda pendiente hasta recuperarla o expirar.
No afirmar que desinstalar la app revoca el acceso.

Extender auditoría y gestión de dispositivos sin fingir que eliminar filas de
sesiones web invalida tokens. Diferenciar “cerrar sesiones web”, “revocar dispositivo”
y “revocar todos los accesos”; preservar la semántica existente hasta implementar
la nueva de forma verificable. Un token móvil de un Admin no debe adquirir acceso
operativo si el alcance inicial excluye Admin: exigirlo en servidor.

## Gráficos y red

Una librería de gráficos para navegador requiere un renderer adaptado. Evaluar una
WebView limitada al gráfico, con HTML/JS empaquetados, frente a un renderer nativo.
No envolver toda la app por inercia. La API se consulta fuera de la WebView; el puente
solo transporta datos públicos validados y eventos permitidos, nunca tokens.
Restringir navegación y scripts remotos; conservar atribuciones [S8, S9].
Probar zoom, pan, crosshair, orientación y volumen con el máximo payload permitido
en Android/iOS reales. Registrar la decisión después del spike.

Definir base URL por entorno; configuración EXPO_PUBLIC_* es pública. localhost en
un teléfono no es el ordenador de desarrollo. Usar API de staging por HTTPS o red
local de desarrollo explícita; no exponer PostgreSQL ni el engine. Mantener
cancelación, timeouts, Retry-After y comportamiento sin conexión; no reintentar a
ciegas mutaciones no idempotentes.

## Integración y distribución

Partir de un contrato aprobado: backend y móviles pueden avanzar con fixtures;
integrar contra una API real antes de cerrar la feature. El backend compatible
se publica antes del cliente que lo necesita. CI de contratos/paquetes compartidos
dispara comprobaciones de todos sus consumidores. Un build móvil no publica web.

Android puede desarrollarse localmente con su toolchain. iOS local requiere macOS
y Xcode; EAS permite compilar remotamente desde Windows, pero la simulación local
de iOS sigue requiriendo macOS [S3, S10]. Una compilación remota no demuestra QA en
un iPhone. Registrar cuentas, signing, dispositivos y costes como dependencias
de la distribución, sin bloquear el diseño que se puede completar.

EAS Build es una opción, no una obligación. Definir profiles development/preview/
production, identificadores y números de build independientes. Publicar y habilitar
OTA solo dentro del alcance autorizado; cambios nativos requieren binario compatible.
Revisar requisitos vigentes de tiendas, privacidad y eliminación de cuenta antes
de distribuir; no añadir permisos o SDKs ajenos al producto.

Fuentes S1–S10: [sources.md](sources.md).

