# Changelog

Todos los cambios importantes de Kombi Backup. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

Cada push a `main` publica una versión `MAYOR.MENOR.N` automáticamente (ver `.github/workflows/release.yml`). Las notas de cada release se generan desde los commits; este archivo resume lo importante.

## [Sin publicar]

### Agregado
- Licencia GPL-2.0, guía de contribución, política de seguridad, código de conducta, declaración de accesibilidad y plantillas de issues y pull requests.

## [0.1.1] — 2026-10-15

### Agregado
- Actualizaciones desde GitHub Releases. El plugin declara `Update URI` y lee `info.json` del último release, sin depender de la API de GitHub ni de su límite de consultas.
- Actualización automática activada por defecto. Se desactiva con `define( 'KOMBI_BACKUP_AUTO_UPDATE', false );`.
- Las copias de desarrollo (con `.git`) no se actualizan solas.
- GitHub Action: en cada push revisa la sintaxis en PHP 7.4 y 8.3 y publica el release.

## [0.1.0] — 2026-10-15

### Agregado
- Respaldo completo (archivos + base de datos) en un `.zip` estándar escrito por el propio plugin: solo anexa, soporta ZIP64 y no necesita ZipArchive. Avanza en pasos AJAX que se retoman si uno se corta.
- Instalador `kombi-installer.php`:
  - pide la clave del respaldo;
  - importa la base de datos;
  - reemplaza URL y ruta, también dentro de datos serializados;
  - ajusta `wp-config.php` y `.htaccess`;
  - se borra solo al terminar.
- Restaurar sobre el sitio actual con un token de un solo uso. Conserva `wp-config.php`, `.htaccess` y la URL, y antes ofrece un respaldo de seguridad.
- Subida de respaldos por trozos (respaldos de varios GB).
- Interfaz con la paleta Nord y el logo de Kombi.

### Seguridad y fiabilidad
- Se verifica el CRC de todo el respaldo antes de tocar el sitio.
- La subida por trozos valida el tamaño y el final del ZIP, y un trozo reintentado no se duplica.
- El instalador no revela el nombre del `.zip` antes de la clave.
- `wp-config.php` se lee y se reescribe bien aunque la contraseña tenga `;`, `)`, `'` o `\`.
- Al restaurar se quitan los archivos que no están en el respaldo, solo en `wp-admin`, `wp-includes` y `wp-content` y respetando las exclusiones.
- Las columnas `GENERATED` quedan fuera del volcado, y las vistas se guardan sin el nombre de la base de origen.

[Sin publicar]: https://github.com/kombicl/kombi-backup/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/kombicl/kombi-backup/releases/tag/v0.1.1
[0.1.0]: https://github.com/kombicl/kombi-backup/commit/ebc6c28
