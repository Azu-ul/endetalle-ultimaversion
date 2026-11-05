# Cómo publicar enDetalle en Hostinger (hPanel, plan Business)

Es la versión para **Hostinger** de [DEPLOY.md](DEPLOY.md) (que está escrita para cPanel). La idea es la misma: todo se prepara en la PC y se sube;
los pasos que cambian son los de **dónde se hace clic** en hPanel. Los pasos que no cambian (pagos, lista de verificación, actualizaciones,
copias de seguridad) se leen en DEPLOY.md y están indicados abajo.

Hostinger Business tiene **PHP 8.3, MySQL, phpMyAdmin, SSL gratis, correo, cron y SSH**. Con SSH nos ahorramos los "cron de una sola vez" de la guía
de cPanel: los comandos de `artisan` se corren directo.

> **Palabras que se repiten**
> - **Sitio** (frontend): `https://midominio.com`. **API** (backend): `https://api.midominio.com`. Tienen que ser del **mismo dominio principal**.
> - **uXXXXXXXXX**: tu usuario de Hostinger (algo como `u123456789`). Se ve en hPanel → **Avanzado → Acceso SSH** (o en la ruta del Administrador de archivos).
> - Los nombres de los menús de hPanel cambian de vez en cuando: si alguno no coincide, buscá el parecido o usá el buscador de hPanel.

---

## 0. Antes de empezar

- [ ] El dominio **ya apunta a Hostinger** (se ve en hPanel → **Dominios**; si lo compraste ahí, ya está).
- [ ] La clave de **producción** de Mercado Pago y PayPal (se pueden cargar después; el sitio anda sin cobrar).
- [ ] El email y una contraseña larga (12 caracteres o más) para el administrador.

---

## 1. Preparar los archivos en tu PC

Igual que en DEPLOY.md, paso 1. En la terminal de **Laragon**, dentro del proyecto:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\build.ps1 -ApiUrl https://api.midominio.com
```

Deja en `deploy\dist\`: `endetalle-backend.zip`, `endetalle-frontend.zip`, `endetalle-schema.sql` y `.env.production.example`.
**Anotá el `APP_KEY`** (empieza con `base64:`) que imprime al final. No se cambia nunca más.

---

## 2. Dónde van las carpetas en Hostinger

Hostinger guarda cada dominio en `/home/uXXXXXXXXX/domains/midominio.com/public_html`. Para enDetalle:

| Qué | Carpeta |
|---|---|
| **Sitio** (lo que ve la gente) | `/home/uXXXXXXXXX/domains/midominio.com/public_html` |
| **API, archivos de programa** (`.env`, `vendor`, `storage`, ...) | `/home/uXXXXXXXXX/endetalle/backend` (**afuera** de `domains`, para que nadie pueda descargarlos) |
| **API, parte pública** | `/home/uXXXXXXXXX/endetalle/backend/public` (es la carpeta que se le da al subdominio `api`) |

En **Administrador de archivos** (hPanel → **Archivos → Administrador de archivos**) creá, en `/home/uXXXXXXXXX`, las carpetas `endetalle` y adentro `backend`.

---

## 3. Crear el subdominio `api` apuntando a `endetalle/backend/public`

1. hPanel → **Dominios → Subdominios** → crear `api` (quedará `api.midominio.com`).
2. Al crearlo, elegí **carpeta personalizada** y escribí `/home/uXXXXXXXXX/endetalle/backend/public`
   (si solo te deja una carpeta dentro de `domains`, dejá la que propone y seguí con el plan B de abajo).
3. **Plan B (con SSH, paso 3.b):** si no pudo apuntar a esa carpeta, se reemplaza la carpeta del subdominio por un acceso directo (symlink) a `backend/public`.

### 3.b Activar SSH (sirve para varias cosas más adelante)

1. hPanel → **Avanzado → Acceso SSH** → **Habilitar** y poné una contraseña. Ahí mismo figuran **IP, puerto y usuario**.
2. Desde la terminal de tu PC (PowerShell):

   ```powershell
   ssh -p PUERTO uXXXXXXXXX@IP
   ```

   Pide la contraseña y entra. (Los datos de PUERTO e IP los muestra hPanel; en Hostinger el puerto no es el 22.)

### Plan B del paso 3, ya conectada por SSH

Ejecutá esto (cambiá `uXXXXXXXXX` y `midominio.com`). Primero **se mira** qué hay en la carpeta del subdominio y recién ahí se borra:

```bash
ls -la ~/domains/midominio.com/public_html/api   # o la carpeta que creó Hostinger para el subdominio
```

Si solo tiene archivos de ejemplo (un `index.php`, `default.php`), se reemplaza por el acceso directo:

```bash
rm -r ~/domains/midominio.com/public_html/api
ln -s ~/endetalle/backend/public ~/domains/midominio.com/public_html/api
```

> Hacelo **después** del paso 6 (cuando `backend/public` ya exista).

---

## 4. Elegir PHP 8.3

1. hPanel → **Avanzado → Configuración de PHP** (o *PHP Configuration*) → pestaña **Versión de PHP** → **8.3** → Guardar.
2. Pestaña **Extensiones PHP**: comprobá que estén activas `pdo_mysql`, `mysqlnd`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `dom`, `ctype`, `json`, `iconv`.
3. Pestaña **Opciones de PHP**: `upload_max_filesize` y `post_max_size` en **32M** o más (los archivos DWG/SKP pueden pesar varios MB).
   Si cambiás esos valores, `DETAILS_MAX_MB` del `.env` tiene que quedar un poco por debajo.

> En Hostinger la versión de PHP se elige **por dominio**: si el subdominio `api` no la hereda, entrá a su configuración y elegí 8.3 también.

---

## 5. Crear la base de datos

1. hPanel → **Bases de datos → Administración** (*MySQL Databases*).
2. Completá **nombre de la base**, **usuario** y **contraseña larga** → **Crear**. Hostinger les agrega un prefijo: quedan como `uXXXXXXXXX_endetalle`.
3. **Anotá los tres datos** (base, usuario, contraseña). El servidor (`DB_HOST`) casi siempre es `localhost`.

---

## 6. Subir la API

**Opción A — Administrador de archivos (la más simple):**
1. Entrá a `/home/uXXXXXXXXX/endetalle/backend`. En la configuración del administrador activá **Mostrar archivos ocultos**.
2. **Subir** `endetalle-backend.zip` → clic derecho → **Extraer** en esa misma carpeta → borrá el zip.

**Opción B — por SSH** (si el zip es grande o la subida se corta). Desde otra terminal de tu PC, en la carpeta del proyecto:

```powershell
scp -P PUERTO deploy\dist\endetalle-backend.zip uXXXXXXXXX@IP:~/endetalle/backend/
```

Y ya conectada por SSH:

```bash
cd ~/endetalle/backend && unzip -o endetalle-backend.zip && rm endetalle-backend.zip
```

Comprobá que adentro de `endetalle/backend` hay `app`, `bootstrap`, `config`, `public`, `storage`, `vendor` y `artisan` (no una carpeta extra adentro).
**No hace falta Composer**: el `vendor` ya viene en el zip.

---

## 7. Subir el sitio

1. **Administrador de archivos** → `domains/midominio.com/public_html`. Si hay archivos de ejemplo de Hostinger (`default.php`, etc.), borralos.
2. Subí `endetalle-frontend.zip` → **Extraer** ahí → borrá el zip.
3. Tienen que quedar `index.html`, la carpeta `assets` y el archivo oculto `.htaccess`.

> Si en el paso 3 el subdominio `api` quedó como una carpeta **dentro** de `public_html` (plan B con acceso directo), no la borres al limpiar `public_html`.

---

## 8. Crear el `.env`

Mismo paso 7 de DEPLOY.md. En `endetalle/backend`, copiá `.env.production.example` a `.env` y completá los valores. Con Hostinger quedan así:

| Variable | Qué poner |
|---|---|
| `APP_KEY` | La `base64:...` del build |
| `APP_URL` | `https://api.midominio.com` |
| `FRONTEND_URL` | `https://midominio.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `midominio.com` (sin https) |
| `SESSION_DOMAIN` | `.midominio.com` (con punto adelante) |
| `DB_HOST` | `localhost` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Los del paso 5 (con prefijo `uXXXXXXXXX_`) |
| `MAIL_*`, `CONTACT_EMAIL` | La casilla del paso 11 |
| `MERCADOPAGO_*`, `PAYPAL_*` | Claves de producción (se pueden dejar para el final) |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | El administrador (contraseña de 12 caracteres o más) |

No cambies `APP_ENV=production` ni `APP_DEBUG=false`. Después, permisos del `.env` en `640` (clic derecho → **Permisos**).

---

## 9. Crear las tablas y los datos iniciales

Con SSH (ya conectada) se hace todo en tres comandos, sin phpMyAdmin ni cron de una sola vez:

```bash
cd ~/endetalle/backend
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
```

El segundo tiene que decir **`Administrador creado: el-email-del-admin`**. Es seguro correrlo más de una vez.

> Si `php -v` muestra una versión que no es 8.3, usá el PHP 8.3 explícito. En Hostinger suele estar en `/opt/alt/php83/usr/bin/php` (probá `ls /opt/alt/`):
> `/opt/alt/php83/usr/bin/php artisan migrate --force`.

**Plan B sin SSH:** phpMyAdmin (hPanel → **Bases de datos → phpMyAdmin → Entrar**) → elegí la base → **Importar** → `endetalle-schema.sql`.
Después hay que cargar los datos iniciales (`ProductionSeeder`), que necesita SSH o un cron de una sola vez como en DEPLOY.md, paso 9.

Cuando el administrador ya exista, borrá del `.env` las tres líneas `ADMIN_...`. Entrá al panel y poné los **precios reales** en *Planes y Precios* antes de abrir el sitio.

Acelerar (opcional, al final): `php artisan config:cache`. Después de eso los cambios del `.env` no se ven hasta correr `php artisan config:clear`.

---

## 10. Permisos de `storage`

Por SSH:

```bash
cd ~/endetalle/backend
chmod -R 755 storage bootstrap/cache
```

---

## 11. Correo (casilla, SPF y DKIM)

1. hPanel → **Correo electrónico → Cuentas de correo** → crear `no-responder@midominio.com` con contraseña larga.
2. Los datos SMTP de Hostinger suelen ser servidor **`smtp.hostinger.com`**, puerto **465** (SSL) o **587** (TLS); usuario = el email completo.
   Confirmalos en hPanel → **Correo → Configurar dispositivos / Detalles de configuración**. Eso va en `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` y `MAIL_FROM_ADDRESS`.
3. Si el DNS del dominio está en Hostinger, SPF y DKIM se agregan solos. Verificalo en hPanel → **Correo → DNS Records** (tiene que haber registros TXT de SPF y de DKIM).
   Si el DNS está en otro lado, hay que copiar esos registros ahí.
4. Probalo registrándote con un email real: el mail de verificación tiene que llegar (revisá spam la primera vez).

---

## 12. SSL

hPanel → **Seguridad → SSL** → instalar el SSL gratuito en `midominio.com` y en `api.midominio.com`.
Activá **Forzar HTTPS** en ambos. El sitio y la API **exigen** HTTPS.

---

## 13. Pagos

Igual que DEPLOY.md, paso 13 (webhooks de Mercado Pago y PayPal, con las direcciones `https://api.midominio.com/api/webhooks/mercadopago` y `.../paypal`).

---

## 14. Tarea diaria (cron)

hPanel → **Avanzado → Trabajos Cron** → **Personalizado** (*Custom*) → comando:

```
/opt/alt/php83/usr/bin/php /home/uXXXXXXXXX/endetalle/backend/artisan subscriptions:maintain
```

Programación: minuto `0`, hora `6`, el resto `*`. **Los crons de Hostinger van en hora UTC**: las 6:00 UTC son las 3:00 de Argentina.

Dos diferencias con la guía de cPanel:
- **No agregues** `>> archivo.log 2>&1`: Hostinger no lo interpreta como redirección. La salida se ve con el botón **Ver salida** del cron.
- Si `/opt/alt/php83/usr/bin/php` no existe, usá la ruta que te dé `which php` por SSH (con PHP 8.3 elegido en el paso 4).

Para probarlo: creá el cron una vez con "cada minuto", mirá la salida (tiene que decir `Planes vencidos: 0. ...`) y volvé a ponerlo diario.

---

## 15. Comprobar y publicar

1. `https://api.midominio.com/api/health` → tiene que mostrar `{"status":"ok","database":true,"storage":true,...}`.
2. `https://midominio.com` carga el sitio; entrá con el administrador en `/ingresar`.
3. **Lista de verificación final**: DEPLOY.md, paso 16 (no se publica si algún punto falla).

Actualizar, copias de seguridad y problemas frecuentes: DEPLOY.md, pasos 17, 18 y 19. Con SSH, el paso 4 de "actualizar" es simplemente `php artisan migrate --force`.

---

## Problemas propios de Hostinger

| Qué pasa | Qué mirar |
|---|---|
| `api.midominio.com` muestra la página de Hostinger o "403" | El subdominio no apunta a `endetalle/backend/public` (paso 3) o falta el `.htaccess` de esa carpeta. |
| Error 500 en la API pero el sitio anda | PHP del subdominio no está en 8.3 (paso 4) o permisos de `storage` (paso 10). Mirá `storage/logs/`. |
| El cron no hace nada | Ruta del PHP (paso 14) o la hora: es UTC. |
| `php artisan` dice otra versión de PHP | Usá la ruta completa `/opt/alt/php83/usr/bin/php`. |
| No puedo iniciar sesión en el sitio | `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` y `FRONTEND_URL` (con https, mismo dominio principal). |
