<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ErrorReportController as AdminErrorReportController;
use App\Http\Controllers\Admin\DetailController as AdminDetailController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CommentController as AdminCommentController;
use App\Http\Controllers\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\ExportController as AdminExportController;
use App\Http\Controllers\Admin\ImageController as AdminImageController;
use App\Http\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\Admin\TagController as AdminTagController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\DetailController;
use App\Http\Controllers\DetailFileController;
use App\Http\Controllers\ErrorReportController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\DetailMediaController;
use Illuminate\Support\Facades\Route;

// Estado del backend (para comprobar el deploy): solo sí/no, sin datos sensibles
Route::get('/health', HealthController::class)->middleware('throttle:60,1,health');

// Públicas
Route::get('/settings', [SiteController::class, 'settings']);
Route::get('/categories', [SiteController::class, 'categories']);
Route::get('/plans', [SiteController::class, 'plans']);
Route::get('/banners', [SiteController::class, 'banners']);
Route::get('/details', [DetailController::class, 'index']);
// Imágenes del detalle: se entregan solo a quien puede abrirlo (ver DetailMediaController)
Route::get('/details/{id}/media/{key}', [DetailMediaController::class, 'show'])->whereNumber('id')->where('key', 'cover|\d+')->middleware('throttle:600,1,media');
Route::get('/details/{slug}', [DetailController::class, 'show']);
// Visitas y "online" (visitantes con y sin cuenta)
Route::post('/track', TrackController::class)->middleware('throttle:180,1,track');
// Comunidad: directorio y perfiles públicos
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
// Comentarios: hace falta poder abrir el detalle (se controla en el controlador)
Route::get('/details/{id}/comments', [CommentController::class, 'index'])->whereNumber('id');

// Avisos de los medios de pago (sin sesión; cada uno se valida contra el proveedor)
Route::post('/webhooks/mercadopago', [PaymentWebhookController::class, 'mercadoPago'])->middleware('throttle:120,1,webhook-mp');
Route::post('/webhooks/paypal', [PaymentWebhookController::class, 'paypal'])->middleware('throttle:120,1,webhook-paypal');

// Autenticación
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1,login');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1,forgot');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1,reset');
Route::get('/reset-password/check', [PasswordResetController::class, 'check'])->middleware('throttle:20,1,reset-check');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1,contact');
// Enlace del email de verificación (firmado, sin sesión; la firma se controla en el controlador)
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->whereNumber('id')->middleware('throttle:20,1,verify')->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1,resend');

    // Mi cuenta
    Route::patch('/user/profile', [AccountController::class, 'updateProfile']);
    Route::post('/user/{kind}', [AccountController::class, 'uploadImage'])->whereIn('kind', ['avatar', 'cover'])->middleware('throttle:10,1,upload');
    Route::delete('/user/{kind}', [AccountController::class, 'deleteImage'])->whereIn('kind', ['avatar', 'cover']);
    Route::put('/user/password', [AccountController::class, 'changePassword'])->middleware('throttle:6,1,password');
    Route::get('/user/sessions', [AccountController::class, 'sessions']);
    Route::post('/user/sessions/logout-others', [AccountController::class, 'logoutOtherSessions'])->middleware('throttle:6,1,sessions');

    // Su plan: qué puede comprar y cancelar (no requieren email verificado)
    Route::get('/checkout/status', [CheckoutController::class, 'status']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('throttle:6,1,cancel');

    // Botón de arrepentimiento: sus compras y la solicitud de reembolso
    Route::get('/refunds', [RefundController::class, 'index']);
    Route::post('/refunds', [RefundController::class, 'store'])->middleware('throttle:5,1,refunds');

    // Avisos del administrador (campanita)
    Route::get('/notices', [NoticeController::class, 'index']);
    Route::post('/notices/read-all', [NoticeController::class, 'readAll']);
    Route::post('/notices/{id}/read', [NoticeController::class, 'read'])->whereNumber('id');
    Route::delete('/notices/{id}', [NoticeController::class, 'destroy'])->whereNumber('id');

    // Sus propios favoritos se pueden leer siempre
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::get('/favorites/details', [FavoriteController::class, 'details']);

    // Con el email verificado (ver EnsureEmailIsVerified)
    Route::middleware('verified')->group(function () {
        // Favoritos
        Route::post('/details/{id}/favorite', [FavoriteController::class, 'store'])->whereNumber('id');
        Route::delete('/details/{id}/favorite', [FavoriteController::class, 'destroy'])->whereNumber('id');

        // Compra de planes
        Route::post('/checkout', [CheckoutController::class, 'start'])->middleware('throttle:10,1,checkout');
        Route::post('/orders/sync', [CheckoutController::class, 'sync'])->middleware('throttle:6,1,orders-sync');
        Route::get('/orders/{uuid}', [CheckoutController::class, 'show'])->whereUuid('uuid');
        Route::post('/orders/{uuid}/capture', [CheckoutController::class, 'capture'])->whereUuid('uuid')->middleware('throttle:20,1,capture');

        // Descarga de archivos DWG / SKP (según el plan)
        Route::get('/details/{id}/files/{type}', [DetailFileController::class, 'download'])->whereNumber('id')->whereIn('type', ['dwg', 'skp'])->middleware('throttle:30,1,downloads');

        // Descarga de la ilustración (original en alta calidad, según el plan)
        Route::get('/details/{id}/media/{key}/download', [DetailMediaController::class, 'download'])->whereNumber('id')->where('key', 'cover|\d+')->middleware('throttle:30,1,image-downloads');

        // Me gusta / No me gusta
        Route::put('/details/{id}/reaction', [ReactionController::class, 'store'])->whereNumber('id')->middleware('throttle:60,1,reactions');
        Route::delete('/details/{id}/reaction', [ReactionController::class, 'destroy'])->whereNumber('id');

        // Comentarios y reportes de error
        Route::post('/details/{id}/comments', [CommentController::class, 'store'])->whereNumber('id')->middleware('throttle:10,1,comments');
        Route::put('/comments/{id}', [CommentController::class, 'update'])->whereNumber('id');
        Route::delete('/comments/{id}', [CommentController::class, 'destroy'])->whereNumber('id');
        Route::post('/details/{id}/reports', [ErrorReportController::class, 'store'])->whereNumber('id')->middleware('throttle:5,1,reports');

        // "Me gusta" entre usuarios
        Route::post('/users/{id}/like', [UserController::class, 'like'])->whereNumber('id')->middleware('throttle:30,1,likes');
        Route::delete('/users/{id}/like', [UserController::class, 'unlike'])->whereNumber('id');
    });
});

// Mensajes privados: solo colaboradores y administradores
Route::middleware(['auth:sanctum', 'role:colaborador|superadmin'])->prefix('messages')->group(function () {
    Route::get('/conversations', [MessageController::class, 'conversations']);
    Route::get('/contacts', [MessageController::class, 'contacts']);
    Route::get('/unread', [MessageController::class, 'unread']);
    Route::get('/with/{id}', [MessageController::class, 'thread'])->whereNumber('id');
    Route::post('/with/{id}', [MessageController::class, 'send'])->whereNumber('id')->middleware('throttle:40,1,messages');
    Route::delete('/with/{id}', [MessageController::class, 'destroy'])->whereNumber('id');
});

// Colaboradores: carga de detalles propios (el superadmin también puede)
Route::middleware(['auth:sanctum', 'role:colaborador|superadmin'])->prefix('studio')->group(function () {
    Route::get('/details', [StudioController::class, 'index']);
    Route::post('/details', [StudioController::class, 'store']);
    Route::get('/details/{id}', [StudioController::class, 'show'])->whereNumber('id');
    Route::put('/details/{id}', [StudioController::class, 'update'])->whereNumber('id');
    Route::delete('/details/{id}', [StudioController::class, 'destroy'])->whereNumber('id');
    Route::post('/details/{id}/submit', [StudioController::class, 'submit'])->whereNumber('id');
    Route::middleware('throttle:60,1,studio-uploads')->group(function () {
        Route::post('/details/{id}/cover', [StudioController::class, 'uploadCover'])->whereNumber('id');
        Route::post('/details/{id}/images', [StudioController::class, 'addImage'])->whereNumber('id');
        Route::post('/details/{id}/files/{type}', [StudioController::class, 'uploadFile'])->whereNumber('id')->whereIn('type', ['dwg', 'skp']);
    });
    Route::delete('/details/{id}/images/{imageId}', [StudioController::class, 'deleteImage'])->whereNumber(['id', 'imageId']);
    Route::delete('/details/{id}/files/{type}', [StudioController::class, 'deleteFile'])->whereNumber('id')->whereIn('type', ['dwg', 'skp']);
});

// Panel admin: solo el superadmin
Route::middleware(['auth:sanctum', 'role:superadmin'])->prefix('admin')->group(function () {
    Route::get('/reports', [AdminErrorReportController::class, 'index']);
    Route::patch('/reports/{id}/resolve', [AdminErrorReportController::class, 'resolve'])->whereNumber('id');

    // Bandeja de aprobación y verificación técnica
    Route::get('/review', [ReviewController::class, 'index']);
    Route::get('/review/{id}', [ReviewController::class, 'show'])->whereNumber('id');
    Route::post('/review/{id}/approve', [ReviewController::class, 'approve'])->whereNumber('id');
    Route::post('/review/{id}/reject', [ReviewController::class, 'reject'])->whereNumber('id');
    Route::post('/review/{id}/verify', [ReviewController::class, 'verify'])->whereNumber('id');
    Route::delete('/review/{id}/verify', [ReviewController::class, 'verify'])->whereNumber('id');

    // Reembolsos (los resuelve el admin a mano) y listado de órdenes y pagos
    Route::get('/refunds', [AdminRefundController::class, 'index']);
    Route::get('/refunds/{id}', [AdminRefundController::class, 'show'])->whereNumber('id');
    Route::post('/refunds/{id}/approve', [AdminRefundController::class, 'approve'])->whereNumber('id');
    Route::post('/refunds/{id}/reject', [AdminRefundController::class, 'reject'])->whereNumber('id');
    Route::get('/refunds/{id}/receipt', [AdminRefundController::class, 'receipt'])->whereNumber('id');
    Route::get('/orders', [AdminOrderController::class, 'index']);

    // Dashboard, detalles, usuarios, planes y configuración
    Route::get('/stats', [AdminStatsController::class, 'index']);
    Route::get('/details', [AdminDetailController::class, 'index']);
    Route::patch('/details/{id}', [AdminDetailController::class, 'update'])->whereNumber('id');
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/exports/users', [AdminExportController::class, 'users'])->middleware('throttle:10,1,export');
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::put('/users/{id}', [AdminUserController::class, 'update'])->whereNumber('id');
    Route::put('/users/{id}/plan', [AdminUserController::class, 'setPlan'])->whereNumber('id');
    Route::put('/plans/{id}', [AdminPlanController::class, 'update'])->whereNumber('id');
    Route::put('/billing/discounts', [AdminPlanController::class, 'discounts']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::get('/comments', [AdminCommentController::class, 'index']);

    // Avisos y notificaciones a usuarios
    Route::get('/notices', [AdminNoticeController::class, 'index']);
    Route::get('/notices/preview', [AdminNoticeController::class, 'preview']);
    Route::get('/notices/users', [AdminNoticeController::class, 'users']);
    Route::post('/notices', [AdminNoticeController::class, 'store'])->middleware('throttle:20,1,notices');
    Route::delete('/notices/{id}', [AdminNoticeController::class, 'destroy'])->whereNumber('id');

    // Etiquetas (SEO)
    Route::get('/tags', [AdminTagController::class, 'index']);
    Route::post('/tags', [AdminTagController::class, 'store']);
    Route::put('/tags/{id}', [AdminTagController::class, 'update'])->whereNumber('id');
    Route::delete('/tags/{id}', [AdminTagController::class, 'destroy'])->whereNumber('id');

    // Carrusel de avisos de la portada
    Route::get('/banners', [AdminBannerController::class, 'index']);
    Route::post('/banners', [AdminBannerController::class, 'store']);
    Route::post('/banners/reorder', [AdminBannerController::class, 'reorder']);
    Route::put('/banners/{id}', [AdminBannerController::class, 'update'])->whereNumber('id');
    Route::post('/banners/{id}/image', [AdminBannerController::class, 'uploadImage'])->whereNumber('id')->middleware('throttle:20,1,banner-image');
    Route::delete('/banners/{id}', [AdminBannerController::class, 'destroy'])->whereNumber('id');

    // Optimización de imágenes cargadas antes de la v2
    Route::get('/images/status', [AdminImageController::class, 'status']);
    Route::post('/images/optimize', [AdminImageController::class, 'optimize'])->middleware('throttle:60,1,images-optimize');

    // Backups (base de datos + archivos del sitio)
    Route::get('/backups', [AdminBackupController::class, 'index']);
    Route::post('/backups', [AdminBackupController::class, 'store'])->middleware('throttle:3,1');
    Route::get('/backups/{name}/download', [AdminBackupController::class, 'download'])->where('name', 'backup-[A-Za-z0-9_.-]+\.zip');
    Route::delete('/backups/{name}', [AdminBackupController::class, 'destroy'])->where('name', 'backup-[A-Za-z0-9_.-]+\.zip');
    Route::put('/settings', [AdminSettingsController::class, 'update']);
});