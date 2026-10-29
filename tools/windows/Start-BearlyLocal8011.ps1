[CmdletBinding()]
param(
    [ValidateSet(8000, 8011)]
    [int] $Port = 8011
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$PhpPath = 'C:\xampp\php\php.exe'
$ExpectedDatabase = 'bearly_latest_dev_import_20261028'
$PublicRoot = (Resolve-Path (Join-Path $ProjectRoot 'public')).Path
$StorageRoot = (Resolve-Path (Join-Path $ProjectRoot 'storage\app\public')).Path
$PublicStorage = Get-Item -LiteralPath (Join-Path $PublicRoot 'storage') -Force

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable not found at $PhpPath. Update PhpPath in this local startup script if XAMPP is installed elsewhere."
}

if ($PublicStorage.LinkType -ne 'Junction') {
    throw 'public/storage is not the expected Windows junction. The script will not replace it.'
}

$StorageJunctionTarget = (Resolve-Path -LiteralPath ([string] @($PublicStorage.Target)[0])).Path
if (-not [string]::Equals(
        $StorageJunctionTarget.TrimEnd('\'),
        $StorageRoot.TrimEnd('\'),
        [System.StringComparison]::OrdinalIgnoreCase
    )) {
    throw 'public/storage does not resolve to storage/app/public. The script will not change it.'
}

$ExistingListener = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
$PortResponds = $false
$TcpProbe = [System.Net.Sockets.TcpClient]::new()
try {
    $ConnectAttempt = $TcpProbe.BeginConnect('127.0.0.1', $Port, $null, $null)
    if ($ConnectAttempt.AsyncWaitHandle.WaitOne(250) -and $TcpProbe.Connected) {
        $PortResponds = $true
    }
} catch {
    $PortResponds = $false
} finally {
    $TcpProbe.Close()
}

if ($ExistingListener.Count -gt 0 -or $PortResponds) {
    $ListenerPids = ($ExistingListener | Select-Object -ExpandProperty OwningProcess -Unique) -join ', '
    if ([string]::IsNullOrWhiteSpace($ListenerPids)) {
        $ListenerPids = 'unknown to Get-NetTCPConnection'
    }
    throw "Port $Port already has a listener (PID(s): $ListenerPids). No process was stopped or changed."
}

$RuntimeRoot = Join-Path $env:TEMP "bearly-laravel-$Port"
$RuntimeMarker = Join-Path $RuntimeRoot 'runtime-owner.txt'
$RouterPath = Join-Path $RuntimeRoot 'router.php'
$ProjectRootFile = Join-Path $RuntimeRoot 'project-root.txt'
$VerificationTokenFile = Join-Path $RuntimeRoot 'verification-token.txt'
$DatabaseProbePath = Join-Path $RuntimeRoot 'verify-target-database.php'

if (Test-Path -LiteralPath $RuntimeRoot) {
    if (-not (Test-Path -LiteralPath $RuntimeMarker -PathType Leaf)) {
        throw "The temporary runtime directory already exists without this script's marker: $RuntimeRoot. Inspect it before reuse."
    }

    $ExistingOwner = (Get-Content -LiteralPath $RuntimeMarker -Raw).Trim()
    if (-not [string]::Equals($ExistingOwner, $ProjectRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "The temporary runtime directory belongs to another project: $RuntimeRoot."
    }
} else {
    New-Item -ItemType Directory -Path $RuntimeRoot | Out-Null
    Set-Content -LiteralPath $RuntimeMarker -Value $ProjectRoot -Encoding ASCII
}

$RouterCode = @'
<?php
declare(strict_types=1);

$projectRoot = trim((string) @file_get_contents(__DIR__.'/project-root.txt'));
$projectRoot = realpath($projectRoot);
$publicRoot = $projectRoot === false ? false : realpath($projectRoot.DIRECTORY_SEPARATOR.'public');
$storageRoot = $projectRoot === false ? false : realpath($projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public');

if ($projectRoot === false || $publicRoot === false || $storageRoot === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Local Laravel runtime paths are unavailable.\n";
    exit;
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Local access only.\n";
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    $requestPath = '/';
}

if ($requestPath === '/storage' || str_starts_with($requestPath, '/storage/')) {
    serveUploadedImage($requestPath, $storageRoot);
    exit;
}

$verificationPath = '/__bearly_local_db_check';
if ($requestPath === $verificationPath) {
    $verificationTokenFile = __DIR__.'/verification-token.txt';
    $verificationToken = is_file($verificationTokenFile)
        ? trim((string) file_get_contents($verificationTokenFile))
        : '';
    $providedToken = (string) ($_SERVER['HTTP_X_BEARLY_DB_VERIFY'] ?? '');
    if ($verificationToken === '' || $providedToken === '' || !hash_equals($verificationToken, $providedToken)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
        exit;
    }

    try {
        require_once $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        $verificationApp = require $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        $verificationApp->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $databaseRow = $verificationApp->make('db')->connection()->selectOne('SELECT DATABASE() AS database_name');
        $databaseName = is_object($databaseRow) && isset($databaseRow->database_name)
            ? (string) $databaseRow->database_name
            : '';
        $configurationCached = $verificationApp->configurationIsCached();
        $expectedDatabase = (string) getenv('DB_DATABASE');
        $matchesExpected = $databaseName !== '' && $databaseName === $expectedDatabase;

        http_response_code($matchesExpected && !$configurationCached ? 200 : 409);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'database' => $databaseName,
            'expected_database' => $expectedDatabase,
            'driver' => $verificationApp['db']->connection()->getDriverName(),
            'configuration_cached' => $configurationCached,
            'environment' => $verificationApp->environment(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Bearly local database verification failed: '.$exception::class.' at '.$exception->getFile().':'.$exception->getLine().' '.$exception->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Local database verification failed.\n";
        exit;
    }
    exit;
}

if (isSafePublicStaticFile($requestPath, $publicRoot)) {
    return false;
}

require $publicRoot.DIRECTORY_SEPARATOR.'index.php';

function isSafePublicStaticFile(string $requestPath, string $publicRoot): bool
{
    if ($requestPath === '/' || str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
        return false;
    }

    if (preg_match('/%(?![0-9A-Fa-f]{2})/', $requestPath) === 1) {
        return false;
    }

    $decodedPath = rawurldecode($requestPath);
    $segments = explode('/', ltrim($decodedPath, '/'));
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
            return false;
        }
    }

    if (preg_match('/\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh)\z/i', $decodedPath) === 1) {
        return false;
    }

    $candidate = realpath($publicRoot.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments));
    if ($candidate === false || !is_file($candidate)) {
        return false;
    }

    return isPathInside($candidate, $publicRoot);
}

function serveUploadedImage(string $requestPath, string $storageRoot): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Method not allowed.\n";
        return;
    }

    if ($requestPath === '/storage' || preg_match('/%(?![0-9A-Fa-f]{2})/', $requestPath) === 1
        || str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
        uploadedImageNotFound();
        return;
    }

    $decodedPath = rawurldecode($requestPath);
    $relativePath = substr($decodedPath, strlen('/storage/'));
    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.') || str_contains($segment, ':')) {
            uploadedImageNotFound();
            return;
        }
    }

    if (preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|\z)/i', $relativePath) === 1) {
        uploadedImageNotFound();
        return;
    }

    $filePath = realpath($storageRoot.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments));
    if ($filePath === false || !is_file($filePath) || !isPathInside($filePath, $storageRoot)) {
        uploadedImageNotFound();
        return;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
        'image/bmp',
        'image/tiff',
        'image/x-icon',
    ];
    if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes, true)) {
        uploadedImageNotFound();
        return;
    }

    header('Content-Type: '.$mimeType);
    header('Content-Length: '.(string) filesize($filePath));
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');

    if ($method === 'GET') {
        readfile($filePath);
    }
}

function uploadedImageNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Image not found.\n";
}

function isPathInside(string $filePath, string $rootPath): bool
{
    $prefix = rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

    return strncasecmp($filePath, $prefix, strlen($prefix)) === 0;
}
'@

$DatabaseProbeCode = @'
<?php
declare(strict_types=1);

$projectRoot = trim((string) file_get_contents(__DIR__.'/project-root.txt'));
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($app->configurationIsCached()) {
    fwrite(STDERR, "Laravel unexpectedly loaded cached configuration.\n");
    exit(2);
}

$connection = $app['db']->connection();
$row = $connection->selectOne('SELECT DATABASE() AS database_name, @@hostname AS server_host, @@port AS server_port');
$result = [
    'environment' => $app->environment(),
    'connection' => $connection->getName(),
    'driver' => $connection->getDriverName(),
    'database' => (string) ($row->database_name ?? ''),
    'server_host' => (string) ($row->server_host ?? ''),
    'server_port' => (string) ($row->server_port ?? ''),
];

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
'@

Set-Content -LiteralPath $ProjectRootFile -Value $ProjectRoot -Encoding ASCII
Set-Content -LiteralPath $RouterPath -Value $RouterCode -Encoding ASCII
Set-Content -LiteralPath $DatabaseProbePath -Value $DatabaseProbeCode -Encoding ASCII

$CacheOverridePath = Join-Path $RuntimeRoot ("config-cache-disabled-" + [guid]::NewGuid().ToString('N') + '.php')
$VerificationToken = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
Set-Content -LiteralPath $VerificationTokenFile -Value $VerificationToken -Encoding ASCII
$StdOutLog = Join-Path $RuntimeRoot ("server-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.out.log')
$StdErrLog = Join-Path $RuntimeRoot ("server-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.err.log')

$EnvironmentNames = @('DB_DATABASE', 'APP_CONFIG_CACHE')
$PreviousEnvironment = @{}
foreach ($Name in $EnvironmentNames) {
    $PreviousEnvironment[$Name] = [pscustomobject]@{
        Exists = Test-Path "Env:$Name"
        Value = [Environment]::GetEnvironmentVariable($Name, 'Process')
    }
}

$ServerProcess = $null
try {
    $env:DB_DATABASE = $ExpectedDatabase
    $env:APP_CONFIG_CACHE = $CacheOverridePath

    $ProbeOutput = & $PhpPath $DatabaseProbePath 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw 'Read-only database preflight failed. No Laravel server was started.'
    }

    try {
        $ProbeResult = (($ProbeOutput -join "`n") | ConvertFrom-Json -ErrorAction Stop)
    } catch {
        throw 'Read-only database preflight returned an unexpected result. No Laravel server was started.'
    }

    if ($ProbeResult.database -ne $ExpectedDatabase -or $ProbeResult.driver -ne 'mysql' -or $ProbeResult.connection -ne 'mysql') {
        throw "Read-only database preflight did not resolve to $ExpectedDatabase over MySQL. No Laravel server was started."
    }

    if ($ProbeResult.environment -ne 'local') {
        throw "Laravel reports environment '$($ProbeResult.environment)' instead of local. No Laravel server was started."
    }

    $ServerArguments = "-S 127.0.0.1:$Port -t `"$PublicRoot`" `"$RouterPath`""
    $ServerProcess = Start-Process -FilePath $PhpPath `
        -ArgumentList $ServerArguments `
        -WorkingDirectory $ProjectRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $StdOutLog `
        -RedirectStandardError $StdErrLog `
        -PassThru
} finally {
    foreach ($Name in $EnvironmentNames) {
        $Previous = $PreviousEnvironment[$Name]
        if ($Previous.Exists) {
            [Environment]::SetEnvironmentVariable($Name, $Previous.Value, 'Process')
        } else {
            [Environment]::SetEnvironmentVariable($Name, $null, 'Process')
        }
    }
}

$DatabaseResponsePath = Join-Path $RuntimeRoot ("database-check-" + [guid]::NewGuid().ToString('N') + '.json')
$DatabaseHeadersPath = Join-Path $RuntimeRoot ("database-check-" + [guid]::NewGuid().ToString('N') + '.headers')
$CurlPath = (Get-Command curl.exe -ErrorAction Stop).Source
$CurlExitCode = 1
for ($Attempt = 0; $Attempt -lt 20; $Attempt++) {
    if ($ServerProcess.HasExited) {
        break
    }

    try {
        & $CurlPath --silent --show-error --max-time 10 `
            --dump-header $DatabaseHeadersPath `
            --output $DatabaseResponsePath `
            --header "X-Bearly-Db-Verify: $VerificationToken" `
            "http://127.0.0.1:$Port/__bearly_local_db_check" 2>$null | Out-Null
        $CurlExitCode = $LASTEXITCODE
        if ($CurlExitCode -eq 0 -and (Test-Path -LiteralPath $DatabaseHeadersPath -PathType Leaf)) {
            break
        }
    } catch {
        # Retry until PHP's built-in server is ready to accept local requests.
    }

    Start-Sleep -Milliseconds 500
}

if ($CurlExitCode -ne 0 -or -not (Test-Path -LiteralPath $DatabaseHeadersPath -PathType Leaf)) {
    $ErrorMessage = if ($ServerProcess.HasExited) {
        "Laravel server process $($ServerProcess.Id) exited during startup. Check $StdErrLog."
    } else {
        "Laravel server did not return a login page in time. Check $StdErrLog."
    }
    throw $ErrorMessage
}

$DatabaseHeaderText = Get-Content -LiteralPath $DatabaseHeadersPath -Raw
$DatabaseStatusMatch = [regex]::Match($DatabaseHeaderText, '(?im)^HTTP/\d(?:\.\d)?\s+(\d{3})')
$DatabaseStatus = if ($DatabaseStatusMatch.Success) { [int] $DatabaseStatusMatch.Groups[1].Value } else { 0 }
try {
    $RuntimeProbe = Get-Content -LiteralPath $DatabaseResponsePath -Raw | ConvertFrom-Json -ErrorAction Stop
} catch {
    throw "Server started as PID $($ServerProcess.Id), but its database verification response was invalid. Check $StdErrLog."
}

$RuntimeDatabase = [string] $RuntimeProbe.database
$RuntimeConfigCached = [bool] $RuntimeProbe.configuration_cached
if ($DatabaseStatus -ne 200 -or $RuntimeDatabase -ne $ExpectedDatabase -or $RuntimeProbe.driver -ne 'mysql' -or $RuntimeConfigCached -or $RuntimeProbe.environment -ne 'local') {
    throw "Server started as PID $($ServerProcess.Id), but HTTP runtime verification did not match. HTTP=$DatabaseStatus, database=$RuntimeDatabase, driver=$($RuntimeProbe.driver), config_cached=$RuntimeConfigCached. Check $StdErrLog."
}

$LoginHeadersPath = Join-Path $RuntimeRoot ("login-check-" + [guid]::NewGuid().ToString('N') + '.headers')
$LoginBodyPath = Join-Path $RuntimeRoot ("login-check-" + [guid]::NewGuid().ToString('N') + '.html')
& $CurlPath --silent --show-error --max-time 10 `
    --dump-header $LoginHeadersPath `
    --output $LoginBodyPath `
    "http://127.0.0.1:$Port/login" 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "The local database verified, but the login page request failed. Check $StdErrLog."
}
$LoginHeaderText = Get-Content -LiteralPath $LoginHeadersPath -Raw
$LoginStatusMatch = [regex]::Match($LoginHeaderText, '(?im)^HTTP/\d(?:\.\d)?\s+(\d{3})')
$LoginTypeMatch = [regex]::Match($LoginHeaderText, '(?im)^Content-Type:\s*([^\r\n]+)')
$LoginStatus = if ($LoginStatusMatch.Success) { [int] $LoginStatusMatch.Groups[1].Value } else { 0 }
$LoginContentType = if ($LoginTypeMatch.Success) { $LoginTypeMatch.Groups[1].Value.Trim() } else { '' }
$LoginBodyLength = (Get-Item -LiteralPath $LoginBodyPath).Length
if ($LoginStatus -ne 200 -or $LoginContentType -notmatch '^text/html' -or $LoginBodyLength -le 0) {
    throw "The local database verified, but login validation returned HTTP $LoginStatus and Content-Type '$LoginContentType'. Check $StdErrLog."
}

Remove-Item -LiteralPath $VerificationTokenFile -Force

Write-Output "Laravel local server started: http://127.0.0.1:$Port"
Write-Output "Login page: HTTP $LoginStatus, $LoginContentType, body $LoginBodyLength bytes"
Write-Output "HTTP process database: $RuntimeDatabase (MySQL, local environment, uncached config)"
Write-Output "PID: $($ServerProcess.Id)"
Write-Output "Temporary router: $RouterPath"
Write-Output "Server error log: $StdErrLog"
