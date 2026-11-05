# enDetalle — instrucciones para Claude Code

Proyecto de Azu (español rioplatense, tono cercano). Cliente: Guillermo (superadmin del sitio y dueño del hosting).
Contexto completo en `CONTEXTO-PROYECTO.md`: leelo antes de empezar.

## Cómo trabajamos: todo cambio termina en un zip para cPanel
El sitio está en un cPanel de WNPower **sin SSH ni terminal**. No se hace `git pull`, ni `composer install`, ni `npm run build` en el servidor.
Cada tanda de cambios termina con **un zip que Azu sube, descomprime y listo: el sitio anda**.

### Reglas fijas
- **Nunca** pidas ni escribas contraseñas, claves ni tokens. Los pone Azu/Guillermo directo en el `.env` del servidor.
- **Nunca** incluyas en el zip: `.env`, `vendor/`, `node_modules/`, `storage/` (datos y archivos subidos), `public/instalar.php`, `public/php.ini`, `public/.well-known`, `.git`, `tests/`, `_herramientas-de-prueba/`, `deploy/`.
- Config persistente (crons, etc.) o descargas: solo con permiso explícito de Azu.
- `Referrer-Policy` en `public/.htaccess` debe seguir en `strict-origin-when-cross-origin`. Con `no-referrer` se rompen las sesiones.

### Estructura del repo → estructura del servidor
- `backend/…`  →  `endetalle-app/…` (se saca el prefijo `backend/`).
- Build del frontend → `endetalle-app/spa/index.html` + `endetalle-app/public/assets/*`.
- En el servidor la app vive en `/home/estudion/endetalle-app` (docroot: `.../public`). PHP 8.3: `/usr/local/bin/ea-php83`.
- ⚠️ `frontend/` está **desactualizado** (ver `frontend/LEEME-FRONTEND-DESACTUALIZADO.txt`): el publicado hoy está solo compilado en `compilado-v2.1.0/`. **No compiles `frontend/` ni lo metas en un zip** sin que Azu lo apruebe: pisaría la UI actual con la vieja. Si el cambio es solo de backend, el zip no lleva `spa/` ni `public/assets/`.

### Pasos para cada entrega
1. **Hacer el cambio** y subir la versión en `backend/config/cms.php` (default de `CMS_VERSION`: 2.1.0 → 2.1.1, etc.).
2. **Probar:** `cd backend && php artisan test` (tienen que pasar todos) y `php -l` de cada PHP tocado. Si se toca la interfaz, probarla en el navegador local.
3. **Detectar archivos cambiados** respecto de lo último publicado: `git diff --name-only <tag-o-commit-publicado>`. Después de cada entrega, hacer commit y un tag (`publicado-2.1.1`) para que la próxima comparación sea fácil.
4. **Armar el zip solo con los archivos cambiados**, bajo `endetalle-app/…`, con rutas con `/` y saltos de línea LF. En Windows no uses `Compress-Archive` (puede generar rutas con `\`): usá `tar -a -c -f archivo.zip …`, 7-Zip o un script de Python con `zipfile`.
   - Nombre: `endetalle-actualizacion-N.zip` (N = número siguiente; la última fue la 8).
   - Dentro, además, `LEEME-ACTUALIZACION-N.txt` con los pasos para Azu (ver abajo).
5. **Verificar el zip antes de entregarlo:** listar el contenido; todo debe estar bajo `endetalle-app/`; sin ninguno de los archivos prohibidos; que cada migración nueva esté incluida.
6. **Entregar** el zip y decir: qué cambió, si hay migración, y qué queda pendiente. Actualizar `CONTEXTO-PROYECTO.md` (novedades + número de actualización).

### Si cambiaron las rutas (`backend/routes/*.php`)
El servidor usa `bootstrap/cache/routes-v7.php`. Si queda viejo, las rutas nuevas **no existen**. Hay dos opciones:
- **Regenerarlo** con `php artisan route:cache` en una copia ubicada exactamente en `/home/estudion/endetalle-app` (en Windows: WSL o Docker). Si el archivo queda con rutas de otra carpeta, no sirve (esto ya causó un error en `/up`). Revisar con `grep` que no aparezcan rutas locales, e incluirlo en el zip.
- **O no incluirlo** y avisar a Azu en el LEEME que **borre** `endetalle-app/bootstrap/cache/routes-v7.php` del servidor (el sitio funciona igual, solo un poco más lento). Nunca entregar un zip con rutas nuevas y el cache viejo sin avisar.

### Si hay migraciones nuevas (`backend/database/migrations`)
Incluirlas en el zip y poner en el LEEME este paso, que se corre **una sola vez** como cron de una sola vez en cPanel:
`/usr/local/bin/ea-php83 /home/estudion/endetalle-app/artisan migrate --force`
Escribir las migraciones para que no rompan datos existentes (columnas nuevas con default o nullables; nada de borrar columnas con datos sin avisar).

### Si cambian dependencias (`composer.json` / `composer.lock`)
En el servidor no se puede correr composer. Avisar a Azu antes: hay que mandar también la carpeta `vendor/` (zip grande) y solo con `composer install --no-dev` en PHP 8.3.

### Plantilla del LEEME-ACTUALIZACION-N.txt
```
enDetalle - actualizacion N (CMS vX.Y.Z)
Que trae: <resumen corto>

1) Subir este zip a /home/estudion (Administrador de archivos > Cargar) y usar Extraer. Pisa archivos sin preguntar. Despues borrar el zip.
2) <solo si hay migracion> Correr la migracion UNA vez (cron de una sola vez): /usr/local/bin/ea-php83 /home/estudion/endetalle-app/artisan migrate --force
3) <solo si corresponde> Borrar endetalle-app/bootstrap/cache/routes-v7.php
4) Recargar el sitio con Ctrl+F5.
Los archivos viejos de public/assets ya no se usan: se pueden borrar. (Nunca borrar public/assets/.htaccess.)
No incluye .env ni vendor.
```

## Comandos en la terminal
- Cuando corras un bucle `for` (en Bash o PowerShell), mandá toda la salida a un archivo en el scratchpad de la sesión y mostrá solo las líneas que importan (con `grep`, `Select-String`, `head` o `tail`). Nunca vuelques la salida completa en la conversación.
- Preferencia de Azu: código en texto o archivo completo; no artefactos.
