[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
param(
    [ValidateSet('Inspect', 'Provision')]
    [string] $Mode = 'Inspect',

    [string] $ConfirmTarget,

    [switch] $DefineOnly
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$script:BearlyMysqlExecutable = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe'
$script:BearlyMysqlServiceConfig = 'C:\ProgramData\MySQL\MySQL Server 8.0\my.ini'
$script:BearlyMysqlServiceData = 'C:\ProgramData\MySQL\MySQL Server 8.0\Data'
$script:BearlyMysqlServiceName = 'MySQL80'
$script:BearlyMysqlExpectedVersion = '8.0.43'
$script:BearlyMysqlMinFreeBytes = 5GB

function ConvertTo-BearlyCanonicalPath {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path)

    if ([string]::IsNullOrWhiteSpace($Path)) {
        throw 'A required path is blank.'
    }
    if (-not [System.IO.Path]::IsPathRooted($Path)) {
        throw "A path is not absolute: $Path"
    }
    if ($Path -match '(^|[\\/])\.\.([\\/]|$)') {
        throw "Path traversal is not allowed: $Path"
    }
    if ($Path.StartsWith('\\?\') -or $Path.StartsWith('\\.\') -or $Path.StartsWith('\\')) {
        throw 'UNC, device, and extended-length paths are not accepted for this local instance.'
    }

    $fullPath = [System.IO.Path]::GetFullPath($Path)
    $volumeRoot = [System.IO.Path]::GetPathRoot($fullPath)
    if ($fullPath.Length -gt $volumeRoot.Length) {
        return $fullPath.TrimEnd([char[]]@('\', '/'))
    }
    return $volumeRoot
}

function Test-BearlyPathWithin {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Candidate,
        [Parameter(Mandatory = $true)][string] $Parent
    )

    $candidatePath = ConvertTo-BearlyCanonicalPath $Candidate
    $parentPath = ConvertTo-BearlyCanonicalPath $Parent
    if ([string]::Equals($candidatePath, $parentPath, [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }
    $prefix = $parentPath.TrimEnd([char[]]@('\', '/')) + [System.IO.Path]::DirectorySeparatorChar
    return $candidatePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function Test-BearlyPathsOverlap {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $First,
        [Parameter(Mandatory = $true)][string] $Second
    )

    return (Test-BearlyPathWithin -Candidate $First -Parent $Second) -or
        (Test-BearlyPathWithin -Candidate $Second -Parent $First)
}

function Get-BearlyExpectedLayout {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $LocalAppData)

    $localPath = ConvertTo-BearlyCanonicalPath $LocalAppData
    $root = ConvertTo-BearlyCanonicalPath (Join-Path $localPath 'BearlyDisposable\mysql-8.0.43')
    return [pscustomobject]@{
        Root = $root
        ConfigDirectory = Join-Path $root 'config'
        DataDirectory = Join-Path $root 'data'
        LogDirectory = Join-Path $root 'logs'
        RuntimeDirectory = Join-Path $root 'run'
        TemporaryDirectory = Join-Path $root 'tmp'
        SecureFileDirectory = Join-Path $root 'files'
        CredentialDirectory = Join-Path $root 'credentials'
        InitializationConfig = Join-Path $root 'config\initialize.cnf'
        RuntimeConfig = Join-Path $root 'config\runtime.cnf'
        InitializationLog = Join-Path $root 'logs\initialize.err'
        RuntimeLog = Join-Path $root 'logs\mysql.err'
        PidFile = Join-Path $root 'run\mysql.pid'
        EncryptedRootCredential = Join-Path $root 'credentials\root-temporary-password.dpapi'
    }
}

function Assert-BearlyDisposablePathPolicy {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject] $Paths,
        [Parameter(Mandatory = $true)][string[]] $ProtectedPaths,
        [Parameter(Mandatory = $true)][string[]] $OneDriveRoots
    )

    $root = ConvertTo-BearlyCanonicalPath $Paths.TargetRoot
    $localAppData = ConvertTo-BearlyCanonicalPath $Paths.LocalAppData
    $expected = ConvertTo-BearlyCanonicalPath (Join-Path $localAppData 'BearlyDisposable\mysql-8.0.43')
    if (-not [string]::Equals($root, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The proposed root does not match the fixed LOCALAPPDATA disposable target.'
    }
    if (-not $Paths.NoReparseAncestors) {
        throw 'A path component is a reparse point or its status could not be verified.'
    }
    if (-not $Paths.RootEmptyOrAbsent) {
        throw 'The proposed root already contains data; automatic reuse or reinitialization is refused.'
    }

    foreach ($oneDriveRoot in $OneDriveRoots) {
        if (-not [string]::IsNullOrWhiteSpace($oneDriveRoot) -and (Test-BearlyPathsOverlap -First $root -Second $oneDriveRoot)) {
            throw 'The proposed root overlaps a configured OneDrive location.'
        }
    }
    if ($root -match '(?i)(^|[\\/])OneDrive(?:\s-[^\\/]*)?([\\/]|$)') {
        throw 'The proposed root contains a OneDrive path component.'
    }

    foreach ($protectedPath in $ProtectedPaths) {
        if ([string]::IsNullOrWhiteSpace($protectedPath)) {
            throw 'A protected MySQL path could not be resolved.'
        }
        if (Test-BearlyPathsOverlap -First $root -Second $protectedPath) {
            throw 'The proposed root overlaps the existing MySQL installation, configuration, or data directory.'
        }
    }

    $dataDirectory = ConvertTo-BearlyCanonicalPath $Paths.DataDirectory
    if (-not (Test-BearlyPathWithin -Candidate $dataDirectory -Parent $root)) {
        throw 'The proposed data directory escapes the disposable root.'
    }
    foreach ($configPath in $Paths.ConfigPaths) {
        if (-not (Test-BearlyPathWithin -Candidate $configPath -Parent $root)) {
            throw 'A proposed configuration path escapes the disposable root.'
        }
        if (Test-BearlyPathsOverlap -First $configPath -Second $dataDirectory) {
            throw 'A proposed configuration path overlaps the data directory.'
        }
        foreach ($protectedPath in $ProtectedPaths) {
            if (Test-BearlyPathsOverlap -First $configPath -Second $protectedPath) {
                throw 'A proposed configuration path overlaps an existing MySQL path.'
            }
        }
    }
}

function ConvertTo-BearlyOwnerKey {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Owner)

    $normalized = ($Owner -replace '/', '\').Trim().ToLowerInvariant()
    switch -Regex ($normalized) {
        '^(?:nt authority\\)?system$' { return 'sid:s-1-5-18' }
        '^(?:nt authority\\)?network service$' { return 'sid:s-1-5-20' }
        '^(?:nt authority\\)?local service$' { return 'sid:s-1-5-19' }
        default { return $normalized }
    }
}

function Test-BearlyListenerDescendsFromService {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][int] $ListenerPid,
        [Parameter(Mandatory = $true)][int] $ServicePid,
        [Parameter(Mandatory = $true)][psobject[]] $Processes,
        [Parameter(Mandatory = $true)][string] $ExpectedExecutable,
        [Parameter(Mandatory = $true)][string] $ExpectedOwner
    )

    $processById = @{}
    foreach ($process in $Processes) {
        if ($process.MetadataStatus -ne 'Readable') {
            throw 'Process metadata permission was denied or incomplete; ownership cannot be verified safely.'
        }
        $processById[[int]$process.ProcessId] = $process
    }
    if (-not $processById.ContainsKey($ListenerPid) -or -not $processById.ContainsKey($ServicePid)) {
        return $false
    }

    $listener = $processById[$ListenerPid]
    $service = $processById[$ServicePid]
    if ([string]::IsNullOrWhiteSpace($listener.ExecutablePath) -or [string]::IsNullOrWhiteSpace($listener.Owner) -or
        [string]::IsNullOrWhiteSpace($service.ExecutablePath) -or [string]::IsNullOrWhiteSpace($service.Owner)) {
        throw 'Process executable or owner metadata is unavailable; ownership cannot be verified safely.'
    }
    $expectedExe = ConvertTo-BearlyCanonicalPath $ExpectedExecutable
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $listener.ExecutablePath), $expectedExe, [StringComparison]::OrdinalIgnoreCase)) {
        return $false
    }
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $service.ExecutablePath), $expectedExe, [StringComparison]::OrdinalIgnoreCase)) {
        return $false
    }
    if ((ConvertTo-BearlyOwnerKey $service.Owner) -ne (ConvertTo-BearlyOwnerKey $ExpectedOwner) -or
        (ConvertTo-BearlyOwnerKey $listener.Owner) -ne (ConvertTo-BearlyOwnerKey $ExpectedOwner)) {
        return $false
    }

    $seen = @{}
    $currentPid = $ListenerPid
    for ($depth = 0; $depth -lt 64; $depth++) {
        if ($currentPid -eq $ServicePid) { return $true }
        if ($seen.ContainsKey($currentPid) -or -not $processById.ContainsKey($currentPid)) { return $false }
        $seen[$currentPid] = $true
        $current = $processById[$currentPid]
        if ($null -eq $current.ParentProcessId -or [int]$current.ParentProcessId -le 0) { return $false }
        $currentPid = [int]$current.ParentProcessId
    }
    return $false
}

function Assert-BearlyPreflightFacts {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject] $Facts,
        [ValidateSet('Inspect', 'Provision')][string] $Mode = 'Inspect'
    )

    if ($Facts.ProcessMetadataStatus -ne 'Readable') {
        throw 'Safety failure: Windows process metadata is unavailable or permission denied. No identity mismatch is inferred.'
    }
    if ($Mode -eq 'Inspect' -and -not $Facts.IsElevated) {
        throw 'Read-only process-ownership inspection must run in an elevated PowerShell session.'
    }
    if ($Mode -eq 'Provision' -and $Facts.IsElevated) {
        throw 'Provisioning must run from a non-elevated PowerShell session. Recheck process metadata; stop if access is denied.'
    }
    if ([string]::IsNullOrWhiteSpace($Facts.CurrentAccount)) {
        throw 'The current Windows account could not be identified.'
    }
    if ($Facts.ServiceName -ne 'MySQL80' -or $Facts.ServiceState -ne 'Running') {
        throw 'The expected MySQL80 service is not running or the service identity is ambiguous.'
    }
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $Facts.ServiceExecutablePath),
            (ConvertTo-BearlyCanonicalPath $script:BearlyMysqlExecutable), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'MySQL80 does not use the expected MySQL executable.'
    }
    $expectedVersionPattern = '^' + [regex]::Escape($script:BearlyMysqlExpectedVersion) + '(?:\.|$)'
    if ($Facts.ServiceVersion -notmatch $expectedVersionPattern) {
        throw 'The installed MySQL executable is not the expected 8.0.43 version.'
    }
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $Facts.ServiceConfigPath),
            (ConvertTo-BearlyCanonicalPath $script:BearlyMysqlServiceConfig), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The MySQL80 option-file path is missing or unexpected.'
    }
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $Facts.ServiceDataDirectory),
            (ConvertTo-BearlyCanonicalPath $script:BearlyMysqlServiceData), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The MySQL80 configured data directory is missing or unexpected.'
    }
    if ([int]$Facts.ServiceProcessId -le 0 -or [string]::IsNullOrWhiteSpace($Facts.ServiceOwner)) {
        throw 'The MySQL80 service process identity is incomplete.'
    }
    if ($Facts.Port3307Status -ne 'Free') {
        if ($Facts.Port3307Status -eq 'AccessDenied') {
            throw 'Safety failure: port 3307 listener status could not be read.'
        }
        throw 'Port 3307 is already occupied.'
    }

    foreach ($port in @(3306, 33060)) {
        $portListeners = @($Facts.Listeners | Where-Object { [int]$_.LocalPort -eq $port })
        if ($portListeners.Count -eq 0) {
            throw "The expected MySQL80 listener on port $port was not found."
        }
        foreach ($listener in $portListeners) {
            $owned = Test-BearlyListenerDescendsFromService -ListenerPid ([int]$listener.OwningProcess) `
                -ServicePid ([int]$Facts.ServiceProcessId) -Processes $Facts.Processes `
                -ExpectedExecutable $script:BearlyMysqlExecutable -ExpectedOwner $Facts.ServiceOwner
            if (-not $owned) {
                throw "The listener on port $port is not owned by the verified MySQL80 service process tree."
            }
        }
    }

    if ($Facts.UnexpectedDisposableProcess -or $Facts.UnexpectedDisposableService) {
        throw 'An unexpected MySQL server process or service exists; target isolation cannot be proven safely.'
    }
    if (-not $Facts.ParentAclSafe) {
        throw 'The nearest existing parent directory does not have sufficiently private, verifiable permissions.'
    }
    if ([long]$Facts.AvailableBytes -lt [long]$script:BearlyMysqlMinFreeBytes) {
        throw 'The target volume has less than the required 5 GiB free space.'
    }

    Assert-BearlyDisposablePathPolicy -Paths $Facts.Paths -ProtectedPaths $Facts.ProtectedPaths -OneDriveRoots $Facts.OneDriveRoots
}

function Assert-BearlyTargetConfirmation {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string] $Supplied,
        [Parameter(Mandatory = $true)][string] $TargetRoot
    )

    $expected = 'INITIALIZE|127.0.0.1:3307|' + (ConvertTo-BearlyCanonicalPath $TargetRoot)
    if (-not [string]::Equals($Supplied, $expected, [StringComparison]::Ordinal)) {
        throw "Provisioning requires the exact target confirmation: $expected"
    }
}

function Invoke-BearlyWorkflow {
    [CmdletBinding()]
    param(
        [ValidateSet('Inspect', 'Provision')][string] $SelectedMode,
        [Parameter(Mandatory = $true)][psobject] $Facts,
        [Parameter(Mandatory = $true)][string] $TargetRoot,
        [string] $Confirmation,
        [bool] $ShouldProceed = $true,
        [Parameter(Mandatory = $true)][scriptblock] $ProvisionAction
    )

    Assert-BearlyPreflightFacts -Facts $Facts -Mode $SelectedMode
    if ($SelectedMode -eq 'Inspect') {
        return New-BearlySanitizedPlan -Paths $Facts.Paths
    }
    Assert-BearlyTargetConfirmation -Supplied $Confirmation -TargetRoot $TargetRoot
    if (-not $ShouldProceed) {
        return New-BearlySanitizedPlan -Paths $Facts.Paths
    }
    return (& $ProvisionAction $Facts)
}

function Get-BearlyCurrentIdentity {
    [CmdletBinding()]
    param()

    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object System.Security.Principal.WindowsPrincipal($identity)
    return [pscustomobject]@{
        Name = $identity.Name
        Sid = $identity.User.Value
        IsElevated = $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
    }
}

function Get-BearlyCimProcess {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][int] $ProcessId)

    try {
        $rows = @(Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = $ProcessId" -ErrorAction Stop)
        if ($rows.Count -ne 1) {
            throw "Process $ProcessId disappeared or could not be queried."
        }
        return $rows[0]
    }
    catch {
        throw "Safety failure: process metadata query failed for a current PID. Permission denied is not treated as an identity mismatch. $($_.Exception.Message)"
    }
}

function Get-BearlyCimOwner {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject] $Process)

    try {
        $result = Invoke-CimMethod -InputObject $Process -MethodName GetOwner -ErrorAction Stop
        if ([int]$result.ReturnValue -ne 0 -or [string]::IsNullOrWhiteSpace($result.User)) {
            throw 'GetOwner returned no owner.'
        }
        if ([string]::IsNullOrWhiteSpace($result.Domain)) { return [string]$result.User }
        return ([string]$result.Domain + '\' + [string]$result.User)
    }
    catch {
        throw "Safety failure: process owner metadata query was denied or incomplete. $($_.Exception.Message)"
    }
}

function Get-BearlyOneDriveRoots {
    [CmdletBinding()]
    param()

    $roots = New-Object System.Collections.Generic.List[string]
    foreach ($name in @('OneDrive', 'OneDriveCommercial', 'OneDriveConsumer')) {
        $value = [Environment]::GetEnvironmentVariable($name)
        if (-not [string]::IsNullOrWhiteSpace($value)) { $roots.Add($value) }
    }
    if (-not [string]::IsNullOrWhiteSpace($env:USERPROFILE)) {
        $roots.Add((Join-Path $env:USERPROFILE 'OneDrive'))
        $roots.Add((Join-Path $env:USERPROFILE 'OneDrive - Organization'))
    }
    $accountsPath = 'HKCU:\Software\Microsoft\OneDrive\Accounts'
    $accountsKeyExists = $false
    try {
        # Read the Accounts container only to distinguish a missing key from a
        # registry access failure. It is not an account and has no UserFolder.
        $null = Get-Item -LiteralPath $accountsPath -ErrorAction Stop
        $accountsKeyExists = $true
    }
    catch {
        $isMissingAccountsKey = $_.CategoryInfo.Category -eq [System.Management.Automation.ErrorCategory]::ObjectNotFound -and
            $_.FullyQualifiedErrorId -match '(?i)(PathNotFound|ItemNotFound)'
        if (-not $isMissingAccountsKey) {
            throw "Safety failure: registered OneDrive account locations could not be inspected. $($_.Exception.Message)"
        }
    }

    if ($accountsKeyExists) {
        try {
            $accountKeys = @(Get-ChildItem -LiteralPath $accountsPath -ErrorAction Stop)
            foreach ($accountKey in $accountKeys) {
                $nameProperty = $accountKey.PSObject.Properties['PSChildName']
                if ($null -eq $nameProperty -or [string]::IsNullOrWhiteSpace([string]$nameProperty.Value)) {
                    throw 'A OneDrive registry child could not be classified as an account entry.'
                }

                $accountName = [string]$nameProperty.Value
                # Current OneDrive account subkeys are Personal and BusinessN.
                # Unknown direct children are not silently ignored: they may
                # represent a configured account whose root is not understood.
                if ($accountName -notmatch '^(?i:Personal|Business[0-9]+)$') {
                    throw "An unrecognized OneDrive Accounts entry '$accountName' prevents verifying all registered sync roots."
                }

                # Build the path from the validated account name instead of
                # trusting a provider-supplied PSPath value.
                $accountPath = Join-Path $accountsPath $accountName
                $account = Get-ItemProperty -LiteralPath $accountPath -ErrorAction Stop
                $userFolderProperty = $account.PSObject.Properties['UserFolder']
                if ($null -eq $userFolderProperty) {
                    throw "Configured OneDrive account '$accountName' has no UserFolder value."
                }

                $userFolder = [string]$userFolderProperty.Value
                if ([string]::IsNullOrWhiteSpace($userFolder)) {
                    throw "Configured OneDrive account '$accountName' has an empty UserFolder value."
                }

                try {
                    $roots.Add((ConvertTo-BearlyCanonicalPath -Path $userFolder))
                }
                catch {
                    throw "Configured OneDrive account '$accountName' has an invalid UserFolder path. $($_.Exception.Message)"
                }
            }
        }
        catch {
            throw "Safety failure: registered OneDrive root locations could not be read or verified. $($_.Exception.Message)"
        }
    }
    return @($roots.ToArray() | Sort-Object -Unique)
}

function Test-BearlyNoReparseAncestors {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path)

    try {
        $full = ConvertTo-BearlyCanonicalPath $Path
        $root = [System.IO.Path]::GetPathRoot($full)
        $relative = $full.Substring($root.Length)
        $current = $root
        $rootItem = Get-Item -LiteralPath $root -Force -ErrorAction Stop
        if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { return $false }
        foreach ($segment in @($relative -split '[\\/]+' | Where-Object { $_ })) {
            $current = Join-Path $current $segment
            if (Test-Path -LiteralPath $current) {
                $item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
                if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { return $false }
            }
            else { break }
        }
        return $true
    }
    catch { return $false }
}

function Test-BearlyParentAcl {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $UserSid
    )

    try {
        $probe = ConvertTo-BearlyCanonicalPath $Path
        while (-not (Test-Path -LiteralPath $probe -PathType Container)) {
            $parent = Split-Path -Parent $probe
            if ([string]::IsNullOrWhiteSpace($parent) -or $parent -eq $probe) { return $false }
            $probe = $parent
        }
        $acl = Get-Acl -LiteralPath $probe -ErrorAction Stop
        $owner = (New-Object System.Security.Principal.NTAccount($acl.Owner)).Translate([System.Security.Principal.SecurityIdentifier]).Value
        if ($owner -ne $UserSid) { return $false }

        $broadSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')
        $writeAllowedSids = @($UserSid, 'S-1-5-18', 'S-1-5-32-544')
        $writeMask = [int][System.Security.AccessControl.FileSystemRights]::Modify -bor
            [int][System.Security.AccessControl.FileSystemRights]::FullControl -bor
            [int][System.Security.AccessControl.FileSystemRights]::WriteData -bor
            [int][System.Security.AccessControl.FileSystemRights]::AppendData -bor
            [int][System.Security.AccessControl.FileSystemRights]::CreateFiles
        $userWrite = $false
        $rules = $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])
        foreach ($rule in $rules) {
            if ($rule.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow) { continue }
            $sid = $rule.IdentityReference.Value
            if ((([int]$rule.FileSystemRights -band $writeMask) -ne 0) -and (($sid -in $broadSids) -or ($sid -notin $writeAllowedSids))) { return $false }
            if ($sid -eq $UserSid -and (([int]$rule.FileSystemRights -band $writeMask) -ne 0)) { $userWrite = $true }
        }
        return $userWrite
    }
    catch { return $false }
}

function Get-BearlyIniValues {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Required MySQL option file is missing: $Path" }
    $values = @{}
    $section = ''
    foreach ($line in Get-Content -LiteralPath $Path -ErrorAction Stop) {
        $trimmed = $line.Trim()
        if ($trimmed -match '^\s*[#;]') { continue }
        if ($trimmed -match '^\[(?<section>[^\]]+)\]\s*$') {
            $section = $Matches.section.Trim().ToLowerInvariant()
            continue
        }
        if ($trimmed -match '^!include(?:dir)?\b') {
            throw 'The existing MySQL option file contains an include directive; effective settings are ambiguous.'
        }
        if ($section -notin @('mysqld', 'server', 'mysqld-8.0')) { continue }
        if ($trimmed -match '^(?<key>[A-Za-z0-9_-]+)\s*=\s*(?<value>.*?)\s*$') {
            $key = $Matches.key.Replace('_', '-').ToLowerInvariant()
            $value = $Matches.value.Trim()
            if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[$value.Length - 1] -eq '"') -or ($value[0] -eq "'" -and $value[$value.Length - 1] -eq "'"))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            if (-not $values.ContainsKey($key)) { $values[$key] = New-Object System.Collections.Generic.List[string] }
            $values[$key].Add($value)
        }
    }
    return $values
}

function Get-BearlyUniqueIniValue {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][hashtable] $Values,
        [Parameter(Mandatory = $true)][string] $Key
    )

    $normalizedKey = $Key.Replace('_', '-').ToLowerInvariant()
    if (-not $Values.ContainsKey($normalizedKey) -or $Values[$normalizedKey].Count -eq 0) {
        throw "The MySQL80 configuration must explicitly define '$Key'; no default is used."
    }
    $distinctValues = @($Values[$normalizedKey] | Sort-Object -Unique)
    if ($distinctValues.Count -ne 1) { throw "The MySQL80 configuration defines conflicting '$Key' values." }
    return [string]$distinctValues[0]
}

function Get-BearlyServiceExecutableAndConfig {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $PathName)

    $exeMatch = [regex]::Match($PathName, '^\s*(?:"(?<quoted>[^"]+)"|(?<bare>\S+))')
    if (-not $exeMatch.Success) { throw 'The MySQL80 service executable path is ambiguous.' }
    $exe = if ($exeMatch.Groups['quoted'].Success) { $exeMatch.Groups['quoted'].Value } else { $exeMatch.Groups['bare'].Value }
    $configMatch = [regex]::Match($PathName, '(?i)--defaults-file(?:\s*=\s*|\s+)(?:"(?<quoted>[^"]+)"|(?<bare>[^\s]+))')
    if (-not $configMatch.Success) { throw 'The MySQL80 service does not specify an explicit --defaults-file.' }
    $config = if ($configMatch.Groups['quoted'].Success) { $configMatch.Groups['quoted'].Value } else { $configMatch.Groups['bare'].Value }
    return [pscustomobject]@{ Executable = $exe; Config = $config }
}

function Get-BearlyServiceProcessTreePids {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][int] $ListenerPid, [Parameter(Mandatory = $true)][int] $ServicePid)

    $pids = New-Object System.Collections.Generic.List[int]
    $current = $ListenerPid
    $seen = @{}
    for ($depth = 0; $depth -lt 64; $depth++) {
        if ($seen.ContainsKey($current)) { throw 'A process ancestry cycle was detected.' }
        $seen[$current] = $true
        $pids.Add($current)
        if ($current -eq $ServicePid) { return $pids.ToArray() }
        $process = Get-BearlyCimProcess -ProcessId $current
        $current = [int]$process.ParentProcessId
        if ($current -le 0) { break }
    }
    throw "Listener PID $ListenerPid is not a verified descendant of service PID $ServicePid."
}

function Get-BearlyCurrentListeners {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][int[]] $Ports)

    try {
        $rows = @(Get-NetTCPConnection -State Listen -LocalPort $Ports -ErrorAction Stop)
        return @($rows | ForEach-Object { [pscustomobject]@{ LocalPort = [int]$_.LocalPort; OwningProcess = [int]$_.OwningProcess; LocalAddress = [string]$_.LocalAddress } })
    }
    catch {
        throw "Safety failure: TCP listener metadata query failed or was denied. $($_.Exception.Message)"
    }
}

function Get-BearlyPreflightFacts {
    [CmdletBinding()]
    param()

    $identity = Get-BearlyCurrentIdentity
    if ([string]::IsNullOrWhiteSpace($env:LOCALAPPDATA)) { throw 'LOCALAPPDATA is not set; no fallback target is permitted.' }
    $layout = Get-BearlyExpectedLayout -LocalAppData $env:LOCALAPPDATA

    try {
        $services = @(Get-CimInstance -ClassName Win32_Service -Filter "Name = '$script:BearlyMysqlServiceName'" -ErrorAction Stop)
        if ($services.Count -ne 1) { throw 'Expected exactly one MySQL80 service.' }
        $allServices = @(Get-CimInstance -ClassName Win32_Service -ErrorAction Stop)
    }
    catch {
        throw "Safety failure: Windows service metadata query was denied or incomplete. $($_.Exception.Message)"
    }
    $service = $services[0]
    $serviceInfo = Get-BearlyServiceExecutableAndConfig -PathName ([string]$service.PathName)
    $executable = ConvertTo-BearlyCanonicalPath $serviceInfo.Executable
    $serviceConfig = ConvertTo-BearlyCanonicalPath $serviceInfo.Config
    $ini = Get-BearlyIniValues -Path $serviceConfig
    $configuredData = ConvertTo-BearlyCanonicalPath (Get-BearlyUniqueIniValue -Values $ini -Key 'datadir')
    $configuredPort = Get-BearlyUniqueIniValue -Values $ini -Key 'port'
    if ($configuredPort -ne '3306') { throw 'MySQL80 does not explicitly configure port 3306.' }
    $binaryVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($executable).ProductVersion
    if ([string]::IsNullOrWhiteSpace($binaryVersion)) { $binaryVersion = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($executable).FileVersion }

    try {
        $mysqldProcesses = @(Get-CimInstance -ClassName Win32_Process -Filter "Name = 'mysqld.exe'" -ErrorAction Stop)
    }
    catch {
        throw "Safety failure: mysqld process metadata query was denied. $($_.Exception.Message)"
    }
    $listeners = Get-BearlyCurrentListeners -Ports @(3306, 3307, 33060)
    $port3307 = @($listeners | Where-Object { $_.LocalPort -eq 3307 })
    $port3307Status = if ($port3307.Count -eq 0) { 'Free' } else { 'Occupied' }
    $servicePid = [int]$service.ProcessId
    $processRecords = New-Object System.Collections.Generic.List[object]
    $listenerPids = @($listeners | Where-Object { $_.LocalPort -in @(3306, 33060) } | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($listenerPid in $listenerPids) {
        foreach ($processId in (Get-BearlyServiceProcessTreePids -ListenerPid ([int]$listenerPid) -ServicePid $servicePid)) {
            if (@($processRecords | Where-Object { $_.ProcessId -eq $processId }).Count -gt 0) { continue }
            $cimProcess = Get-BearlyCimProcess -ProcessId ([int]$processId)
            $owner = ''
            if ($processId -eq $listenerPid -or $processId -eq $servicePid) { $owner = Get-BearlyCimOwner -Process $cimProcess }
            $processRecords.Add([pscustomobject]@{
                ProcessId = [int]$cimProcess.ProcessId
                ParentProcessId = [int]$cimProcess.ParentProcessId
                ExecutablePath = [string]$cimProcess.ExecutablePath
                Owner = $owner
                MetadataStatus = if ([string]::IsNullOrWhiteSpace($cimProcess.ExecutablePath)) { 'AccessDenied' } else { 'Readable' }
            })
        }
    }
    $serviceOwner = Get-BearlyCimOwner -Process (Get-BearlyCimProcess -ProcessId $servicePid)

    $unexpectedProcess = $false
    $serviceTree = @($processRecords | Select-Object -ExpandProperty ProcessId -Unique)
    foreach ($process in $mysqldProcesses) {
        if ([string]::IsNullOrWhiteSpace($process.ExecutablePath) -or $null -eq $process.CommandLine) {
            throw 'Safety failure: a mysqld process has inaccessible path or command-line metadata.'
        }
        if ($serviceTree -contains [int]$process.ProcessId) { continue }
        # Fail closed on any other server process. Without connecting to it,
        # an external option file or persisted setting could hide its datadir.
        $unexpectedProcess = $true
    }
    $unexpectedService = $false
    foreach ($candidateService in $allServices) {
        if ([string]$candidateService.Name -eq $script:BearlyMysqlServiceName) { continue }
        $pathName = [string]$candidateService.PathName
        if ($pathName -match '(?i)mysqld(?:\.exe)?' -or
            $pathName.IndexOf($layout.Root, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
            $pathName -match '(?i)--port(?:\s*=\s*|\s+)3307\b') { $unexpectedService = $true }
    }

    $protectedPaths = @($script:BearlyMysqlExecutable, $script:BearlyMysqlServiceConfig, $script:BearlyMysqlServiceData,
        (Split-Path -Parent (Split-Path -Parent $script:BearlyMysqlExecutable)))
    $configPaths = @($layout.InitializationConfig, $layout.RuntimeConfig)
    $pathObject = [pscustomobject]@{
        TargetRoot = $layout.Root
        LocalAppData = $env:LOCALAPPDATA
        DataDirectory = $layout.DataDirectory
        ConfigPaths = $configPaths
        NoReparseAncestors = (Test-BearlyNoReparseAncestors -Path $layout.Root)
        RootEmptyOrAbsent = $true
    }
    if (Test-Path -LiteralPath $layout.Root) {
        $rootItem = Get-Item -LiteralPath $layout.Root -Force -ErrorAction Stop
        $pathObject.RootEmptyOrAbsent = $rootItem.PSIsContainer -and @((Get-ChildItem -LiteralPath $layout.Root -Force -ErrorAction Stop)).Count -eq 0
    }
    $drive = [System.IO.Path]::GetPathRoot($layout.Root)
    $availableBytes = (New-Object System.IO.DriveInfo($drive)).AvailableFreeSpace
    $parentAclSafe = Test-BearlyParentAcl -Path (Split-Path -Parent $layout.Root) -UserSid $identity.Sid

    $oneDriveRoots = Get-BearlyOneDriveRoots
    $facts = [pscustomobject]@{
        CurrentAccount = $identity.Name
        IsElevated = $identity.IsElevated
        ProcessMetadataStatus = 'Readable'
        ServiceName = [string]$service.Name
        ServiceState = [string]$service.State
        ServiceProcessId = $servicePid
        ServiceOwner = $serviceOwner
        ServiceExecutablePath = $executable
        ServiceVersion = [string]$binaryVersion
        ServiceConfigPath = $serviceConfig
        ServiceDataDirectory = $configuredData
        Port3307Status = $port3307Status
        Listeners = $listeners
        Processes = $processRecords.ToArray()
        UnexpectedDisposableProcess = $unexpectedProcess
        UnexpectedDisposableService = $unexpectedService
        ParentAclSafe = $parentAclSafe
        AvailableBytes = [long]$availableBytes
        Paths = $pathObject
        ProtectedPaths = $protectedPaths
        OneDriveRoots = $oneDriveRoots
    }
    return $facts
}

function New-BearlySanitizedPlan {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject] $Paths)

    return [pscustomobject]@{
        Mode = 'Read-only plan'
        Service = 'MySQL80 remains running and unchanged'
        ExistingEndpoint = 'Current ownership verified for MySQL80 TCP listeners on ports 3306 and 33060'
        ProposedEndpoint = '127.0.0.1:3307 (TCP only, loopback binding)'
        MySQLVersion = '8.0.43'
        Root = $Paths.TargetRoot
        DataDirectory = $Paths.DataDirectory
        InitializationConfig = $Paths.ConfigPaths[0]
        RuntimeConfig = $Paths.ConfigPaths[1]
        Logs = 'Dedicated logs directory under the proposed root'
        PIDFile = Join-Path $Paths.TargetRoot 'run\mysql.pid'
        Credentials = 'DPAPI-encrypted current-user file; temporary plaintext option file only during a separately approved client operation'
        MutationPerformed = $false
        DatabaseConnectionAttempted = $false
    }
}

function New-BearlyServerId {
    [CmdletBinding()]
    param([uint32] $Avoid = 1)

    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $bytes = New-Object byte[] 4
        do {
            $generator.GetBytes($bytes)
            $candidate = [BitConverter]::ToUInt32($bytes, 0)
        } while ($candidate -eq 0 -or $candidate -eq $Avoid)
        return $candidate
    }
    finally { $generator.Dispose() }
}

function Set-BearlyPrivateAcl {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path, [switch] $File)

    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $acl = New-Object System.Security.AccessControl.DirectorySecurity
    if ($File) { $acl = New-Object System.Security.AccessControl.FileSecurity }
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($identity.User)
    $inheritance = [System.Security.AccessControl.InheritanceFlags]::None
    if (-not $File) { $inheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit }
    $accounts = @($identity.User, (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')), (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    foreach ($account in $accounts) {
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($account,
            [System.Security.AccessControl.FileSystemRights]::FullControl, $inheritance,
            [System.Security.AccessControl.PropagationFlags]::None, [System.Security.AccessControl.AccessControlType]::Allow)
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl -ErrorAction Stop
}

function ConvertTo-BearlyOptionValue {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Value)

    return '"' + $Value.Replace('"', '\"') + '"'
}

function New-BearlyMySqlConfigurationText {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject] $Layout, [Parameter(Mandatory = $true)][uint32] $ServerId)

    $basedir = 'C:/Program Files/MySQL/MySQL Server 8.0'
    $data = $Layout.DataDirectory.Replace('\', '/')
    $logs = $Layout.LogDirectory.Replace('\', '/')
    $run = $Layout.RuntimeDirectory.Replace('\', '/')
    $temp = $Layout.TemporaryDirectory.Replace('\', '/')
    $files = $Layout.SecureFileDirectory.Replace('\', '/')
    $baseOptions = @(
        '[mysqld]'
        "basedir=$(ConvertTo-BearlyOptionValue $basedir)"
        "datadir=$(ConvertTo-BearlyOptionValue $data)"
        'bind-address=127.0.0.1'
        'port=3307'
        'mysqlx=OFF'
        'local_infile=OFF'
        'named_pipe=OFF'
        'shared_memory=OFF'
        "server-id=$ServerId"
        "secure-file-priv=$(ConvertTo-BearlyOptionValue $files)"
        "tmpdir=$(ConvertTo-BearlyOptionValue $temp)"
        "pid-file=$(ConvertTo-BearlyOptionValue "$run/mysql.pid")"
    )
    # MySQL documents that --initialize should receive only directory-location
    # options. Keep network/runtime settings exclusively in runtime.cnf.
    $init = @('[mysqld]', "basedir=$(ConvertTo-BearlyOptionValue $basedir)", "datadir=$(ConvertTo-BearlyOptionValue $data)") -join "`r`n"
    $runtime = @($baseOptions + "log-error=$(ConvertTo-BearlyOptionValue "$logs/mysql.err")") -join "`r`n"
    return [pscustomobject]@{ Initialization = $init + "`r`n"; Runtime = $runtime + "`r`n" }
}

function ConvertTo-BearlyWindowsArgument {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Argument)

    return '"' + ($Argument -replace '(\\*)"', '$1$1\"' -replace '(\\+)$', '$1$1') + '"'
}

function Write-BearlyCredentialFile {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path, [Parameter(Mandatory = $true)][securestring] $Secret)

    $encrypted = ConvertFrom-SecureString -SecureString $Secret
    [System.IO.File]::WriteAllText($Path, $encrypted, (New-Object System.Text.UTF8Encoding($false)))
    Set-BearlyPrivateAcl -Path $Path -File
}

function Protect-BearlyInitializationLog {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return }
    $text = [System.IO.File]::ReadAllText($Path)
    $redacted = [regex]::Replace($text, '(?im)^(.*temporary password is generated for root@localhost:\s*).+$', '$1[REDACTED]')
    if ($redacted -ne $text) {
        [System.IO.File]::WriteAllText($Path, $redacted, (New-Object System.Text.UTF8Encoding($false)))
    }
}

function Invoke-BearlyMysqlInitialize {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Executable,
        [Parameter(Mandatory = $true)][string] $InitializationConfig,
        [Parameter(Mandatory = $true)][string] $DataDirectory,
        [Parameter(Mandatory = $true)][string] $InitializationLog,
        [Parameter(Mandatory = $true)][string] $CredentialPath
    )

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $Executable
    $startInfo.Arguments = (ConvertTo-BearlyWindowsArgument ("--defaults-file=$InitializationConfig")) + ' --initialize'
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = $null
    $stdout = ''
    $stderr = ''
    $plainPassword = $null
    $securePassword = $null
    try {
        $process = [System.Diagnostics.Process]::Start($startInfo)
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $stdout = $stdoutTask.Result
        $stderr = $stderrTask.Result
        $dataLogs = @()
        if (Test-Path -LiteralPath $DataDirectory -PathType Container) {
            $dataLogs = @(Get-ChildItem -LiteralPath $DataDirectory -Filter '*.err' -File -Force -ErrorAction Stop)
        }
        $log = (($dataLogs | ForEach-Object { [System.IO.File]::ReadAllText($_.FullName) }) -join "`n")
        $combined = $log + "`n" + $stdout + "`n" + $stderr
        $match = [regex]::Match($combined, '(?im)temporary password is generated for root@localhost:\s*(\S+)')
        $sanitizedDiagnostics = [regex]::Replace($combined, '(?im)^(.*temporary password is generated for root@localhost:\s*).+$', '$1[REDACTED]')
        if ($process.ExitCode -ne 0) {
            if (-not [string]::IsNullOrWhiteSpace($sanitizedDiagnostics)) {
                [System.IO.File]::WriteAllText($InitializationLog, $sanitizedDiagnostics, (New-Object System.Text.UTF8Encoding($false)))
                Set-BearlyPrivateAcl -Path $InitializationLog -File
            }
            foreach ($dataLog in $dataLogs) { Protect-BearlyInitializationLog -Path $dataLog.FullName }
            throw "MySQL secure initialization failed with exit code $($process.ExitCode). No log lines or credentials were emitted. The partial directory is preserved; do not rerun initialization."
        }
        if (-not $match.Success) {
            throw 'MySQL initialization did not expose the expected temporary root credential record. The partial directory is preserved; do not rerun initialization.'
        }
        $plainPassword = $match.Groups[1].Value.TrimEnd('.', ',', ';')
        $securePassword = ConvertTo-SecureString -String $plainPassword -AsPlainText -Force
        Write-BearlyCredentialFile -Path $CredentialPath -Secret $securePassword
        if (-not [string]::IsNullOrWhiteSpace($sanitizedDiagnostics)) {
            [System.IO.File]::WriteAllText($InitializationLog, $sanitizedDiagnostics, (New-Object System.Text.UTF8Encoding($false)))
            Set-BearlyPrivateAcl -Path $InitializationLog -File
        }
        foreach ($dataLog in $dataLogs) { Protect-BearlyInitializationLog -Path $dataLog.FullName }
        if (-not (Test-Path -LiteralPath (Join-Path $DataDirectory 'auto.cnf') -PathType Leaf)) {
            throw 'MySQL initialization returned success but did not create the expected server identity file. The partial directory is preserved.'
        }
    }
    catch {
        if (Test-Path -LiteralPath $InitializationLog -PathType Leaf) {
            try { Protect-BearlyInitializationLog -Path $InitializationLog } catch { }
        }
        if (Test-Path -LiteralPath $DataDirectory -PathType Container) {
            foreach ($dataLog in @(Get-ChildItem -LiteralPath $DataDirectory -Filter '*.err' -File -Force -ErrorAction SilentlyContinue)) {
                try { Protect-BearlyInitializationLog -Path $dataLog.FullName } catch { }
            }
        }
        throw $_
    }
    finally {
        if ($null -ne $process) { $process.Dispose() }
        $stdout = $null
        $stderr = $null
        $plainPassword = $null
        if ($null -ne $securePassword) { $securePassword.Dispose() }
    }
}

function Invoke-BearlyProvision {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject] $Facts)

    $freshFacts = Get-BearlyPreflightFacts
    Assert-BearlyPreflightFacts -Facts $freshFacts -Mode 'Provision'
    if (-not [string]::Equals((ConvertTo-BearlyCanonicalPath $freshFacts.Paths.TargetRoot),
            (ConvertTo-BearlyCanonicalPath $Facts.Paths.TargetRoot), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The disposable target changed during preflight; no files were changed.'
    }
    $Facts = $freshFacts
    $layout = Get-BearlyExpectedLayout -LocalAppData $env:LOCALAPPDATA
    if (Test-Path -LiteralPath $layout.Root) {
        $entries = @(Get-ChildItem -LiteralPath $layout.Root -Force -ErrorAction Stop)
        if ($entries.Count -ne 0) { throw 'The disposable root is no longer empty. No files were changed.' }
    }
    $ini = Get-BearlyIniValues -Path $Facts.ServiceConfigPath
    $existingServerId = [uint32]1
    if ($ini.ContainsKey('server-id')) {
        if ($ini['server-id'].Count -ne 1 -or $ini['server-id'][0] -notmatch '^\d+$') {
            throw 'MySQL80 has an ambiguous server-id configuration; provisioning is refused.'
        }
        $serverIdText = [string]$ini['server-id'][0]
        $parsedServerId = [uint64]0
        if (-not [uint64]::TryParse($serverIdText, [ref]$parsedServerId) -or $parsedServerId -gt [uint64][uint32]::MaxValue) {
            throw 'MySQL80 server-id is outside the supported range; provisioning is refused.'
        }
        $existingServerId = [uint32]$parsedServerId
    }
    $serverId = New-BearlyServerId -Avoid $existingServerId

    $directories = @($layout.Root, $layout.ConfigDirectory, $layout.DataDirectory, $layout.LogDirectory,
        $layout.RuntimeDirectory, $layout.TemporaryDirectory, $layout.SecureFileDirectory, $layout.CredentialDirectory)
    foreach ($directory in $directories) {
        if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -ErrorAction Stop | Out-Null }
        Set-BearlyPrivateAcl -Path $directory
    }
    $configuration = New-BearlyMySqlConfigurationText -Layout $layout -ServerId $serverId
    [System.IO.File]::WriteAllText($layout.InitializationConfig, $configuration.Initialization, (New-Object System.Text.UTF8Encoding($false)))
    [System.IO.File]::WriteAllText($layout.RuntimeConfig, $configuration.Runtime, (New-Object System.Text.UTF8Encoding($false)))
    Set-BearlyPrivateAcl -Path $layout.InitializationConfig -File
    Set-BearlyPrivateAcl -Path $layout.RuntimeConfig -File

    Invoke-BearlyMysqlInitialize -Executable $script:BearlyMysqlExecutable -InitializationConfig $layout.InitializationConfig `
        -DataDirectory $layout.DataDirectory -InitializationLog $layout.InitializationLog -CredentialPath $layout.EncryptedRootCredential
    return [pscustomobject]@{
        Mode = 'Provisioned data directory only'
        Endpoint = '127.0.0.1:3307'
        DataDirectory = $layout.DataDirectory
        InitializationConfig = $layout.InitializationConfig
        RuntimeConfig = $layout.RuntimeConfig
        EncryptedCredential = $layout.EncryptedRootCredential
        ServerId = $serverId
        ServerStarted = $false
        WindowsServiceCreated = $false
        DatabaseConnectionAttempted = $false
        AutomaticCleanupPerformed = $false
    }
}

function Invoke-BearlyMain {
    [CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
    param(
        [ValidateSet('Inspect', 'Provision')][string] $SelectedMode,
        [string] $Confirmation
    )

    if ($SelectedMode -eq 'Provision' -and [string]::IsNullOrWhiteSpace($Confirmation)) {
        throw 'Provision mode requires -ConfirmTarget with the exact disposable path and 127.0.0.1:3307 endpoint.'
    }
    $facts = Get-BearlyPreflightFacts
    Assert-BearlyPreflightFacts -Facts $facts -Mode $SelectedMode
    if ($SelectedMode -eq 'Inspect') {
        $plan = New-BearlySanitizedPlan -Paths $facts.Paths
        $plan | Format-List
        return
    }

    Assert-BearlyTargetConfirmation -Supplied $Confirmation -TargetRoot $facts.Paths.TargetRoot
    $description = 'Create isolated MySQL configuration and data directory, then run secure initialization only'
    $proceed = $PSCmdlet.ShouldProcess($facts.Paths.TargetRoot, $description)
    if ($proceed) {
        # The confirmation prompt can introduce a delay. Rediscover current
        # service/listener PIDs and target state immediately before mutation.
        $facts = Get-BearlyPreflightFacts
        Assert-BearlyPreflightFacts -Facts $facts -Mode 'Provision'
        Assert-BearlyTargetConfirmation -Supplied $Confirmation -TargetRoot $facts.Paths.TargetRoot
    }
    $result = Invoke-BearlyWorkflow -SelectedMode 'Provision' -Facts $facts -TargetRoot $facts.Paths.TargetRoot `
        -Confirmation $Confirmation -ShouldProceed $proceed -ProvisionAction { param($validatedFacts) Invoke-BearlyProvision -Facts $validatedFacts }
    $result | Format-List
}

if (-not $DefineOnly) {
    Invoke-BearlyMain -SelectedMode $Mode -Confirmation $ConfirmTarget
}
