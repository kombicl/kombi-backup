# Accesibilidad

Queremos que cualquier persona pueda respaldar, migrar y restaurar su sitio con Kombi Backup, incluidas quienes usan lector de pantalla, solo teclado o zoom.

## Objetivo

Cumplir las **WCAG 2.2 nivel AA** en la pantalla del plugin (Herramientas → Kombi Backup) y en el instalador (`kombi-installer.php`).

## Qué hacemos hoy

- **Teclado:** todos los controles son botones, enlaces o campos nativos, con foco visible. El menú de acciones usa `<details>`/`<summary>`, y el diálogo de restauración usa `<dialog>` nativo, que encierra el foco y se cierra con Esc.
- **Lectores de pantalla:**
  - el progreso se anuncia con `role="status"` / `aria-live="polite"`;
  - los íconos decorativos llevan `aria-hidden`;
  - el logo tiene texto alternativo;
  - el botón de acciones tiene nombre accesible ("Más acciones").
- **Contraste:** los botones principales usan el azul Kombi oscurecido (4,8:1 con texto claro). Los textos secundarios cumplen 4,5:1.
- **Tamaño de toque:** los botones del instalador miden 44 px de alto como mínimo.
- **Responsive:** las pantallas funcionan desde 375 px de ancho sin scroll horizontal.
- **Idioma:** la interfaz está en español y el instalador declara `lang="es"`.

## Entornos probados

- Chrome actual en Windows, a 1280 px y a 375 px (móvil), sin scroll horizontal.

## Limitaciones conocidas

- Todavía no hay una prueba sistemática con otros navegadores, solo con teclado ni con zoom al 200 %.
- El área para arrastrar el `.zip` también se activa con el teclado (es un campo de archivo), pero arrastrar no tiene equivalente que no sea elegir el archivo.
- La barra de progreso es visual; el porcentaje y la etapa se anuncian como texto.
- No hemos hecho una prueba formal con NVDA, JAWS ni VoiceOver.

## Reportar una barrera

Si algo te impide usar el plugin, abre un [issue](https://github.com/kombicl/kombi-backup/issues/new?template=bug_report.yml) con la etiqueta `accesibilidad`, o escribe a **contacto@kombi.cl**. Cuéntanos:

- qué intentabas hacer;
- qué tecnología de apoyo usas;
- dónde ocurrió.

Respondemos en un máximo de 5 días hábiles.
