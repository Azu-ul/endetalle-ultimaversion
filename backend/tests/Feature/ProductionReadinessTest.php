<?php

namespace Tests\Feature;

use App\Models\Detail;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Lo que tiene que cumplirse antes de publicar el sitio: salud, errores sin datos internos, seguridad y datos iniciales. */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'una-clave-larga-123';

    /** Corre AdminUserSeeder sin pasar por `db:seed` (que en producción pide confirmación interactiva). */
    private function runAdminSeeder(): void
    {
        $this->app->make(AdminUserSeeder::class)->run();
    }

    private function admin(array $config = []): void
    {
        config($config + ['admin.name' => 'Dueña del sitio', 'admin.email' => 'duena@sitio.com', 'admin.password' => self::ADMIN_PASSWORD]);
    }

    private function middlewareOf(string $method, string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }
        $this->fail("No existe la ruta {$method} {$uri}");
    }

    // ---------- Ruta de salud ----------

    public function test_health_responde_ok_y_solo_dice_si_o_no(): void
    {
        $res = $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('database', true)->assertJsonPath('storage', true);

        $this->assertEqualsCanonicalizing(['status', 'database', 'storage', 'time'], array_keys($res->json()));
        $body = $res->getContent();
        foreach ([base_path(), 'SQLSTATE', 'sqlite', 'mysql', 'password', 'APP_KEY', config('app.key')] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    public function test_health_no_requiere_sesion_pero_tiene_limite_de_intentos(): void
    {
        $this->assertContains('throttle:60,1,health', $this->middlewareOf('GET', 'api/health'));
        $this->assertNotContains('auth:sanctum', $this->middlewareOf('GET', 'api/health'));
    }

    // ---------- Errores sin datos internos ----------

    public function test_en_produccion_un_error_no_muestra_trazas_ni_rutas_del_servidor(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
        Route::get('/api/_prueba-error', fn () => throw new \RuntimeException('SECRETO en /home/usuario/endetalle/app.php'));

        $res = $this->getJson('/api/_prueba-error')->assertStatus(500);

        $body = $res->getContent();
        foreach (['SECRETO', '/home/usuario', base_path(), 'RuntimeException', 'trace', '"file"', '"line"', 'vendor/'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertSame('Server Error', $res->json('message'));
    }

    public function test_en_produccion_una_pagina_que_no_existe_da_404_sin_pistas_del_servidor(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.spa_index' => storage_path('no-existe/index.html')]);

        $res = $this->get('/cualquier-ruta');

        $res->assertNotFound();
        foreach (['vite', 'build', 'SPA_INDEX', 'frontend/dist', base_path()] as $hint) {
            $this->assertStringNotContainsString($hint, $res->getContent());
        }
        $this->getJson('/api/no-existe')->assertNotFound();
    }

    public function test_un_404_comun_de_la_api_no_muestra_nombres_internos_pero_los_avisos_para_el_usuario_si(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@test.com', 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        // Ruta que no existe y registro que no existe (antes: "No query results for model [App\\Models\\Order]")
        foreach (['/api/no-existe', '/api/orders/' . \Illuminate\Support\Str::uuid(), '/api/details/999/files/dwg'] as $url) {
            $res = $this->getJson($url)->assertNotFound()->assertJsonPath('message', 'No encontrado.');
            foreach (['App\\Models', 'Models', 'query results', 'route api'] as $leak) {
                $this->assertStringNotContainsString($leak, $res->getContent(), $url);
            }
        }

        // Un aviso pensado para el usuario sigue saliendo tal cual
        Route::get('/api/_aviso', fn () => abort(404, 'Este detalle no tiene archivo SKP.'));
        $this->getJson('/api/_aviso')->assertNotFound()->assertJsonPath('message', 'Este detalle no tiene archivo SKP.');
    }

    public function test_en_produccion_los_links_salen_siempre_con_la_direccion_https_de_app_url(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://api.midominio.com.ar']);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertSame('https://api.midominio.com.ar/storage/avatars/a.png', url('/storage/avatars/a.png'));
        $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 1, 'hash' => 'abc']);
        $this->assertStringStartsWith('https://api.midominio.com.ar/api/email/verify/1/abc?', $signed);
    }

    public function test_la_configuracion_de_produccion_apaga_el_debug_y_usa_logs_diarios(): void
    {
        $example = $this->envFile(base_path('.env.production.example'));

        $this->assertSame('production', $example['APP_ENV']);
        $this->assertSame('false', $example['APP_DEBUG']);
        $this->assertSame('daily', $example['LOG_CHANNEL']);
        $this->assertSame('live', $example['PAYPAL_MODE']);
        $this->assertSame('local', $example['DETAILS_DISK']);
        $this->assertSame('true', $example['SESSION_SECURE_COOKIE']);
        $this->assertStringStartsWith('https://', $example['APP_URL']);
        $this->assertStringStartsWith('https://', $example['FRONTEND_URL']);
        $this->assertStringNotContainsString('localhost', $example['APP_URL'] . $example['FRONTEND_URL']);
        $this->assertMatchesRegularExpression("/'debug'\s*=>\s*\(bool\)\s*env\('APP_DEBUG',\s*false\)/", (string) file_get_contents(config_path('app.php')), 'sin APP_DEBUG el debug tiene que quedar apagado');
    }

    // ---------- Seguridad ----------

    public function test_todas_las_rutas_de_admin_exigen_sesion_y_el_rol_superadmin(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }
            $count++;
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
            $this->assertContains('role:superadmin', $middleware, $route->uri());
        }
        $this->assertGreaterThan(20, $count);
    }

    public function test_un_usuario_comun_o_colaborador_no_entra_al_admin_y_sin_sesion_tampoco(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@test.com', 'password' => 'password12']);
        $user->assignRole(Role::findOrCreate('colaborador', 'web'));

        foreach (['/api/admin/stats', '/api/admin/users', '/api/admin/refunds', '/api/admin/orders', '/api/admin/review'] as $url) {
            $this->getJson($url)->assertUnauthorized();
            Sanctum::actingAs($user);
            $this->getJson($url)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_cors_solo_permite_el_dominio_del_sitio(): void
    {
        config(['cors.allowed_origins' => ['https://midominio.com.ar']]);
        $this->app->forgetInstance(\Illuminate\Http\Middleware\HandleCors::class);

        // El sitio: permitido, con cookies
        $ok = $this->withHeaders(['Origin' => 'https://midominio.com.ar'])->getJson('/api/plans');
        $this->assertSame('https://midominio.com.ar', $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $ok->headers->get('Access-Control-Allow-Credentials'));

        // Cualquier otro origen: nunca se le devuelve su propio origen ni un comodín
        foreach (['https://otro-sitio.com', 'http://midominio.com.ar', 'https://evil.midominio.com.ar'] as $origin) {
            $res = $this->withHeaders(['Origin' => $origin])->getJson('/api/plans');
            $allowed = $res->headers->get('Access-Control-Allow-Origin');
            $this->assertNotSame($origin, $allowed, $origin);
            $this->assertNotSame('*', $allowed, $origin);

            $preflight = $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])->options('/api/login');
            $this->assertNotSame($origin, $preflight->headers->get('Access-Control-Allow-Origin'), $origin);
        }
    }

    public function test_la_carga_de_detalles_exige_rol_de_colaborador_o_superadmin(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/studio')) {
                $this->assertContains('role:colaborador|superadmin', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    public function test_los_endpoints_sensibles_tienen_limite_de_intentos(): void
    {
        foreach ([
            ['POST', 'api/login'], ['POST', 'api/register'], ['POST', 'api/forgot-password'], ['POST', 'api/reset-password'],
            ['GET', 'api/reset-password/check'], ['POST', 'api/contact'], ['POST', 'api/checkout'], ['POST', 'api/orders/{uuid}/capture'],
            ['POST', 'api/orders/sync'], ['POST', 'api/refunds'], ['POST', 'api/email/resend'], ['POST', 'api/subscription/cancel'],
            ['POST', 'api/webhooks/mercadopago'], ['POST', 'api/webhooks/paypal'], ['PUT', 'api/user/password'],
            ['GET', 'api/details/{id}/files/{type}'],
        ] as [$method, $uri]) {
            $throttled = collect($this->middlewareOf($method, $uri))->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertTrue($throttled, "{$method} {$uri} no tiene límite de intentos");
        }
    }

    public function test_el_login_se_bloquea_despues_de_6_intentos_por_minuto(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['email' => 'nadie@test.com', 'password' => 'mala'])->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'nadie@test.com', 'password' => 'mala'])->assertStatus(429);
    }

    public function test_los_ejemplos_de_env_no_traen_claves_y_no_hay_secretos_en_el_frontend_ni_en_el_repositorio(): void
    {
        // Ninguna clave secreta viene con valor en los ejemplos
        foreach (['.env.example', '.env.production.example'] as $file) {
            $env = $this->envFile(base_path($file));
            foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_WEBHOOK_SECRET', 'PAYPAL_CLIENT_ID', 'PAYPAL_SECRET', 'PAYPAL_WEBHOOK_ID', 'ADMIN_PASSWORD', 'ADMIN_EMAIL'] as $key) {
                if (array_key_exists($key, $env)) {
                    $this->assertContains($env[$key], ['', 'null'], "{$file}: {$key} no puede traer un valor");
                }
            }
        }

        // Nada parecido a una clave real en el frontend (código fuente) ni en el resto de los archivos versionados
        $pattern = '/APP_USR-[0-9]|TEST-[0-9a-f]{16}|AIza[0-9A-Za-z_\-]{30}|sk_live_|-----BEGIN (RSA |EC )?PRIVATE KEY|client_secret\s*[:=]\s*[\'"][A-Za-z0-9]{16,}/';
        $root = dirname(base_path());
        $tracked = $this->trackedFiles($root);
        if ($tracked === null) {
            $this->markTestSkipped('git no está disponible para listar los archivos versionados.');
        }
        $scanned = 0;
        foreach ($tracked as $file) {
            // La prueba y build.ps1 contienen los patrones que usan para BUSCAR claves: no se escanean a sí mismos
            if (str_ends_with($file, 'ProductionReadinessTest.php') || $file === 'deploy/build.ps1' || preg_match('/\.(png|ico|svg|jpg|webp|lock)$|package-lock/', $file) || ! is_file("{$root}/{$file}")) {
                continue;
            }
            $scanned++;
            $this->assertSame(0, preg_match($pattern, (string) file_get_contents("{$root}/{$file}")), "Parece haber una clave en {$file}");
        }
        $this->assertGreaterThan(100, $scanned);

        // El único archivo .env versionado del frontend tiene solo la URL de la API
        $this->assertSame([], array_values(array_filter($tracked, fn ($f) => preg_match('#(^|/)\.env$#', $f) === 1)));
        $frontendEnv = $root . '/frontend/.env.development';
        $this->assertDoesNotMatchRegularExpression('/KEY|SECRET|TOKEN|PASSWORD/i', (string) @file_get_contents($frontendEnv));
        $this->assertStringNotContainsString('define:', (string) file_get_contents($root . '/frontend/vite.config.ts'));
    }

    // ---------- Administrador desde variables ----------

    public function test_el_seeder_crea_al_admin_con_los_datos_del_env_verificado_y_con_contrasena_cifrada(): void
    {
        $this->admin();

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'duena@sitio.com')->firstOrFail();
        $this->assertSame('Dueña del sitio', $admin->name);
        $this->assertTrue($admin->hasRole('superadmin'));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame('active', $admin->status);
        $this->assertNotSame(self::ADMIN_PASSWORD, $admin->password);
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $admin->password));
        $this->assertSame(1, User::count());
    }

    public function test_sin_email_o_contrasena_no_crea_nada(): void
    {
        $this->admin(['admin.password' => '']);
        $this->seed(AdminUserSeeder::class);
        $this->admin(['admin.email' => 'no-es-un-email']);
        $this->seed(AdminUserSeeder::class);
        $this->admin(['admin.email' => null]);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_en_produccion_exige_una_contrasena_de_al_menos_12_caracteres(): void
    {
        $this->app['env'] = 'production';
        $this->admin(['admin.password' => 'corta123']);
        $this->runAdminSeeder();
        $this->assertSame(0, User::count());

        $this->admin(['admin.password' => '12345678901']); // 11
        $this->runAdminSeeder();
        $this->assertSame(0, User::count());

        $this->admin(['admin.password' => '123456789012']); // 12
        $this->runAdminSeeder();
        $this->assertSame(1, User::count());
    }

    public function test_el_superadmin_es_unico_y_volver_a_correr_el_seeder_no_cambia_la_contrasena(): void
    {
        $this->admin();
        $this->seed(AdminUserSeeder::class);
        $hash = User::first()->password;

        $this->admin(['admin.password' => 'otra-clave-distinta-999']);
        $this->seed(AdminUserSeeder::class);                       // mismo email: no pisa la contraseña
        $this->assertSame($hash, User::first()->password);

        $this->admin(['admin.email' => 'otro@sitio.com']);          // otro email: no se crea un segundo superadmin
        $this->seed(AdminUserSeeder::class);
        $this->assertSame(1, User::count());
        $this->assertSame(1, User::role('superadmin')->count());
    }

    public function test_ningun_seeder_de_produccion_trae_contrasenas_ni_datos_de_demo(): void
    {
        foreach (['AdminUserSeeder', 'ProductionSeeder', 'RoleSeeder'] as $seeder) {
            $source = (string) file_get_contents(database_path("seeders/{$seeder}.php"));
            $this->assertDoesNotMatchRegularExpression('/Hash::make\(\s*[\'"]/', $source, $seeder);
            $this->assertDoesNotMatchRegularExpression('/[\'"]password[\'"]\s*=>\s*[\'"][^\'"]/', $source, $seeder);
            $this->assertStringNotContainsString('Demo', preg_replace('/\/\*\*.*?\*\//s', '', $source), $seeder);
            $this->assertStringNotContainsString('TestAccounts', preg_replace('/\/\*\*.*?\*\//s', '', $source), $seeder);
        }
    }

    public function test_el_seeder_de_produccion_carga_lo_minimo_sin_demos_y_se_puede_repetir(): void
    {
        $this->admin();

        $this->seed(ProductionSeeder::class);
        $this->seed(ProductionSeeder::class);

        $this->assertSame(['colaborador', 'superadmin', 'usuario'], Role::orderBy('name')->pluck('name')->all());
        $this->assertSame(['free', 'estudiante', 'pro'], DB::table('plans')->orderBy('level')->pluck('slug')->all());
        $this->assertTrue(DB::table('categories')->where('slug', 'premium')->exists());
        $this->assertGreaterThan(5, DB::table('settings')->count());
        $this->assertSame(['duena@sitio.com'], User::pluck('email')->all()); // ni colaboradores de prueba ni usuarios demo
        $this->assertSame(0, Detail::count());
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%demo.endetalle.test')->count());
    }

    // ---------- Imágenes públicas sin storage:link ----------

    public function test_avatares_y_portadas_se_guardan_y_se_sirven_sin_storage_link(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Storage::fake('public');
        $this->assertSame([], config('filesystems.links'));
        $this->assertSame(public_path('storage'), config('filesystems.disks.public.root')); // escribe directo en public/storage
        $this->assertSame(rtrim(config('app.url'), '/') . '/storage', config('filesystems.disks.public.url')); // https://api.dominio/storage/...

        $user = User::create(['name' => 'Ana', 'email' => 'ana@test.com', 'password' => 'password12']);
        Sanctum::actingAs($user);
        foreach (['avatar', 'cover'] as $kind) {
            $res = $this->post("/api/user/{$kind}", ['image' => UploadedFile::fake()->image("{$kind}.png", 200, 200)], ['Accept' => 'application/json'])->assertOk();

            $url = $res->json($kind === 'avatar' ? 'avatar_url' : 'cover_url');
            // La URL pública cuelga de /storage (en el servidor real, con el dominio de APP_URL) y apunta a un archivo que existe
            $this->assertStringContainsString("/storage/{$kind}s/", $url);
            Storage::disk('public')->assertExists("{$kind}s/" . basename($url));
        }
    }

    // ---------- Utilidades ----------

    /** @return array<string, string> */
    private function envFile(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m)) {
                $values[$m[1]] = trim($m[2], " \"'");
            }
        }

        return $values;
    }

    /** @return string[]|null */
    private function trackedFiles(string $root): ?array
    {
        $output = @shell_exec('git -C ' . escapeshellarg($root) . ' ls-files 2>NUL');
        if (! $output) {
            $output = @shell_exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null');
        }

        return $output ? array_values(array_filter(explode("\n", str_replace("\r", '', $output)))) : null;
    }
}
