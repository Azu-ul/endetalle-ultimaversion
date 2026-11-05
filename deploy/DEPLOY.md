# Cómo publicar enDetalle en el hosting (cPanel)

Guía paso a paso, pensada para hacerla **sin saber programar**. Cada paso dice dónde hacer clic y qué escribir.
Está escrita para un hosting compartido con **cPanel** (probado en el plan de WNPower), con **PHP 8.3** y MySQL,
**sin terminal (SSH)** y sin Composer en el servidor: todo se prepara en la PC y se sube por el *Administrador de archivos*.

> **Palabras que se repiten en la guía**
> - **Sitio** (o *frontend*): lo que ve la gente, por ejemplo `https://midominio.com.ar`.
> - **API** (o *backend*): el "motor" que guarda usuarios, pagos y archivos, por ejemplo `https://api.midominio.com.ar`.
>   El sitio y la API van en **dos direcciones distintas, pero del mismo dominio** (así las sesiones funcionan).
> - **USUARIO**: el nombre de usuario de cPanel. Se ve en cPanel, columna derecha, "Directorio principal": `/home/USUARIO`.
> - Donde dice `midominio.com.ar`, poné el dominio real del cliente.

---

## 0. Antes de empezar: qué hay que tener a mano

Pedile esto al cliente (la lista completa, con explicaciones, está al final en **"Datos que hay que pedirle al cliente"**):

- [ ] El dominio y el acceso a cPanel.
- [ ] Una casilla de correo para los envíos del sitio (se crea en el paso 11).
- [ ] Las claves de **producción** de Mercado Pago y de PayPal.
- [ ] El email y una contraseña larga para el administrador del sitio.

---

## 1. Preparar los archivos en tu PC (una sola vez por versión)

1. Abrí la terminal de **Laragon** (botón *Terminal*), así ya están `php`, `composer`, `node` y `mysqldump`.
2. Andá a la carpeta del proyecto y ejecutá (cambiá la dirección por la de la API real):

   ```powershell
   powershell -ExecutionPolicy Bypass -File deploy\build.ps1 -ApiUrl https://api.midominio.com.ar
   ```

3. Tarda unos minutos. Al terminar dice **LISTO** y deja en `deploy\dist\`:

   | Archivo | Para qué sirve |
   |---|---|
   | `endetalle-backend.zip` | La API (se sube a `endetalle/backend`) |
   | `endetalle-frontend.zip` | El sitio (se sube a `public_html`) |
   | `endetalle-schema.sql` | La base de datos vacía (se importa con phpMyAdmin) |
   | `.env.production.example` | La plantilla de configuración del servidor |

4. **Anotá la clave `APP_KEY`** que imprime al final (empieza con `base64:`). Se usa en el paso 7 y **no se cambia nunca más**.
5. Si el script se detiene con un **ERROR**, leé el mensaje: dice qué falta (por ejemplo, que una prueba automática falló).

> El script **no toca** tu entorno de desarrollo: trabaja sobre una copia en `deploy\build`.

---

## 2. Crear el sitio y la API en cPanel (carpetas públicas)

La **carpeta pública** es lo único que se ve desde internet. Para la API tiene que ser `endetalle/backend/public`:
así `.env`, `storage` y `vendor` quedan **afuera** de lo público, donde nadie puede descargarlos.

1. En cPanel abrí **Administrador de archivos** → carpeta `/home/USUARIO` → botón **+ Carpeta** → creá `endetalle`.
   Dentro de `endetalle`, creá `backend`. Y en `/home/USUARIO` creá también una carpeta `logs` (para los registros de las tareas).
2. **Sitio:** si el dominio principal de la cuenta es el del cliente, su carpeta pública ya es `public_html`: no hay que hacer nada.
   Si es un dominio adicional: cPanel → **Dominios** → **Crear un nuevo dominio** → *Raíz del documento* `public_html` (o la carpeta que sugiera).
3. **API:** cPanel → **Dominios** → **Crear un nuevo dominio** → escribí `api.midominio.com.ar`.
   En **Raíz del documento** escribí **`endetalle/backend/public`** (sin barra al principio) y **destildá** "Compartir raíz de documento".
   → **Enviar**.
4. Si cPanel pide el DNS: el dominio tiene que apuntar a este hosting (los servidores de nombres que indica WNPower).
   Si el dominio está en otro proveedor, hay que crear un registro **A** `api` con la misma IP que el dominio principal.

---

## 3. Elegir PHP 8.3 para ambos dominios

1. cPanel → **MultiPHP Manager**.
2. Tildá `midominio.com.ar`, `www.midominio.com.ar` y `api.midominio.com.ar`. Arriba a la derecha, **Versión de PHP** → **`ea-php83`** → **Aplicar**.
3. cPanel → **MultiPHP INI Editor** (o *Seleccionar versión de PHP* → *Extensiones*): comprobá que estén **activadas** estas extensiones
   (casi siempre ya lo están): `pdo_mysql`, `mysqlnd`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `dom`, `ctype`, `json`, `iconv`, `session`.
4. En el mismo lugar, **Modo básico**, mirá `upload_max_filesize` y `post_max_size`: por defecto **16 MB**. Alcanza para los archivos de unos 3 MB.
   Si algún día hay que subir archivos más grandes, se suben los dos valores (por ejemplo a `64M`) y se cambia `DETAILS_MAX_MB` en el `.env` (paso 7)
   a un número un poco menor.

---

## 4. Crear la base de datos

1. cPanel → **Bases de datos MySQL**.
2. **Crear nueva base de datos**: nombre `endetalle` → *Crear*. cPanel le agrega un prefijo: queda `USUARIO_endetalle`. Anotalo.
3. **Agregar nuevo usuario**: nombre `endetalle`, contraseña larga (usá el botón **Generador de contraseñas** y guardala) → *Crear usuario*. Queda `USUARIO_endetalle`.
4. **Agregar usuario a la base de datos**: elegí el usuario y la base → *Agregar* → tildá **TODOS LOS PRIVILEGIOS** → *Realizar cambios*.

---

## 5. Subir la API (backend)

1. **Administrador de archivos** → entrá a `endetalle/backend`. Arriba a la derecha, **Configuración** → tildá **Mostrar archivos ocultos** → *Guardar*
   (así vas a ver archivos que empiezan con punto, como `.env` y `.htaccess`).
2. Botón **Cargar** → elegí `endetalle-backend.zip` (de `deploy\dist`) y esperá a que llegue al 100 %.
   *(Si la subida se corta por el tamaño: usá FTP con FileZilla, con los datos de **Cuentas FTP** de cPanel.)*
3. Volvé al administrador de archivos, hacé clic derecho sobre el zip → **Extract** (Extraer) → que extraiga **en `/endetalle/backend`**.
4. Comprobá que dentro de `endetalle/backend` hay carpetas `app`, `bootstrap`, `config`, `public`, `storage`, `vendor` y el archivo `artisan`
   (**no** una carpeta extra adentro). Después **borrá el zip**.

---

## 6. Subir el sitio (frontend)

1. **Administrador de archivos** → entrá a `public_html` (o la carpeta pública del sitio). Si hay archivos de ejemplo del hosting, borralos.
2. **Cargar** → `endetalle-frontend.zip` → clic derecho → **Extract** en `public_html`.
3. Comprobá que en `public_html` están `index.html`, la carpeta `assets` y el archivo oculto `.htaccess`. **Borrá el zip.**

---

## 7. Crear el archivo de configuración `.env` en el servidor

El `.env` guarda las claves del sitio. **Nunca se sube a GitHub ni se manda por chat o mail.**

1. En `endetalle/backend`, clic derecho sobre **`.env.production.example`** → **Copy** → copiar a `/endetalle/backend/.env`.
   *(Si no está en el zip, subí el archivo `.env.production.example` de `deploy\dist`, y copialo con ese nombre.)*
2. Clic derecho sobre `.env` → **Edit** (Editar) y completá **todos** los valores. Los más importantes:

   | Variable | Qué poner |
   |---|---|
   | `APP_KEY` | La clave `base64:...` que imprimió el build (paso 1) |
   | `APP_URL` | `https://api.midominio.com.ar` |
   | `FRONTEND_URL` | `https://midominio.com.ar` |
   | `SANCTUM_STATEFUL_DOMAINS` | `midominio.com.ar` (sin https) |
   | `SESSION_DOMAIN` | `.midominio.com.ar` (con un punto adelante) |
   | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Los del paso 4 (con el prefijo `USUARIO_`) |
   | `MAIL_*` y `CONTACT_EMAIL` | La casilla del paso 11 |
   | `MERCADOPAGO_*`, `PAYPAL_*` | Las claves de **producción** (paso 13) |
   | `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | El administrador (contraseña de **12 caracteres o más**) |

3. **No cambies** `APP_ENV=production` ni `APP_DEBUG=false`.
4. *Guardar cambios*. Clic derecho sobre `.env` → **Change Permissions** → `600` (o `640`).

> ⚠️ Los dos dominios tienen que ser del **mismo dominio principal** (`midominio.com.ar` y `api.midominio.com.ar`).
> Si fueran dominios totalmente distintos, los navegadores bloquean la sesión y nadie podría iniciar sesión.

---

## 8. Importar la base de datos (phpMyAdmin)

1. cPanel → **phpMyAdmin**.
2. En la columna izquierda, clic en la base **`USUARIO_endetalle`** (tiene que estar vacía).
3. Pestaña **Importar** → **Seleccionar archivo** → `endetalle-schema.sql` (de `deploy\dist`) → *Importar* (abajo).
4. Tiene que mostrar un mensaje verde y en la izquierda aparecen unas 36 tablas (`users`, `plans`, `orders`, ...).

> El archivo **borra y vuelve a crear** las tablas: usalo **solo en una base vacía**. Nunca lo importes sobre un sitio que ya tiene usuarios.

### 8.b Método de respaldo: crear las tablas con una tarea cron (si phpMyAdmin falla)

Si por algún motivo no se puede importar el SQL, las tablas se crean ejecutando las migraciones. **Como no hay terminal**, se hace con una tarea cron de una sola vez:

1. cPanel → **Trabajos de Cron** → *Agregar nuevo trabajo de Cron*. En **Configuración común** elegí **Una vez por minuto (`* * * * *`)**.
2. En **Comando** pegá (cambiá `USUARIO`):

   ```
   /opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/endetalle/backend/artisan migrate --force >> /home/USUARIO/logs/endetalle-migrate.log 2>&1
   ```

3. *Agregar nuevo trabajo de Cron*. **Esperá 2 minutos** y abrí `logs/endetalle-migrate.log` en el administrador de archivos: tiene que decir `DONE` en cada migración.
4. **Borrá la tarea ahora mismo** (si no, se vuelve a ejecutar cada minuto): en *Trabajos de Cron actuales* → **Eliminar** en esa línea.

---

## 9. Cargar los datos iniciales (administrador, planes, categorías, textos)

El importador del paso 8 solo crea las tablas vacías. Falta crear al **administrador** y cargar los **planes, categorías y ajustes**.
Se hace con otra tarea cron de una sola vez (igual que en 8.b):

1. **Trabajos de Cron** → *Una vez por minuto* → comando:

   ```
   /opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/endetalle/backend/artisan db:seed --class=ProductionSeeder --force >> /home/USUARIO/logs/endetalle-seed.log 2>&1
   ```

2. Esperá 2 minutos, abrí `logs/endetalle-seed.log`. Tiene que decir **`Administrador creado: el-email-del-admin`**.
   - Si dice que falta `ADMIN_EMAIL` / `ADMIN_PASSWORD`, o que la contraseña es corta, corregí el `.env` (paso 7) y esperá otro minuto.
3. **Borrá la tarea cron.** Es seguro que se ejecute más de una vez: no duplica nada.
4. Ahora que el administrador existe, podés **borrar del `.env` las tres líneas `ADMIN_...`** (la contraseña queda guardada cifrada en la base).
5. **Importante:** los **precios de los planes** que se cargan son de **ejemplo**. Entrá al panel (paso 15) → **Planes y Precios** y poné los reales **antes de abrir el sitio**.
   También revisá **Configuración** (textos, redes, términos y privacidad).

---

## 10. Acelerar el sitio (opcional, al final)

Cuando todo funcione, se puede guardar la configuración ya "procesada" para que cada visita sea más rápida. Es otra tarea cron de una sola vez:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/endetalle/backend/artisan config:cache >> /home/USUARIO/logs/endetalle-optimize.log 2>&1
```

> ⚠️ **Después de hacer esto, los cambios que hagas en el `.env` no se ven** hasta que corras una tarea de una sola vez con
> `artisan config:clear` (o borres el archivo `endetalle/backend/bootstrap/cache/config.php` desde el administrador de archivos).
> Si tocás el `.env` y "no pasa nada", es por esto. Las rutas ya vienen guardadas desde el build.
> Las vistas se compilan solas la primera vez (la carpeta `storage/framework/views` tiene que poder escribirse).

---

## 11. Crear la casilla de correo y configurar SPF y DKIM

Con esto los mails del sitio (verificar email, recuperar contraseña, comprobantes, avisos de vencimiento, reembolsos, formulario de contacto) **no caen en spam**.

1. cPanel → **Cuentas de correo** → *Crear*. Dominio `midominio.com.ar`, usuario `no-responder`, contraseña larga → *Crear*.
2. En esa cuenta, **Conectar dispositivos** (o *Configuración manual*): copiá el **servidor saliente (SMTP)** y el **puerto**
   (normalmente `mail.midominio.com.ar` y `465` con SSL/TLS). Es lo que va en `MAIL_HOST` y `MAIL_PORT` del `.env`.
3. cPanel → **Email Deliverability** → al lado del dominio, **Administrar**.
   - Si en **SPF** y en **DKIM** aparece "problema", clic en **Instalar el registro sugerido** (o *Reparar*) en cada uno. Tienen que quedar en verde.
   - Si el dominio usa DNS de **otro proveedor** (no el del hosting), cPanel muestra los registros TXT: hay que copiarlos en el panel de DNS de ese proveedor.
4. Esperá hasta una hora a que el DNS se actualice. Probalo en el paso 16 (registrándote con un email real).

---

## 12. Activar el SSL (candado verde)

1. cPanel → **SSL/TLS Status** → tildá `midominio.com.ar`, `www.midominio.com.ar` y `api.midominio.com.ar` → **Run AutoSSL**.
2. Esperá unos minutos y comprobá que los tres muestran el candado. Si falla, casi siempre es que el dominio todavía no apunta al hosting (paso 2).
3. Abrí `http://midominio.com.ar` y `http://api.midominio.com.ar`: tienen que **redirigir solas a `https://`**.
   El sitio y la API **exigen** HTTPS (las sesiones y los pagos no funcionan sin él).

---

## 13. Pagos: claves de producción y webhooks

Un **webhook** es un aviso que Mercado Pago o PayPal le mandan a la API cuando un pago se acredita. Sin esto, el plan igual se activa cuando la persona vuelve al sitio, pero los reembolsos y contracargos no se enterarían.

### Mercado Pago (pagos en pesos)
1. Entrá a `mercadopago.com.ar/developers` → **Tus integraciones** → la aplicación del sitio.
2. **Credenciales de producción** (la cuenta tiene que estar **activada** para cobrar): copiá el **Access Token** (`APP_USR-...`) a `MERCADOPAGO_ACCESS_TOKEN` en el `.env`.
3. **Webhooks** → *Configurar notificaciones* → **Modo producción** → URL:
   **`https://api.midominio.com.ar/api/webhooks/mercadopago`** → evento **Pagos** → *Guardar*.
4. Copiá la **Clave secreta** que muestra a `MERCADOPAGO_WEBHOOK_SECRET`.

### PayPal (pagos en dólares)
1. `developer.paypal.com` → **Apps & Credentials** → arriba, cambiá a **Live** → la app del sitio.
2. Copiá **Client ID** a `PAYPAL_CLIENT_ID` y **Secret** a `PAYPAL_SECRET`. Dejá `PAYPAL_MODE=live`.
3. En la misma app, **Webhooks** → **Add Webhook** → URL **`https://api.midominio.com.ar/api/webhooks/paypal`** → eventos:
   **Payment capture completed**, **Payment capture refunded** y **Payment capture reversed** → *Save*.
4. Copiá el **Webhook ID** a `PAYPAL_WEBHOOK_ID`.

> Si `PAYPAL_WEBHOOK_ID` queda vacío, la API **rechaza** todos los avisos de PayPal (por seguridad).
> Cada vez que cambies el `.env`, acordate del aviso del paso 10 si activaste la caché de configuración.

---

## 14. Tarea diaria: vencer planes y avisar

Una vez por día, a las 3:00, hay que correr `subscriptions:maintain`: da de baja los planes vencidos, activa los cambios de plan encolados,
manda el aviso "tu plan vence en 3 días" y cierra las compras que nadie pagó. **Se ejecuta directamente** (no usa `schedule:run`).

1. cPanel → **Trabajos de Cron** → *Agregar nuevo trabajo de Cron*.
2. Completá los campos así: **Minuto** `0` · **Hora** `3` · **Día** `*` · **Mes** `*` · **Día de la semana** `*`
3. **Comando** (cambiá `USUARIO`):

   ```
   /opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/endetalle/backend/artisan subscriptions:maintain >> /home/USUARIO/logs/endetalle-maintain.log 2>&1
   ```

4. Para comprobar que anda sin esperar a la madrugada: creá **temporalmente** la misma tarea con *Una vez por minuto*, esperá 2 minutos y abrí
   `logs/endetalle-maintain.log`: tiene que decir `Planes vencidos: 0. Planes encolados activados: 0. Avisos enviados: 0. ...`. Después **borrá la temporal** y dejá la de las 3:00.
5. De vez en cuando, mirá el log: si se llena de errores, algo no está bien (por ejemplo el correo). Se puede vaciar borrando el archivo.

---

## 15. Comprobar que la API responde

1. Abrí en el navegador **`https://api.midominio.com.ar/api/health`**. Tiene que mostrar algo así:
   ```json
   {"status":"ok","database":true,"storage":true,"time":"2026-..."}
   ```
2. Si dice `"database":false` → revisá `DB_*` en el `.env` (paso 4 y 7). Si dice `"storage":false` → clic derecho en `endetalle/backend/storage` →
   *Change Permissions* → `755` (marcando "Recurse into subdirectories").
3. Si aparece una página de error, mirá el último archivo de `endetalle/backend/storage/logs/` (dice qué falló).
4. Abrí `https://midominio.com.ar`: tiene que cargar el sitio. Entrá con el administrador (`/ingresar`) y abrí el **panel de administración**.

---

## 16. Lista de verificación final (antes de avisarle al cliente)

Marcá cada punto. **Si alguno falla, no se publica.**

**Infraestructura**
- [ ] `http://midominio.com.ar` y `http://api.midominio.com.ar` redirigen a `https://` y muestran el candado.
- [ ] `https://www.midominio.com.ar` redirige a `https://midominio.com.ar`.
- [ ] `https://api.midominio.com.ar/api/health` muestra `status: ok`, `database: true`, `storage: true`.
- [ ] `https://api.midominio.com.ar/.env`, `/vendor/autoload.php` y `/storage/logs/` dan **error 403 o 404** (nunca el contenido).
- [ ] Una dirección que no existe en la API (`/api/loquesea`) da un error corto **sin rutas del servidor ni detalles técnicos**.
- [ ] Recargar una página interna del sitio (por ejemplo `/planes`) **no** da error 404.

**Usuarios y correo**
- [ ] **Registro con email verificado:** registrarse con un email real → llega el mail de verificación (no a spam) → el enlace verifica la cuenta → se puede abrir un detalle.
- [ ] **Recuperar contraseña:** "¿Olvidaste tu contraseña?" → llega el mail → se puede elegir una contraseña nueva.
- [ ] **Formulario de contacto:** enviar un mensaje → llega a `CONTACT_EMAIL`.
- [ ] Iniciar sesión como **administrador** y abrir el panel; un usuario común que escribe `/admin` vuelve al inicio.

**Contenido y descargas**
- [ ] Panel → **Planes y Precios**: precios **reales** cargados. **Configuración**: textos, redes, términos y privacidad revisados.
- [ ] Subir un **detalle de prueba** con portada, láminas, un archivo **DWG** y uno **SKP** → aparece al aprobarlo/publicarlo.
- [ ] **Descarga:** con plan Free no deja descargar; con Estudiante baja el DWG; con Pro baja DWG y SKP. Las imágenes y avatares se ven bien.

**Pagos y reembolsos**
- [ ] **Compra de prueba** con Mercado Pago (pesos) y con PayPal (dólares): al pagar vuelve al sitio con "¡Pago acreditado!", el plan aparece activo en *Mi cuenta* y llega el mail del comprobante.
      *(Es plata real: usar el plan más barato y después devolverla desde el panel de cada proveedor.)*
- [ ] En el panel → **Pagos y órdenes** figura la compra como *Pagada*; en los paneles de Mercado Pago y PayPal figura la notificación del webhook como **entregada/exitosa**.
- [ ] **Reembolso:** *Mi suscripción* → **Solicitar reembolso** → llega el mail con el código `ARR-...` → en el panel → **Reembolsos** aprobar adjuntando el comprobante → el usuario vuelve a Free y recibe el mail.
- [ ] **Tareas programadas:** el log `logs/endetalle-maintain.log` tiene la salida de la última corrida (paso 14).

---

## 17. Actualizar el sitio más adelante

1. En la PC, volvé a correr `deploy\build.ps1` (paso 1): genera zips nuevos.
2. Subí `endetalle-backend.zip` a `endetalle/backend` y **extraelo eligiendo "sobrescribir"**. Esto **no pisa** el `.env` ni los archivos subidos
   (imágenes y archivos DWG/SKP): el zip no los trae.
3. Subí `endetalle-frontend.zip` a `public_html` y extraelo sobrescribiendo.
4. Si la versión nueva trae cambios en la base de datos (migraciones), corré la tarea de una sola vez del paso **8.b** (`migrate --force`) y borrala.
5. Si activaste la caché de configuración (paso 10), borrá `bootstrap/cache/config.php` o corré `config:clear` y volvé a cachear.

## 18. Copias de seguridad

- **Base de datos:** phpMyAdmin → la base → **Exportar** → *Rápido* → *Continuar*. Guardá el `.sql`. Hacelo antes de cada actualización y una vez por semana.
- **Archivos:** descargá (o comprimí con el administrador de archivos) las carpetas `endetalle/backend/storage/app/private` (archivos DWG/SKP)
  y `endetalle/backend/public/storage` (imágenes). Son lo único que no se puede regenerar.
- cPanel → **Copia de seguridad** también permite bajar una copia completa de la cuenta.

## 19. Problemas frecuentes

| Qué pasa | Qué mirar |
|---|---|
| Pantalla blanca o error 500 | `endetalle/backend/storage/logs/laravel-AAAA-MM-DD.log` (la última línea dice qué falló). Casi siempre es una variable del `.env` o permisos de `storage`. |
| "No application encryption key" | Falta `APP_KEY` en el `.env`. |
| El sitio carga pero no se puede iniciar sesión | `SESSION_DOMAIN` (con punto adelante), `SANCTUM_STATEFUL_DOMAINS` y `FRONTEND_URL` tienen que coincidir con el dominio real y con **https**. |
| Error de "CORS" en el navegador | `FRONTEND_URL` tiene que ser **exactamente** la dirección del sitio (sin barra final, con `https://`, sin www si se eligió sin www). |
| No llegan los mails | `MAIL_*` del `.env`; SPF y DKIM en verde (paso 11); mirá el log de Laravel. |
| Mercado Pago/PayPal no acreditan | Webhooks mal cargados (paso 13), claves de **producción**, `APP_URL` correcto con https. En Mercado Pago el plan igual se acredita al abrir *Mi cuenta*. |
| Subir un DWG/SKP falla | Pesa más de `DETAILS_MAX_MB` o del límite del hosting (paso 3). |
| Las imágenes no se ven | Permisos de `public/storage` (`755`) y que `APP_URL` sea la dirección de la API. |
| Cambié el `.env` y no pasa nada | Si activaste la caché de configuración: paso 10. |

---

## Datos que hay que pedirle al cliente

| Dato | Para qué sirve |
|---|---|
| **Dominio** definitivo (y quién lo administra / dónde está el DNS) | Dirección del sitio y de la API (`midominio.com.ar` / `api.midominio.com.ar`); decidir si se usa con o sin `www` |
| **Acceso a cPanel** (usuario y contraseña, o que cree un acceso para nosotros) | Crear dominios, base de datos, correo, SSL y tareas cron |
| **Email y nombre del administrador** + una **contraseña larga** (12 caracteres o más) | Crea al usuario administrador del sitio |
| **Casilla de correo remitente** (por ejemplo `no-responder@midominio.com.ar`) y **casilla donde quiere recibir** los mensajes de contacto y avisos de reembolso | Envío de mails del sitio; destino del formulario de contacto |
| **Mercado Pago:** cuenta **activada para cobrar**, **Access Token de producción** y **Clave secreta del webhook** | Cobros en pesos |
| **PayPal:** cuenta Business verificada, **Client ID**, **Secret** (modo **Live**) y el **Webhook ID** | Cobros en dólares |
| **Precios reales** de los planes Estudiante y Pro, en pesos y en dólares | El sitio carga precios de ejemplo |
| **Textos legales:** Términos y condiciones y Política de privacidad definitivos | Se cargan en el panel → Configuración |
| **Datos de contacto:** dirección, horario, redes sociales | Sección Contacto y pie de página |
| **Logo / imagen de cabecera** definitivos (si cambian) | Aspecto del sitio |
| **Detalles reales** para cargar (planos DWG, modelos SKP, imágenes) y a nombre de qué cuenta se suben | Contenido inicial del sitio |
| Confirmación del **plazo y condiciones de reembolso** (hoy: 15 días, 100 %) | Política de reembolsos del sitio |
