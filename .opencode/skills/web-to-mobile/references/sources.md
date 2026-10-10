# Fuentes primarias

Consulta: **2026-10-08**. Revalidar versiones, toolchains y políticas al implementar.
Las decisiones de arquitectura propuestas son conclusiones propias aplicadas al
repositorio; no son garantías de los proveedores.

| ID | Fuente | Hecho que sustenta |
|---|---|---|
| S1 | [React Native: Get Started](https://reactnative.dev/docs/environment-setup) | Recomienda un framework como Expo para apps nuevas; admite trabajar sin él. |
| S2 | [Expo: monorepos](https://docs.expo.dev/guides/monorepos/) | Soporta workspaces; documenta Metro y riesgos de versiones duplicadas. |
| S3 | [Expo: development builds](https://docs.expo.dev/develop/development-builds/introduction/) | Builds propios con módulos nativos, toolchains locales y compilación en EAS. |
| S4 | [Expo: CNG](https://docs.expo.dev/workflow/continuous-native-generation/) | Generación de proyectos nativos desde configuración y tratamiento de Git. |
| S5 | [Laravel 13: Sanctum](https://laravel.com/docs/13.x/sanctum) | Cookies SPA, Bearer móvil, emisión, abilities, expiración y revocación. |
| S6 | [Expo: SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/) | Almacenamiento de credenciales, diferencias de plataforma y reinstalación. |
| S7 | [Capacitor](https://capacitorjs.com/docs) | Runtime nativo que permite reutilizar una aplicación web. |
| S8 | [Lightweight Charts](https://tradingview.github.io/lightweight-charts/docs) | Renderer para navegador y atribución requerida también en móvil. |
| S9 | [React Native WebView: guía](https://github.com/react-native-webview/react-native-webview/blob/master/docs/Guide.md) | Comunicación entre app y WebView y carga de contenido. |
| S10 | [Expo: builds locales](https://docs.expo.dev/build-reference/local-builds/) | Limitaciones del build local EAS en Windows. |
| S11 | [EAS Build en monorepos](https://docs.expo.dev/build-reference/build-with-monorepos/) | Configuración y ejecución desde el directorio de la app. |
| S12 | [Expo Router](https://docs.expo.dev/router/introduction/) | Navegación y rutas de la aplicación móvil. |

Los requisitos de publicación y precios se verifican cuando se prepare esa fase;
no se han evaluado aquí cuentas concretas, costes de EAS ni aceptación en tiendas.

