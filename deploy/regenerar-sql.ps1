<#
.SYNOPSIS
    Regenera deploy/endetalle-schema.sql (la base de datos para importar con phpMyAdmin) a partir de las migraciones.

.DESCRIPTION
    Hay que correrlo cada vez que se agrega una migracion nueva y todavia no se publico el sitio
    (con el sitio ya publicado, las migraciones nuevas se aplican con la tarea cron "migrate --force": ver DEPLOY.md).

    No depende de como este tu base de desarrollo: crea una base temporal vacia en tu MySQL local (con los datos
    DB_* de backend/.env), corre todas las migraciones, vuelca la estructura con php artisan schema:dump y borra la
    base temporal. Hace falta mysqldump en el PATH (viene con Laragon: abri la terminal de Laragon).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\regenerar-sql.ps1
#>
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Target = Join-Path $PSScriptRoot 'endetalle-schema.sql'
$TempDb = 'endetalle_schema_tmp'

foreach ($tool in 'php', 'mysqldump') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { Write-Host "ERROR: no encuentro '$tool' en el PATH (abri la terminal de Laragon)." -ForegroundColor Red; exit 1 }
}

# Datos de conexion de tu MySQL local
$envFile = Join-Path $Backend '.env'
if (-not (Test-Path $envFile)) { Write-Host 'ERROR: falta backend\.env (con los datos DB_* de tu MySQL local).' -ForegroundColor Red; exit 1 }
$settings = @{}
foreach ($line in Get-Content $envFile) { if ($line -match '^\s*(DB_[A-Z]+)\s*=\s*"?([^"#]*)"?\s*$') { $settings[$Matches[1]] = $Matches[2].Trim() } }
foreach ($key in 'DB_HOST', 'DB_USERNAME') { if (-not $settings.ContainsKey($key)) { Write-Host "ERROR: falta $key en backend\.env" -ForegroundColor Red; exit 1 } }
if (-not $settings.ContainsKey('DB_PASSWORD')) { $settings['DB_PASSWORD'] = '' }
if (-not $settings.ContainsKey('DB_PORT')) { $settings['DB_PORT'] = '3306' }

# Crear / borrar la base temporal con un archivo PHP chico (pasar codigo con comillas a "php -r" lo rompe Windows PowerShell)
$helperPath = Join-Path $env:TEMP 'endetalle_tmpdb.php'
$helperCode = @'
<?php
try {
    $pdo = new PDO('mysql:host=' . getenv('T_HOST') . ';port=' . getenv('T_PORT'), getenv('T_USER'), getenv('T_PASS'));
    $db = getenv('T_DB');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    if (getenv('T_ACTION') === 'create') {
        $pdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo conectar a MySQL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
'@
Set-Content -Path $helperPath -Value $helperCode -Encoding Ascii
function Invoke-TempDb([string]$action) {
    $env:T_HOST = $settings['DB_HOST']; $env:T_PORT = $settings['DB_PORT']; $env:T_USER = $settings['DB_USERNAME']
    $env:T_PASS = $settings['DB_PASSWORD']; $env:T_DB = $TempDb; $env:T_ACTION = $action
    & php $helperPath
    if ($LASTEXITCODE -ne 0) { throw "Fallo la base temporal ($action)." }
}

$schemaDir = Join-Path $Backend 'database\schema'
try {
    Write-Host "Creando la base temporal $TempDb..."
    Invoke-TempDb 'create'

    $env:DB_CONNECTION = 'mysql'; $env:DB_DATABASE = $TempDb
    Push-Location $Backend
    try {
        & php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'Fallaron las migraciones.' }
        & php artisan schema:dump
        if ($LASTEXITCODE -ne 0) { throw 'Fallo schema:dump.' }
    } finally { Pop-Location }

    $dump = Join-Path $schemaDir 'mysql-schema.sql'
    if (-not (Test-Path $dump)) { throw 'No se genero el archivo de la base.' }
    Move-Item $dump $Target -Force
    # mysqldump deja saltos de linea mezclados: se dejan todos en LF
    [System.IO.File]::WriteAllText($Target, ([System.IO.File]::ReadAllText($Target) -replace "`r`n", "`n"), (New-Object System.Text.UTF8Encoding($false)))
    if ((Get-ChildItem $schemaDir -Force -ErrorAction SilentlyContinue | Measure-Object).Count -eq 0) { Remove-Item $schemaDir -Force }
    Write-Host "Listo: $Target" -ForegroundColor Green
} finally {
    Remove-Item Env:\DB_CONNECTION, Env:\DB_DATABASE -ErrorAction SilentlyContinue
    try { Invoke-TempDb 'drop'; Write-Host "Base temporal $TempDb borrada." } catch { Write-Host "Avisa: borra a mano la base $TempDb." -ForegroundColor Yellow }
    Remove-Item Env:\T_HOST, Env:\T_PORT, Env:\T_USER, Env:\T_PASS, Env:\T_DB, Env:\T_ACTION -ErrorAction SilentlyContinue
    Remove-Item $helperPath -ErrorAction SilentlyContinue
}
