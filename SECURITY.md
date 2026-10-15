# Política de seguridad

Kombi Backup maneja datos sensibles: el volcado de la base de datos, `wp-config.php` y el acceso para reemplazar un sitio completo. Tomamos en serio cualquier vulnerabilidad.

## Versiones con soporte

Solo la última versión publicada recibe correcciones. El plugin se actualiza solo desde GitHub Releases, así que la mayoría de los sitios la reciben a las pocas horas.

| Versión | Soporte |
|---|---|
| Última `0.1.x` | ✅ |
| Anteriores | ❌ |

## Cómo reportar una vulnerabilidad

**No abras un issue público.** Usa una de estas vías privadas:

1. **GitHub:** en la pestaña [Security → Report a vulnerability](https://github.com/kombicl/kombi-backup/security/advisories/new) de este repositorio.
2. **Correo:** contacto@kombi.cl con el asunto `[Seguridad] Kombi Backup`.

Incluye la versión afectada, una descripción del problema, los pasos para reproducirlo y su impacto. Si puedes, propone una corrección.

## Qué esperar

- Confirmación de recepción en un máximo de **3 días hábiles**.
- Una evaluación inicial y un plan en un máximo de **10 días hábiles**.
- Te avisaremos cuando la corrección esté publicada y, si quieres, te daremos crédito en el CHANGELOG.

## Alcance

Nos interesan especialmente:
- el acceso al instalador sin la clave o con un token de restauración inválido;
- la exposición de respaldos, volcados o `wp-config.php` por web;
- la ejecución de código o la escritura de archivos fuera de la carpeta del sitio (path traversal) durante la extracción;
- la inyección SQL en la importación o el reemplazo de URLs;
- el acceso a las acciones del plugin sin el permiso `manage_options`.

## Buenas prácticas para quienes usan el plugin

- No dejes el `.zip` del respaldo ni `kombi-installer.php` en la carpeta pública más tiempo del necesario.
- No compartas la clave del instalador ni el enlace de restauración.
- Guarda los respaldos descargados en un lugar seguro: contienen toda la base de datos.
