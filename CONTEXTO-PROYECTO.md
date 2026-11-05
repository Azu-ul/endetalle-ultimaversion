# enDetalle — contexto completo para continuar en un chat nuevo

> Pegale este archivo (o subí el zip) al nuevo chat y decile: "Leé CONTEXTO-PROYECTO.md y seguimos".
> Última actualización de este documento: 5 de noviembre de 2026 (CMS v2.1.0, actualización 8).
> Este archivo NO contiene contraseñas ni claves. Nunca se las pidas a Azu/Guillermo por el chat: las ponen ellos directamente en el `.env` del servidor.

## 0. Estado de este zip (leer primero)
- `backend/` está en **v2.1.0** y coincide con el código que corre en el cPanel (verificado archivo por archivo contra el `app.zip` del servidor).
- `frontend/` es **fuente vieja (hasta el Bloque 11, antes de la v2.0.0)**. El código fuente React/TS de la v2.0.0 y v2.1.0 **no estaba en ningún zip**: solo existe compilado en `compilado-v2.1.0/` (lo que está publicado). **No compilar `frontend/` y subirlo**: pisaría el sitio con la UI vieja.
- Faltan también los tests `CmsV2Test` y `StaffMessagesTest` (el backend de la v2 sí está).
- Para cambios de frontend hay dos caminos: (a) reconstruir la fuente v2.x a partir de `frontend/` viejo + el bundle compilado, o (b) editar solo lo que haga falta. Decidirlo con Azu antes de tocar la UI.
- `public/instalar.php` ya no se incluye (se borra del servidor).

## 1. Qué es y quién es quién
- **enDetalle**: biblioteca de detalles constructivos (planos DWG / modelos SketchUp SKP) con membresías pagas y mini red social (comentarios, favoritos, votos, perfiles públicos, autores).
- **Cliente / dueño del negocio:** Guillermo (padrastro de Azu). Él es el **superadmin** del sitio y el dueño del hosting cPanel.
- **Quien desarrolla/gestiona con Claude:** Azu (azulsofiadavid@gmail.com). Habla en español rioplatense, tono cercano. A veces escribe Guillermo desde la misma cuenta.
- El sitio **definitivo es el de WNPower** (cPanel). Un Hostinger anterior nunca tuvo pagos.
- Prioridad que pidió Guillermo para lo que viene: **seguridad ultra sólida** (los datos valen y los usuarios tienen que poder entrar siempre) y **velocidad de carga**.

## 2. Producción (ya funcionando)
| Dato | Valor |
|---|---|
| URL | https://endetalle.estudio-nelli.com.ar |
| Hosting | WNPower, cPanel `cpanel171.wnpservers.net:2083`, usuario `estudion` (sin SSH ni terminal) |
| App en el servidor | `/home/estudion/endetalle-app` (fuera de `public_html`) |
| Docroot del subdominio | `/home/estudion/endetalle-app/public` |
| PHP | 8.3 (`/usr/local/bin/ea-php83`) |
| Base de datos | MySQL `estudion_endetalle` con usuario `estudion_endetalle` (la contraseña la tiene Azu/Guillermo en el `.env`) |
| Superadmin | estudio.nelli@gmail.com |
| Mail saliente | SMTP con `no-responder@estudio-nelli.com.ar` (contraseña en `.env`). Funciona; Gmail lo acepta pero puede mandarlo a Spam. |
| Avisos de venta / contacto | `CONTACT_EMAIL=estudio.nelli@gmail.com` (variable opcional `SALES_NOTIFY_EMAIL` para otra casilla) |
| Pagos | **Mercado Pago real ya configurado y probado** (compra real + reembolso desde MP). PayPal: sin confirmar. |

**Crons activos en cPanel** (no se usa `schedule:run`; cada tarea tiene su cron):
- `0 3 * * *` → `ea-php83 /home/estudion/endetalle-app/artisan subscriptions:maintain >> /home/estudion/logs/endetalle-maintain.log`
- `30 3 * * *` → `ea-php83 /home/estudion/endetalle-app/artisan backup:run >> /home/estudion/logs/endetalle-backup.log`
- (hay otro de `wp-cron.php` que no es de este proyecto)

**Cosas que Azu/Guillermo todavía tienen que hacer (recordáselas):**
- Borrar `public/instalar.php` del servidor (ya no hace falta) y los zips viejos de `/home/estudion`. También se puede borrar `app/Http/Controllers/VoteController.php` (quedó huérfano; ya no está referenciado en rutas) y los assets viejos de `public/assets`.
- Bajar un backup desde el panel y guardarlo en su compu/nube (los backups quedan en el mismo hosting).
- Guardar una copia del `.env` por separado (los backups no lo incluyen a propósito).

## 3. Arquitectura
- **Backend:** Laravel (PHP 8.3), Sanctum con **cookies de sesión** (SPA same-origin), MySQL en producción, SQLite en memoria para los tests. Roles con spatie: `superadmin`, `colaborador`, `usuario`.
- **Frontend:** React + TypeScript + Vite + Tailwind. Se compila con `VITE_API_URL=""` (misma URL para todo: `/api/...`).
- **Un solo origen:** Laravel sirve la SPA (`SpaController`, `SPA_INDEX=spa/index.html`) y la API. El build va en `endetalle-app/public/assets` y `endetalle-app/spa/index.html`.
- **Sesiones/Sanctum:** para que una petición sea "stateful" necesita Referer u Origin → el header `Referrer-Policy` debe ser `strict-origin-when-cross-origin` (ya está en `public/.htaccess`; NO volver a `no-referrer`, rompe las sesiones).
- **Config:** todo por `.env` (valores con comillas dobles escapando `\ " $`). No hay config cache. Logs: `storage/logs/laravel-YYYY-MM-DD.log` (en cPanel: visor `.../viewer/home/estudion/endetalle-app/storage/logs/...`).
- **Route cache:** `bootstrap/cache/routes-v7.php` **se versiona en el zip del servidor y hay que regenerarlo cada vez que cambian rutas**, construyéndolo en una carpeta con la misma ruta absoluta que el servidor (`/home/estudion/endetalle-app`), si no queda con rutas del sandbox (esto ya causó un error en `/up`).
- **Archivos subidos:** disco privado `storage/app/private` (detalles DWG/SKP, comprobantes de reembolso); descarga solo vía rutas autenticadas.

### Estructura de este zip
```
backend/              Laravel (app/, routes/api.php, config/, database/, tests/) — v2.1.0
frontend/             React (FUENTE VIEJA, ver sección 0)
compilado-v2.1.0/     frontend compilado publicado hoy (spa/index.html + public/assets)
deploy/               scripts y docs de deploy viejos (Hostinger; ver DEPLOY.md). El deploy real hoy es por zip a cPanel.
.github/              workflow antiguo de deploy
_herramientas-de-prueba/  scripts de Playwright y router para probar en local (ver sección 7)
```

## 4. Lo que está implementado (todo en producción)
- Instalador web (`instalar.php`), DB, usuarios, planes, categorías/subcategorías, SMTP.
- Planes **Free / Estudiante / Pro**, precios en ARS/USD, períodos mensual / 3 meses / anual con **descuentos configurables desde el panel** (por defecto 15% y 30%; claves `discount_quarterly` y `discount_yearly` en la tabla `settings`).
- **Pagos con Mercado Pago** (Checkout Pro + webhook `/api/webhooks/mercadopago`, firma opcional con `MERCADOPAGO_WEBHOOK_SECRET`). Al venderse: activa plan, mail de confirmación al comprador y **aviso de venta** al dueño.
- **Reembolsos** con botón de arrepentimiento: el usuario solicita (con aviso claro de revisar **Spam**), el superadmin aprueba adjuntando comprobante (se envía por mail) o rechaza con motivo; el reembolso aprobado corta el plan. Si se devuelve la plata directo desde Mercado Pago (webhook) el plan se corta **pero no se manda mail al usuario** (idea pendiente: avisarle también).
- Descargas con paywall: Free no descarga nada; Estudiante no descarga SKP; modal "Desbloqueá esta descarga" con CTA a planes; botones gris/opacidad baja cuando el detalle no tiene archivo.
- Panel admin "EnDetalle CMS" (solo superadmin): Dashboard (con **Top 5 más vistos**), Aprobaciones, Detalles (editor con **"Subido por"** → perfil, categoría + subcategoría), **Categorías**, Clientes (crear cliente + plan manual, editar, suspender), **Staff** (administrador + colaboradores), Reportes, **Comentarios** (moderación), Planes y Precios, Pagos y órdenes, Reembolsos, Configuración, **Backups**.
- **Comentarios públicos:** en hilo (un nivel de respuestas), fecha y hora completas, **editar** (autor, o superadmin) con marca "editado", eliminar.
- **Backups:** `BackupService` genera un `.zip` con `database.sql` (volcado en PHP puro) + `sitio/` (código, `storage/app`, sin `.env` ni `vendor`). Manual desde el panel y automático por cron. Retención: 7 automáticos y 10 manuales (`BACKUP_KEEP_AUTO` / `BACKUP_KEEP_MANUAL`). Descarga solo para superadmin.
- UX: ojito para ver contraseña, botón **Cerrar sesión** abajo de la sidebar, mensajería en la sidebar (solo colaborador/superadmin), verificado = "Verificado por el equipo de EnDetalle", título "EnDetalle CMS".
- Notificaciones por mail en español (`app/Notifications/*Es.php`).

### Novedades de la actualización 8 (CMS v2.1.0, 5/11/2026)
- **Términos de Uso y Política de Privacidad**: textos en `backend/resources/legal/terms.txt` y `privacy.txt` (Argentina: Ley 25.326, Ley 24.240 y botón de arrepentimiento, Mercado Pago/PayPal, imágenes protegidas, licencia de uso, colaboradores). `SiteController::defaultLegal()` los muestra cuando `terms_content`/`privacy_content` de Configuración están vacíos (reemplaza `{{SITE_URL}}`, `{{CONTACT_EMAIL}}`, `{{REFUND_DAYS}}`, `{{UPDATED}}`). Lo que el admin escriba en el panel tiene prioridad. **Conviene que los revise un abogado** antes de abrir el sitio al público.
- **Autores destacados**: el superadmin ya no se excluye del directorio (`UserController::index`).
- **Mensajes privados del equipo** (solo colaboradores y superadmin): `MessageController` + rutas `/api/messages/*`. Reutiliza la tabla `messages` (migración `2026_11_05_100000_staff_messages_upgrade` suma `sender_deleted_at`/`recipient_deleted_at`). Máx. 2000 caracteres, 40 envíos/min. `/api/track` devuelve `unread_messages`. Pantalla `MessagesPanel.tsx` (se actualiza cada 10 s).
- Prueba en navegador: `_herramientas-de-prueba/staff-msgs.js` (no está en este zip).

### Novedades de la actualización 7 (CMS v2.0.0, 3/11/2026)
- **Me gusta / No me gusta** por detalle (tabla `detail_reactions`; `details.votes_count` = me gusta, `details.dislikes_count`). Reaccionar pide cuenta con email verificado (PUT/DELETE `/api/details/{id}/reaction`).
- **Detalles públicos** (`details.is_public`): se abren sin cuenta, solo si el acceso es libre.
- **Programación de publicaciones**: `published_at` a futuro; `Detail::published()` filtra por `published_at <= now()`.
- **Avisos / notificaciones**: tablas `admin_notices` + `user_notices`; ruta `/notificaciones`.
- **Visitas y online**: `POST /api/track` (`visitors` + `page_views`, sin IP; ignora bots). Dashboard con online y visitas por período. `subscriptions:maintain` borra visitas de más de 120 días. Zona horaria: `CMS_TIMEZONE`.
- **Versión del CMS**: `config/cms.php` (hoy 2.1.0).
- **Excel de usuarios**: Backups → "Usuarios y clientes (Excel)" (`.xlsx` y `.csv`).
- **Etiquetas**: Admin → "Etiquetas"; van como `keywords` / `article:tag`.
- **Imágenes protegidas y livianas** (`App\Services\Images`): original JPG ≤ 2 MB y WebP en disco **privado** (`storage/app/private/detail-images`), miniatura pública. Imagen grande por `/api/details/{id}/media/{cover|id}`. "Optimizar imágenes ahora" en Configuración (o `php artisan images:optimize`).
- **Configuración**: "Título del sitio" y "Descripción"; viajan en `window.__BOOT__`.
- **Portada**: carrusel (`HeroCarousel`), Admin → "Carrusel" (tabla `banners`).
- **Clientes**: lista todos los usuarios menos el admin (`GET /api/admin/users?role=all`).
- **Dashboard**: "Cobrado este mes", vencimientos, detalles programados.
- **Velocidad**: JS inicial 596 → 308 KB, miniaturas lazy, compresión y caché en `.htaccess`.
- La API sin sesión responde 401 JSON.

## 5. Cómo se actualiza el sitio (sin SSH)
1. Se arma un zip con la estructura `endetalle-app/...` conteniendo **solo los archivos cambiados** (backend + `bootstrap/cache/routes-v7.php` regenerado si cambiaron rutas + `spa/index.html` + `public/assets/*` nuevos del build).
2. Azu lo sube a `/home/estudion` (Administrador de archivos → Cargar), **Extraer** (pisa sin preguntar), borra el zip y recarga con Ctrl+F5.
3. Si hay migraciones nuevas, correrlas una vez (cron de una sola vez): `/usr/local/bin/ea-php83 /home/estudion/endetalle-app/artisan migrate --force`. Las migraciones de las actualizaciones 7 y 8 ya se corrieron.
4. **No re-extraer jamás `endetalle-para-cpanel.zip` (versión vieja)**.
- Se aplicaron las actualizaciones 1 a 8.

## 6. Pendientes / ideas
1. **Revisión de seguridad + velocidad (pedido de Guillermo).** Revisar: rate limiting (login, registro, reset, comentarios, descargas), cabeceras (CSP, HSTS, X-Content-Type-Options, X-Frame-Options), cookies (`SESSION_SECURE_COOKIE=true`, `SameSite`), política de contraseñas, 2FA para superadmin, límites y tipos de archivo en subidas, acceso a `storage`/`.env` desde la web, permisos, `APP_DEBUG=false`, logs sin datos sensibles, `composer audit`/`npm audit`, SPF/DKIM/DMARC, backups externos, WAF (Cloudflare), auditoría de acciones del admin. Velocidad: medir consultas en producción, `config:cache`, sesiones en archivo, OPcache, quizá CDN; `/api/details` trae hasta 100 detalles: si el catálogo pasa de ~100, paginar.
2. **Importar usuarios de WordPress** de la otra web de Guillermo (necesita CSV; los hashes no se reutilizan → mail para crear contraseña).
3. Ideas fuera de la v2.1.0 en mensajería: adjuntar archivos, aviso por mail de mensaje nuevo, mensajes de grupo.
4. **Pregunta abierta:** ¿un detalle debe poder tener **varias subcategorías**? Hoy una sola (`subcategory_id`).
5. Mail al usuario cuando se devuelve la plata directo desde Mercado Pago.
6. Confirmar/configurar PayPal; ver si se cambia la casilla de avisos de venta.
7. Revisar SPF/DKIM (cPanel → Email Deliverability).
8. Ideas fuera de la v2.0.0: avisos también por email, bandeja de "No me gusta" con comentario, etiquetas con página propia (`/etiqueta/...`), historial de descargas de ilustraciones.
9. **Recuperar la fuente del frontend v2.x** (ver sección 0).

## 7. Probar en local
- **Tests backend:** `cd backend && composer install && php artisan test`. En su momento pasaban 206 (incluyendo `CmsV2Test` y `StaffMessagesTest`, que **no están en este zip**); las pruebas de este zip no se corrieron al consolidar.
- **Frontend:** en `frontend/` (`npm install`), typecheck `npx tsc --noEmit -p .`, build `VITE_API_URL="" npx vite build --outDir <dir> --emptyOutDir` (ver advertencia de la sección 0).
- **E2E con navegador real:** Playwright; en `_herramientas-de-prueba/` están `smoke.js`, `smoke2.js`, `staff.js`, `cm.js`, `router.php` y `startsrv.sh`. Servidor local: `php -S 127.0.0.1:8081 -t public router.php`; en el `.env` local `SANCTUM_STATEFUL_DOMAINS="127.0.0.1:8081"` y `APP_URL` local. Si el login falla de golpe, es el rate limit: `php artisan cache:clear`.
- Las rutas de la SPA son en español: `/ingresar`, `/planes`, `/mi-cuenta`, `/mensajes`, `/notificaciones`, `/detalle/{slug}`, `/perfil/{id-slug}`; el panel admin se abre con `/admin`.

## 8. Reglas de trabajo con Azu
- Preferencia suya: **código en texto o archivo completo con botón de descargar; no artefactos.**
- Yo no ingreso contraseñas, claves ni tokens: los pone ella/Guillermo. Descargas y configuración persistente (crons, etc.) se hacen **con su permiso explícito**.
- Con el navegador (extensión Chrome) se pueden mirar logs, cron y "Monitorizar el envío" de cPanel.
- Después de cada cambio: compilar, correr tests, probar en navegador local y entregar zip + decir qué queda pendiente.
