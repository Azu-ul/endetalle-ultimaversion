<#
.SYNOPSIS
    Genera los archivos para subir enDetalle a un hosting compartido cPanel (sin SSH ni Composer en el servidor).

.DESCRIPTION
    Trabaja sobre una COPIA de backend/ (deploy/build/backend): no toca tu vendor ni tu .env de desarrollo.
      1. Corre las pruebas automaticas del backend (se puede saltar con -SkipTests).
      2. Instala las dependencias de PHP sin las de desarrollo (composer install --no-dev --optimize-autoloader),
         fijando PHP 8.3 como plataforma, y cachea las rutas.
      3. Compila el frontend apuntando a la API de produccion y revisa que no se haya colado ninguna clave.
      4. Arma en deploy/dist:
           endetalle-backend.zip    (sin .env, tests, node_modules, storage/logs ni caches)
           endetalle-frontend.zip   (sitio compilado + .htaccess)
           endetalle-schema.sql     (base de datos para importar con phpMyAdmin)
           .env.production.example  (plantilla del .env del servidor)

.PARAMETER ApiUrl
    URL publica de la API, con https y sin barra al final. Ejemplo: https://api.midominio.com.ar

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\build.ps1 -ApiUrl https://api.midominio.com.ar
#>
param(
    [Parameter(Mandatory = $true)][string]$ApiUrl,
    [string]$PhpPlatform = '8.3.0',
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$Root = Split-Path -Parent $PSScriptRoot
$Deploy = Join-Path $Root 'deploy'
$Build = Join-Path $Deploy 'build'
$Dist = Join-Path $Deploy 'dist'
$BackendSrc = Join-Path $Root 'backend'
$FrontendSrc = Join-Path $Root 'frontend'
$BackendBuild = Join-Path $Build 'backend'
$FrontendBuild = Join-Path $Build 'frontend'

function Step([string]$text) { Write-Host ''; Write-Host "==> $text" -ForegroundColor Cyan }
function Fail([string]$text) { Write-Host "ERROR: $text" -ForegroundColor Red; exit 1 }

# Corre un programa externo y corta el script si falla (en Windows PowerShell los errores de .exe no cortan solos)
function Run([string]$exe, [string[]]$arguments, [string]$workDir) {
    Push-Location $workDir
    # composer y npm escriben su progreso por stderr: en Windows PowerShell 5.1 eso no tiene que cortar el script,
    # solo cuenta el codigo de salida
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $exe @arguments
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous; Pop-Location }
    if ($code -ne 0) { Fail "Fallo el comando: $exe $($arguments -join ' ')" }
}

# Zip con barras "/" en los nombres (Compress-Archive de Windows PowerShell 5.1 usa "\" y en el hosting
# los archivos se extraen mal). Incluye archivos ocultos como .htaccess.
function New-Zip([string]$sourceDir, [string]$zipPath) {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    $source = (Resolve-Path $sourceDir).Path.TrimEnd('\')
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $source -Recurse -Force -File | ForEach-Object {
            $entry = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal)
        }
    } finally { $zip.Dispose() }
}

# Copia un archivo de texto dejandolo con saltos de linea LF (un .htaccess con CRLF puede dar error 500 en Apache)
function Copy-AsLf([string]$from, [string]$to) {
    $text = [System.IO.File]::ReadAllText($from) -replace "`r`n", "`n"
    [System.IO.File]::WriteAllText($to, $text, (New-Object System.Text.UTF8Encoding($false)))
}

# ---------------------------------------------------------------------------------------------
Step 'Revisando los datos y las herramientas'

$ApiUrl = $ApiUrl.Trim().TrimEnd('/')
if ($ApiUrl -notmatch '^https://[a-z0-9.-]+\.[a-z]{2,}$') { Fail "ApiUrl tiene que ser una direccion https sin barra al final, por ejemplo https://api.midominio.com.ar (recibido: $ApiUrl)" }
if ($ApiUrl -match 'localhost|127\.0\.0\.1') { Fail 'ApiUrl no puede apuntar a localhost.' }

foreach ($tool in 'php', 'composer', 'node', 'npm') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { Fail "No encuentro '$tool' en el PATH. Instalalo (o abri la terminal de Laragon) y volve a correr el script." }
}
$phpVersion = (& php -r 'echo PHP_VERSION;')
if ([version]($phpVersion -replace '^(\d+\.\d+\.\d+).*$', '$1') -lt [version]'8.3.0') { Fail "Hace falta PHP 8.3 o mas (tenes $phpVersion)." }
Write-Host "PHP $phpVersion | Node $(& node -v) | API de produccion: $ApiUrl"

if (-not $SkipTests) {
    Step 'Corriendo las pruebas automaticas del backend'
    Run 'php' @('vendor/bin/phpunit') $BackendSrc
}

# ---------------------------------------------------------------------------------------------
Step 'Preparando una copia limpia del backend (deploy/build/backend)'

if (Test-Path $Build) { Remove-Item $Build -Recurse -Force }
New-Item -ItemType Directory -Force -Path $BackendBuild, $FrontendBuild, $Dist | Out-Null
Get-ChildItem $Dist -Force | Remove-Item -Recurse -Force

# robocopy: codigos 0 a 7 = ok. Sin vendor/tests/.env/node_modules/storage/logs y sin el enlace public/storage
$excludeDirs = @('vendor', 'node_modules', 'tests', '.git', '.idea', '.vscode', '.phpunit.cache', '.cursor', '.codex', 'docs',
    (Join-Path $BackendSrc 'public\storage'), (Join-Path $BackendSrc 'storage\logs'))
$excludeFiles = @('.env', '.env.backup', '.env.production', 'phpunit.xml', '.phpunit.result.cache', '*.log', '*.sqlite', '*.sqlite-journal', '.DS_Store', 'auth.json',
    'AGENTS.md', 'CLAUDE.md', 'README.md', '.editorconfig', '.gitattributes', '.gitignore', '.npmrc', 'package.json', 'vite.config.js')
& robocopy $BackendSrc $BackendBuild /E /XJ /NFL /NDL /NJH /NJS /NP /XD @excludeDirs /XF @excludeFiles | Out-Null
if ($LASTEXITCODE -ge 8) { Fail 'No se pudo copiar el backend.' }
$global:LASTEXITCODE = 0

# Nada del contenido de desarrollo: ni archivos subidos, ni logs, ni caches
Get-ChildItem (Join-Path $BackendBuild 'storage') -Recurse -File -Force | Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Force
Get-ChildItem (Join-Path $BackendBuild 'bootstrap\cache') -File -Force | Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Force

# Carpetas de storage que Laravel necesita (cada una con un .gitignore para que el zip las conserve)
foreach ($dir in 'app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\views', 'framework\testing', 'logs') {
    $path = Join-Path $BackendBuild "storage\$dir"
    New-Item -ItemType Directory -Force -Path $path | Out-Null
    if (-not (Test-Path (Join-Path $path '.gitignore'))) { Set-Content -Path (Join-Path $path '.gitignore') -Value "*`n!.gitignore" -Encoding Ascii }
}

# Carpeta de imagenes publicas: sin enlaces simbolicos (el hosting los bloquea), y sin ejecucion de scripts
$publicStorage = Join-Path $BackendBuild 'public\storage'
New-Item -ItemType Directory -Force -Path $publicStorage | Out-Null
Copy-AsLf (Join-Path $Deploy 'templates\storage.htaccess') (Join-Path $publicStorage '.htaccess')
# El .htaccess de la API y la plantilla del .env tambien con LF, aunque Git los haya bajado con CRLF
foreach ($file in 'public\.htaccess', '.env.production.example', '.env.example') {
    $path = Join-Path $BackendBuild $file
    if (Test-Path $path) { Copy-AsLf $path $path }
}

# ---------------------------------------------------------------------------------------------
Step "Instalando las dependencias de PHP (sin las de desarrollo, plataforma PHP $PhpPlatform)"

Run 'composer' @('config', 'platform.php', $PhpPlatform, '--no-interaction') $BackendBuild
Run 'composer' @('update', '--lock', '--no-interaction') $BackendBuild
Run 'composer' @('install', '--no-dev', '--optimize-autoloader', '--no-interaction') $BackendBuild

Step 'Cacheando las rutas (la configuracion y las vistas se cachean en el servidor: ver DEPLOY.md)'
$previousEnv = $env:APP_ENV
$env:APP_ENV = 'production'
try { Run 'php' @('artisan', 'route:cache') $BackendBuild } finally { $env:APP_ENV = $previousEnv }
foreach ($cache in 'config.php', 'events.php') {
    $file = Join-Path $BackendBuild "bootstrap\cache\$cache"
    if (Test-Path $file) { Remove-Item $file -Force }   # nunca se manda una configuracion cacheada de esta PC
}

# ---------------------------------------------------------------------------------------------
Step 'Compilando el frontend para produccion'

if (-not (Test-Path (Join-Path $FrontendSrc 'node_modules'))) { Run 'npm' @('install') $FrontendSrc }
$env:VITE_API_URL = $ApiUrl
try { Run 'npx' @('vite', 'build', '--outDir', $FrontendBuild, '--emptyOutDir') $FrontendSrc } finally { Remove-Item Env:\VITE_API_URL -ErrorAction SilentlyContinue }

if (-not (Test-Path (Join-Path $FrontendBuild 'index.html'))) { Fail 'La compilacion del frontend no genero index.html.' }
Copy-AsLf (Join-Path $Deploy 'templates\frontend.htaccess') (Join-Path $FrontendBuild '.htaccess')
Copy-AsLf (Join-Path $Deploy 'templates\assets.htaccess') (Join-Path $FrontendBuild 'assets\.htaccess')

Step 'Revisando que el sitio compilado apunte a la API de produccion y no lleve claves'
$bundle = (Get-ChildItem (Join-Path $FrontendBuild 'assets') -Filter *.js | ForEach-Object { Get-Content $_.FullName -Raw -Encoding UTF8 }) -join "`n"
if ($bundle -notmatch [regex]::Escape($ApiUrl)) { Fail "El sitio compilado no contiene la direccion de la API ($ApiUrl)." }
if ($bundle -match 'localhost:8000|127\.0\.0\.1:8000') { Fail 'El sitio compilado todavia apunta a localhost.' }
$secretPattern = 'APP_USR-[0-9]|TEST-[0-9a-f]{16}|AIza[0-9A-Za-z_\-]{30}|sk_live_|-----BEGIN (RSA |EC )?PRIVATE KEY|client_secret|PAYPAL_SECRET|MERCADOPAGO_ACCESS_TOKEN'
if ($bundle -match $secretPattern) { Fail "Se encontro algo que parece una clave dentro del sitio compilado: $($Matches[0])" }
Write-Host 'OK: apunta a la API de produccion y no hay claves.'

# ---------------------------------------------------------------------------------------------
Step 'Armando los zips en deploy/dist'

New-Zip $BackendBuild (Join-Path $Dist 'endetalle-backend.zip')
New-Zip $FrontendBuild (Join-Path $Dist 'endetalle-frontend.zip')
Copy-AsLf (Join-Path $Deploy 'endetalle-schema.sql') (Join-Path $Dist 'endetalle-schema.sql')
Copy-AsLf (Join-Path $BackendSrc '.env.production.example') (Join-Path $Dist '.env.production.example')

# Verificacion final del contenido del zip del backend
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Join-Path $Dist 'endetalle-backend.zip'))
try {
    $names = $zip.Entries | ForEach-Object { $_.FullName }
    $bad = $names | Where-Object { $_ -match '(^|/)\.env$|(^|/)tests/|(^|/)node_modules/|^storage/logs/[^.]|\\' }
    if ($bad) { Fail "El zip del backend trae archivos que no deberian estar: $($bad | Select-Object -First 5)" }
    foreach ($needed in 'artisan', 'public/index.php', 'public/.htaccess', 'public/storage/.htaccess', 'vendor/autoload.php', 'bootstrap/cache/routes-v7.php', '.env.production.example', 'storage/logs/.gitignore') {
        if ($names -notcontains $needed) { Fail "Al zip del backend le falta: $needed" }
    }
} finally { $zip.Dispose() }

# Ningun .htaccess con saltos CRLF dentro de los zips
foreach ($zipName in 'endetalle-backend.zip', 'endetalle-frontend.zip') {
    $check = [System.IO.Compression.ZipFile]::OpenRead((Join-Path $Dist $zipName))
    try {
        foreach ($entry in ($check.Entries | Where-Object { $_.Name -eq '.htaccess' })) {
            $reader = New-Object System.IO.StreamReader($entry.Open())
            try { $content = $reader.ReadToEnd() } finally { $reader.Dispose() }
            if ($content.Contains("`r")) { Fail "$zipName / $($entry.FullName) tiene saltos de linea CRLF (puede dar error 500 en Apache)." }
        }
    } finally { $check.Dispose() }
}

# Clave de la aplicacion (APP_KEY) lista para pegar en el .env del servidor
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$appKey = 'base64:' + [Convert]::ToBase64String($bytes)

Write-Host ''
Write-Host '==============================================================' -ForegroundColor Green
Write-Host ' LISTO. Archivos generados en deploy\dist:' -ForegroundColor Green
Get-ChildItem $Dist -File | ForEach-Object { Write-Host ('   {0,-28} {1,8:N1} MB' -f $_.Name, ($_.Length / 1MB)) }
Write-Host ''
Write-Host ' APP_KEY para el .env del servidor (se genera una sola vez, no la cambies despues):' -ForegroundColor Yellow
Write-Host "   $appKey"
Write-Host '   Guardala en un lugar seguro: si se pierde, hay que generar otra y se cierran todas las sesiones.'
Write-Host ''
Write-Host ' Siguiente paso: abrir deploy\DEPLOY.md y seguirlo punto por punto.'
Write-Host '==============================================================' -ForegroundColor Green
