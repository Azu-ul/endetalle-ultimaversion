# enDetalle — backend (Laravel)

API del sitio: usuarios, detalles constructivos, planes y pagos (Mercado Pago y PayPal), reembolsos y panel de administración.
El frontend (React) está en `../frontend`.

## Puesta en marcha local

```bash
composer install
cp .env.example .env          # y completar la base de datos (MySQL) y las claves que hagan falta
php artisan key:generate
php artisan migrate --seed    # roles, planes, categorías, ajustes y cuentas de prueba
php artisan serve             # http://localhost:8000  (el frontend: npm run dev en ../frontend, http://localhost:5173)
```

Usá siempre `localhost`, no `127.0.0.1` (las cookies de sesión son por host).

**Pruebas automáticas** (usan SQLite en memoria; no tocan tu base):

```bash
php vendor/bin/phpunit
```

## Pagos (Mercado Pago y PayPal)

- Variables en `.env.example`: `MERCADOPAGO_ACCESS_TOKEN`, `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_MODE` (`sandbox` o `live`),
  y en producción los secretos de los webhooks (`MERCADOPAGO_WEBHOOK_SECRET`, `PAYPAL_WEBHOOK_ID`).
- Pesos con Mercado Pago, dólares con PayPal. Los planes se pagan por período (1, 3 o 12 meses) y no se renuevan solos.
- Reglas de compra (todas se validan en el servidor, ver `app/Services/Payments/PlanPurchase.php`):
  - **Mismo plan:** solo se renueva con 3 días o menos para el vencimiento (o ya vencido); suma el período al vencimiento vigente.
  - **Subir de plan** (Estudiante → Pro): en cualquier momento; se paga la diferencia de precio del período vigente y el plan nuevo conserva el vencimiento.
  - **Bajar de plan** (Pro → Estudiante): con 3 días o menos para el vencimiento se paga completo y queda encolado: empieza cuando vence el actual.
  - **Cancelar:** no quita días ya pagados; el plan sigue hasta su vencimiento y no se renueva.
- Tarea diaria (cron): `php artisan subscriptions:maintain` vence planes, activa los encolados, avisa 3 días antes del vencimiento y cierra
  órdenes sin pagar. En el hosting: un cron de cada minuto con `php artisan schedule:run`.
- En local Mercado Pago no manda webhooks ni vuelve al sitio (descarta las `back_urls` con `localhost`): el plan se acredita al abrir
  **Mi cuenta** (o con "Ya pagué, verificar").

## Reembolsos

Reglas en `config/billing.php` (plazo `REFUND_WINDOW_DAYS`, 15 por defecto, y `REFUND_PERCENT`, 100): se reembolsa si la solicitud se hace
dentro de los 15 días corridos desde la fecha de pago **de esa orden**; pasado el plazo no se reembolsa.
Cada compra admite **una sola** solicitud: si fue rechazada no se puede volver a pedir (el formulario la muestra deshabilitada con el motivo
y el usuario consulta desde Contacto); si fue aprobada se muestra como "Reembolsada".

1. El usuario pide el reembolso desde **Mi Suscripción → Solicitar reembolso** o desde el enlace **Botón de arrepentimiento** del footer.
   Recibe por email el código (`ARR-000123`). La solicitud no corta el plan.
2. El administrador la ve en **Reembolsos** (panel admin), devuelve el dinero **a mano** desde Mercado Pago o PayPal (no se usan sus APIs de
   reembolso) y aprueba adjuntando el comprobante (PDF o imagen, hasta 5 MB), o rechaza con un motivo. Queda registrado quién y cuándo.
3. Al aprobar: la orden pasa a reembolsada, el usuario vuelve a Free (se cancelan sus compras pendientes y planes encolados) y recibe el
   comprobante por email. Un reembolso o contracargo que avisa el proveedor por webhook sigue quitando los meses de esa orden.

## Comandos de desarrollo (solo local)

Sirven para probar casos que dependen del tiempo y de PayPal sin esperar días. **Solo existen con `APP_ENV=local`**: no se registran en
otros entornos y se niegan a correr si el entorno no es local. Cada uno imprime un resumen (plan, vencimiento, órdenes) sin mostrar claves.

| Comando | Qué hace |
|---|---|
| `php artisan dev:subscription {email} {plan} [--period=monthly] [--provider=mercadopago] [--days-left=30]` | Deja al usuario con ese plan pago (`estudiante` o `pro`) activo como si hubiera pagado de verdad: crea la orden pagada (pesos con `mercadopago`, dólares con `paypal`, monto de la tabla de planes, id de pago de prueba `DEV-…`) y la suscripción con vencimiento = hoy + días restantes y fecha de pago coherente con el período. Cierra cualquier otro plan activo o encolado y las compras sin pagar. `--period`: `monthly`, `quarterly` o `yearly`. |
| `php artisan dev:reset {email}` | Deja al usuario en Free: borra sus órdenes, pagos, solicitudes de reembolso (con sus comprobantes) y todos sus planes, también los encolados. |
| `php artisan dev:pay-date {order_id} --days-ago=N` | Mueve la fecha de pago de una orden N días atrás para probar el plazo de reembolso de 15 días. Dice si queda dentro o fuera de plazo. |

Recetas (reemplazá el email):

```bash
# Subir a Pro pagando en dólares con PayPal sandbox, partiendo de un Estudiante pagado con PayPal
php artisan dev:reset tu@mail.com
php artisan dev:subscription tu@mail.com estudiante --provider=paypal --days-left=10
#   → en Planes aparece "Subir a Pro · US$ 20" (29 − 9); pagá con una cuenta compradora de PayPal sandbox

# Renovar con 3 días o menos (con 4 o más queda bloqueado)
php artisan dev:subscription tu@mail.com estudiante --provider=paypal --days-left=3

# Bajar de plan con el plan por vencer (con más de 3 días queda bloqueado)
php artisan dev:subscription tu@mail.com pro --provider=paypal --days-left=2
#   → en Planes: "Cambiar a Estudiante"; al pagar queda encolado ("Pasás a Estudiante el …")

# Reembolsos dentro y fuera de plazo
php artisan dev:subscription tu@mail.com estudiante            # imprime el número de orden
php artisan dev:pay-date 12 --days-ago=15                      # dentro de plazo (día 15)
php artisan dev:pay-date 12 --days-ago=16                      # fuera de plazo
```

## Archivos de los detalles (DWG y SKP)

- Se guardan siempre en un disco **privado** (`storage/app/private/detail-files/{id}`), nunca en `public`. El disco sale de la variable
  `DETAILS_DISK` (por defecto `local`) y todo el código lo lee con `DetailFile::disk()` (`Storage::disk(config('filesystems.details_disk'))`),
  así que pasar a un almacenamiento S3 más adelante es definir ese disco en `config/filesystems.php` y cambiar `DETAILS_DISK`
  (y copiar los archivos existentes al disco nuevo).
- Se descargan por `GET /api/details/{id}/files/{dwg|skp}`: exige sesión, email verificado, plan activo que incluya ese tipo de archivo
  (DWG desde Estudiante, SKP solo Pro) y cupo disponible. Se entrega en streaming y con el nombre original: la ruta real nunca se expone.
- **Sin `storage:link` ni symlinks** (el hosting los bloquea): el disco `public` (portadas, láminas, avatares) escribe directo en
  `public/storage`.
- **Validación de la subida:** extensión `.dwg` / `.skp`, tamaño máximo `DETAILS_MAX_MB` (15 por defecto) y los primeros bytes del archivo
  (un `.exe` renombrado a `.dwg` se rechaza).
- **Límite del hosting:** PHP limita las subidas a **16 MB por defecto** (`upload_max_filesize` y `post_max_size`). Los archivos de unos 3 MB
  entran sin problema. Para subir archivos más grandes hay que aumentar el límite del hosting **y** `DETAILS_MAX_MB`:
  - cPanel: **MultiPHP INI Editor** (elegir el dominio de la API) → subir `upload_max_filesize` y `post_max_size` (por ejemplo a `64M`), o
  - en un archivo `.user.ini` dentro de `backend/public/` con `upload_max_filesize = 64M` y `post_max_size = 64M`
    (en hostings con PHP como FastCGI no hace falta reiniciar nada: tarda unos minutos en aplicarse).
  - Si una subida pasa el límite del hosting, PHP la corta antes de llegar a la aplicación y el formulario avisa que el archivo es muy
    grande.
- Si ya había imágenes subidas en `storage/app/public`, al dejar de usar `storage:link` hay que copiarlas a `public/storage`.

## Publicar en producción (hosting compartido cPanel)

La guía paso a paso, para alguien no técnico, está en [`../deploy/DEPLOY.md`](../deploy/DEPLOY.md). En resumen:

- `deploy/build.ps1 -ApiUrl https://api.midominio.com.ar` (en Windows) corre las pruebas, instala las dependencias de PHP sin las de desarrollo
  (PHP 8.3), cachea las rutas, compila el frontend apuntando a la API de producción y deja en `deploy/dist` los zips del backend y del frontend,
  el SQL de la base y la plantilla del `.env`. No toca tu `vendor` ni tu `.env`.
- Sitio y API van en dos direcciones del mismo dominio (`midominio.com.ar` y `api.midominio.com.ar`); la carpeta pública de la API es `backend/public`,
  así `.env`, `storage` y `vendor` quedan fuera de lo público.
- Configuración de producción: `.env.production.example` (todas las variables, comentadas). Con `APP_DEBUG=false` los errores no muestran trazas
  ni rutas del servidor.
- Base de datos: `deploy/endetalle-schema.sql` (se importa con phpMyAdmin; se regenera con `deploy/regenerar-sql.ps1` si hay migraciones nuevas).
  Datos iniciales: `php artisan db:seed --class=ProductionSeeder --force` (roles, administrador desde `ADMIN_*`, planes, categorías y ajustes; sin datos de
  demostración). En el hosting no hay terminal: se corre con una tarea cron de una sola vez (ver la guía).
- Tarea diaria a las 3:00: `php artisan subscriptions:maintain` (se ejecuta directo, sin `schedule:run`).
- `GET /api/health` responde si el backend, la base y `storage` están bien (solo sí/no, sin datos sensibles).

## Correo (SMTP)

En desarrollo `MAIL_MAILER=log` deja los emails en `storage/logs/laravel.log`. Para producción (casilla de correo del hosting) ver `.env.production.example`.
Los mensajes del formulario de contacto y los avisos de reembolso llegan a `CONTACT_EMAIL`.
