# Cómo contribuir

Gracias por querer mejorar Kombi Backup. Todo aporte es bienvenido: reportes de errores, ideas, documentación o código.

## Reportar un error

1. Busca en los [issues](https://github.com/kombicl/kombi-backup/issues) si ya está reportado.
2. Abre uno nuevo con la plantilla **Reportar un error**. Incluye la versión del plugin, de WordPress y de PHP, el tipo de hosting y los pasos para reproducirlo.
3. **No publiques** claves del instalador, contraseñas, volcados de base de datos ni URLs de restauración. Si el problema es de seguridad, sigue [SECURITY.md](SECURITY.md) y no abras un issue público.

## Proponer cambios

1. Haz un fork y crea una rama desde `main` (`fix/descripcion` o `feature/descripcion`).
2. Mantén el estilo del código existente: [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/), tabs, comentarios breves en español.
3. El código debe funcionar en **PHP 7.4 a 8.x** y **WordPress 6.0+**. No uses `match`, `str_contains`, argumentos con nombre ni tipos unión.
4. Prueba de punta a punta lo que toques: crear el respaldo, instalarlo en una carpeta y una base nuevas con otra URL, y restaurar sobre un sitio existente.
5. Agrega una línea en `CHANGELOG.md`, bajo **Sin publicar**.
6. Abre el pull request con la plantilla y describe qué probaste.

## Publicación

Cada merge a `main` publica un release automáticamente si pasa la revisión de sintaxis. Los sitios con el plugin se actualizan solos, así que **`main` debe estar siempre lista para producción**. Los cambios grandes van primero en una rama.

La versión es `MAYOR.MENOR` de la cabecera de `kombi-backup.php` más el número de ejecución de la Action. Para un cambio mayor, sube `MENOR` o `MAYOR` en `Version:`.

## Código de conducta

Este proyecto sigue el [código de conducta](CODE_OF_CONDUCT.md). Al participar, aceptas respetarlo.
