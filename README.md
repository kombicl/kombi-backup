# Kombi Backup

Plugin de WordPress para respaldar el sitio completo (archivos y base de datos) en un solo `.zip`. Con el instalador se levanta en otro servidor y queda funcionando con la URL nueva. También restaura un respaldo sobre el sitio actual, por ejemplo para publicar una versión nueva preparada en local.

Herramientas → **Kombi Backup**.

## Qué hace

| Función | Cómo |
|---|---|
| Respaldo completo | Todo lo que está bajo la carpeta de WordPress más las tablas con el prefijo del sitio. Avanza en pasos AJAX de 10 s (no choca con los timeouts del hosting) y se retoma si un paso se corta. |
| Instalar en otro servidor | Se sube `kombi-installer.php` y el `.zip` a una carpeta vacía y se abre en el navegador. El instalador pide la clave del respaldo y los datos de la base de datos. Extrae los archivos, importa la base y reemplaza la URL y la ruta antiguas por las nuevas, también dentro de datos serializados. Ajusta `wp-config.php` y `.htaccess` y se borra solo al terminar. |
| Restaurar aquí | Sirve para un respaldo de este sitio o uno subido desde otro. Se conservan `wp-config.php`, `.htaccess` y la URL de este sitio. Antes ofrece crear un respaldo de seguridad. El sitio muestra la página de mantenimiento mientras dura. |
| Subir respaldo | La subida va por trozos bajo el límite de `upload_max_filesize`, así que entran respaldos de varios GB. |

## Formato del respaldo

Es un `.zip` estándar: se abre con cualquier descompresor.

```
kombi-backup/manifest.json        URL, ruta, prefijo, versiones, hash de la clave del instalador
kombi-backup/database-001.sql     volcado SQL (una sentencia por línea, partes de 32 MB)
wp-admin/ wp-includes/ wp-content/ …
```

- El ZIP lo escribe el propio plugin, solo con zlib (no necesita `ZipArchive`). Se escribe solo anexando, con ZIP64 para más de 4 GB o más de 65.535 archivos.
- Las imágenes, videos y otros formatos ya comprimidos se guardan sin volver a comprimir. Los archivos de más de 64 MB también.
- Se omiten `.git`, `node_modules`, las cachés y las carpetas de otros plugins de respaldo. La lista se puede editar en la pantalla.
- Los respaldos quedan en `wp-content/kombi-backups/`, protegida por `.htaccess` y `web.config`. Los nombres llevan un sufijo aleatorio y la metadata va en `.php` con `exit` (por nginx). Se descargan solo a través del escritorio.

## Seguridad del instalador

- **Instalación nueva:** pide la clave de 12 caracteres que muestra el plugin. El manifiesto solo guarda su hash.
- **Restaurar:** usa un token de un solo uso que vence en 1 hora, generado desde el escritorio.
- El estado, que incluye la contraseña de la base de datos, se guarda en un `.php` con `exit` dentro de una carpeta temporal bloqueada.
- Al terminar borra el instalador, la carpeta temporal, el token y, si se marcó, el `.zip`. Si una restauración queda a medias, el plugin borra el instalador abandonado al día siguiente.

## Compatibilidad

- PHP 7.4+ con `zlib` y `mysqli`. WordPress 6.0+.
- Si el servidor nuevo es MariaDB o MySQL 5.7, cambia las intercalaciones `utf8mb4_0900_*` de MySQL 8.
- Si `wp-config.php` calcula `WP_HOME`/`WP_SITEURL` (por ejemplo, con una lista de hosts permitidos), el instalador los fija a la URL nueva.
- Si `wp-config.php` está un nivel arriba de la carpeta de WordPress, se incluye en el respaldo.
- No copia el `.user.ini` ni el `php.ini` del servidor de origen.

## Limitaciones (v0.1)

- Multisitio: no soportado.
- Restaurar sobrescribe los archivos del respaldo, pero no borra los que existen solo en el destino. Por ejemplo, un plugin eliminado queda en disco, aunque inactivo, porque la lista de plugins activos viene de la base de datos.
- Solo respalda lo que está bajo `ABSPATH` (no un `WP_CONTENT_DIR` movido fuera).
- No cambia el prefijo de tablas al instalar.

## Desarrollo

Probado de punta a punta en local con kombicl.test (6.214 archivos, 267 MB → ZIP de 170 MB en 90 s):

1. Respaldo y luego instalación en una carpeta y una base nuevas, con otra URL: 0 URLs antiguas y 0 datos serializados inválidos.
2. Restauración sobre ese sitio: se reemplazaron la base y los archivos, y se conservaron `wp-config.php` y la URL.
