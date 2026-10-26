$scriptUnderTest = Join-Path (Split-Path -Parent $PSScriptRoot) 'Prepare-BearlyDisposableMysql.ps1'
. $scriptUnderTest -DefineOnly

function New-TestPreflightFacts {
    $root = 'C:\Users\operator\AppData\Local\BearlyDisposable\mysql-8.0.43'
    $exe = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe'
    return [pscustomobject]@{
        CurrentAccount = 'CONTOSO\operator'
        IsElevated = $true
        ProcessMetadataStatus = 'Readable'
        ServiceName = 'MySQL80'
        ServiceState = 'Running'
        ServiceProcessId = 100
        ServiceOwner = 'NT AUTHORITY\SYSTEM'
        ServiceExecutablePath = $exe
        ServiceVersion = '8.0.43.0'
        ServiceConfigPath = 'C:\ProgramData\MySQL\MySQL Server 8.0\my.ini'
        ServiceDataDirectory = 'C:\ProgramData\MySQL\MySQL Server 8.0\Data'
        Port3307Status = 'Free'
        Listeners = @(
            [pscustomobject]@{ LocalPort = 3306; OwningProcess = 200 },
            [pscustomobject]@{ LocalPort = 33060; OwningProcess = 200 }
        )
        Processes = @(
            [pscustomobject]@{ ProcessId = 200; ParentProcessId = 100; ExecutablePath = $exe; Owner = 'NT AUTHORITY\SYSTEM'; MetadataStatus = 'Readable' },
            [pscustomobject]@{ ProcessId = 100; ParentProcessId = 50; ExecutablePath = $exe; Owner = 'SYSTEM'; MetadataStatus = 'Readable' },
            [pscustomobject]@{ ProcessId = 50; ParentProcessId = 1; ExecutablePath = 'C:\Windows\System32\services.exe'; Owner = ''; MetadataStatus = 'Readable' },
            [pscustomobject]@{ ProcessId = 1; ParentProcessId = 0; ExecutablePath = 'C:\Windows\System32\wininit.exe'; Owner = ''; MetadataStatus = 'Readable' }
        )
        UnexpectedDisposableProcess = $false
        UnexpectedDisposableService = $false
        ParentAclSafe = $true
        AvailableBytes = 20GB
        Paths = [pscustomobject]@{
            TargetRoot = $root
            LocalAppData = 'C:\Users\operator\AppData\Local'
            DataDirectory = "$root\data"
            ConfigPaths = @("$root\config\initialize.cnf", "$root\config\runtime.cnf")
            NoReparseAncestors = $true
            RootEmptyOrAbsent = $true
        }
        ProtectedPaths = @(
            $exe,
            'C:\ProgramData\MySQL\MySQL Server 8.0\my.ini',
            'C:\ProgramData\MySQL\MySQL Server 8.0\Data',
            'C:\Program Files\MySQL\MySQL Server 8.0'
        )
        OneDriveRoots = @('C:\Users\operator\OneDrive')
    }
}

Describe 'Prepare-BearlyDisposableMysql offline safety policy' {
    Mock New-Item { throw 'Unexpected mutating filesystem command in offline tests.' }
    Mock Set-Acl { throw 'Unexpected ACL change in offline tests.' }
    Mock Invoke-BearlyMysqlInitialize { throw 'Unexpected MySQL initialization in offline tests.' }
    Mock Invoke-BearlyProvision { throw 'Unexpected provisioning in offline tests.' }
    Mock Write-BearlyCredentialFile { throw 'Unexpected credential-file write in offline tests.' }

    It 'accepts consistent read-only preflight facts and returns a sanitized plan' {
        $facts = New-TestPreflightFacts
        $plan = Invoke-BearlyWorkflow -SelectedMode Inspect -Facts $facts -TargetRoot $facts.Paths.TargetRoot -ProvisionAction { throw 'Provision action must not run in Inspect mode.' }
        $plan.ProposedEndpoint | Should Be '127.0.0.1:3307 (TCP only, loopback binding)'
        $plan.MutationPerformed | Should Be $false
        $plan.DatabaseConnectionAttempted | Should Be $false
    }

    It 'keeps all filesystem and MySQL mutators uncalled in Inspect mode' {
        $facts = New-TestPreflightFacts
        Invoke-BearlyWorkflow -SelectedMode Inspect -Facts $facts -TargetRoot $facts.Paths.TargetRoot `
            -ProvisionAction { Invoke-BearlyProvision -Facts $facts } | Out-Null
        Assert-MockCalled New-Item -Times 0 -Scope It
        Assert-MockCalled Set-Acl -Times 0 -Scope It
        Assert-MockCalled Invoke-BearlyMysqlInitialize -Times 0 -Scope It
        Assert-MockCalled Invoke-BearlyProvision -Times 0 -Scope It
        Assert-MockCalled Write-BearlyCredentialFile -Times 0 -Scope It
    }

    It 'keeps the Inspect entry point on its read-only path' {
        $script:TestPreflightFacts = New-TestPreflightFacts
        Mock Get-BearlyPreflightFacts { return $script:TestPreflightFacts }
        Mock Start-Service { throw 'Inspect must never start a service.' }
        Mock Stop-Service { throw 'Inspect must never stop a service.' }

        Invoke-BearlyMain -SelectedMode Inspect -Confirmation '' | Out-Null

        Assert-MockCalled Get-BearlyPreflightFacts -Times 1 -Scope It
        Assert-MockCalled New-Item -Times 0 -Scope It
        Assert-MockCalled Start-Service -Times 0 -Scope It
        Assert-MockCalled Stop-Service -Times 0 -Scope It
        Assert-MockCalled Invoke-BearlyMysqlInitialize -Times 0 -Scope It
        Assert-MockCalled Invoke-BearlyProvision -Times 0 -Scope It
        Assert-MockCalled Write-BearlyCredentialFile -Times 0 -Scope It
    }

    It 'rejects the wrong MySQL executable' {
        $facts = New-TestPreflightFacts
        $facts.ServiceExecutablePath = 'C:\Tools\mysqld.exe'
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects a listener with an unexpected process owner' {
        $facts = New-TestPreflightFacts
        $facts.Processes[0].Owner = 'CONTOSO\another-user'
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects a listener outside the current MySQL80 process ancestry' {
        $facts = New-TestPreflightFacts
        $facts.Processes[0].ParentProcessId = 999
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'treats denied process metadata as a safety failure, not an executable mismatch' {
        $facts = New-TestPreflightFacts
        $facts.ProcessMetadataStatus = 'AccessDenied'
        try { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect; throw 'Expected a safety failure.' }
        catch { $_.Exception.Message | Should Match 'permission denied|unavailable' }
    }

    It 'rejects an occupied port 3307' {
        $facts = New-TestPreflightFacts
        $facts.Port3307Status = 'Occupied'
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects an existing data directory or nonempty target root' {
        $facts = New-TestPreflightFacts
        $facts.Paths.RootEmptyOrAbsent = $false
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects reparse-point redirection' {
        $facts = New-TestPreflightFacts
        $facts.Paths.NoReparseAncestors = $false
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects overlap with the existing MySQL data directory' {
        $facts = New-TestPreflightFacts
        $facts.Paths.TargetRoot = 'C:\ProgramData\MySQL\MySQL Server 8.0\Data'
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects a configuration path outside the disposable root' {
        $facts = New-TestPreflightFacts
        $facts.Paths.ConfigPaths = @('C:\ProgramData\MySQL\my.ini', "$($facts.Paths.TargetRoot)\config\runtime.cnf")
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'rejects an unsafe traversal path' {
        { ConvertTo-BearlyCanonicalPath 'C:\Users\operator\..\shared' } | Should Throw
    }

    It 'requires an exact endpoint and root confirmation before a provision action' {
        $facts = New-TestPreflightFacts
        $facts.IsElevated = $false
        $script:provisionCalled = $false
        try {
            Invoke-BearlyWorkflow -SelectedMode Provision -Facts $facts -TargetRoot $facts.Paths.TargetRoot `
                -Confirmation '' -ProvisionAction { $script:provisionCalled = $true }
            throw 'Expected missing confirmation rejection.'
        }
        catch { $_.Exception.Message | Should Match 'exact target confirmation' }
        $script:provisionCalled | Should Be $false
    }

    It 'refuses accidental targeting of MySQL80 and never invokes the provision action' {
        $facts = New-TestPreflightFacts
        $facts.IsElevated = $false
        $facts.Paths.TargetRoot = $facts.ServiceDataDirectory
        $script:provisionCalled = $false
        try {
            Invoke-BearlyWorkflow -SelectedMode Provision -Facts $facts -TargetRoot $facts.Paths.TargetRoot `
                -Confirmation ('INITIALIZE|127.0.0.1:3307|' + $facts.Paths.TargetRoot) `
                -ProvisionAction { $script:provisionCalled = $true }
            throw 'Expected protected-path rejection.'
        }
        catch { $_.Exception.Message | Should Match 'fixed LOCALAPPDATA|overlaps' }
        $script:provisionCalled | Should Be $false
    }

    It 'does not invoke mutating actions when explicit ShouldProcess approval is absent' {
        $facts = New-TestPreflightFacts
        $facts.IsElevated = $false
        $script:provisionCalled = $false
        $confirmation = 'INITIALIZE|127.0.0.1:3307|' + $facts.Paths.TargetRoot
        $result = Invoke-BearlyWorkflow -SelectedMode Provision -Facts $facts -TargetRoot $facts.Paths.TargetRoot `
            -Confirmation $confirmation -ShouldProceed $false -ProvisionAction { $script:provisionCalled = $true }
        $script:provisionCalled | Should Be $false
        $result.MutationPerformed | Should Be $false
    }

    It 'prepares distinct initialization and runtime configs with required isolation settings' {
        $layout = Get-BearlyExpectedLayout -LocalAppData 'C:\Users\operator\AppData\Local'
        $content = New-BearlyMySqlConfigurationText -Layout $layout -ServerId 472001
        $content.Initialization | Should Match 'basedir="C:/Program Files/MySQL/MySQL Server 8.0"'
        $content.Initialization | Should Match 'datadir="C:/Users/operator/AppData/Local/BearlyDisposable/mysql-8.0.43/data"'
        $content.Initialization | Should Not Match 'bind-address|port=3307|mysqlx|local_infile|server-id'
        $content.Runtime | Should Match 'bind-address=127\.0\.0\.1'
        $content.Runtime | Should Match 'port=3307'
        $content.Runtime | Should Match 'mysqlx=OFF'
        $content.Runtime | Should Match 'local_infile=OFF'
        $content.Runtime | Should Match 'server-id=472001'
        $content.Runtime | Should Match 'mysql\.err'
        $content.Initialization | Should Not Match '3306|ProgramData/MySQL'
    }
}

Describe 'Prepare-BearlyDisposableMysql OneDrive registry root discovery' {
    $script:TestAccountsPath = 'HKCU:\Software\Microsoft\OneDrive\Accounts'

    BeforeEach {
        $script:TestRegistryEntries = @()
        $script:TestRegistryAccessDenied = $false
        $script:TestRegistryPropertyAccessDenied = $false
        $script:TestAccountsKeyMissing = $false
        $script:TestParentReadCount = 0

        Mock Get-Item {
            $script:TestParentReadCount++
            if ($script:TestAccountsKeyMissing) {
                Write-Error 'The mocked Accounts key does not exist.' -Category ObjectNotFound -ErrorId PathNotFound -TargetObject $LiteralPath -ErrorAction Stop
            }
            # The Accounts container intentionally has no UserFolder value.
            return [pscustomobject]@{ PSPath = $LiteralPath; DisplayName = 'Accounts' }
        } -ParameterFilter { $LiteralPath -eq $script:TestAccountsPath }

        Mock Get-ChildItem {
            if ($script:TestRegistryAccessDenied) { throw 'Access is denied while enumerating OneDrive accounts.' }
            return $script:TestRegistryEntries
        } -ParameterFilter { $LiteralPath -eq $script:TestAccountsPath }

        Mock Get-ItemProperty {
            if ($script:TestRegistryPropertyAccessDenied) { throw 'Access is denied while reading OneDrive account properties.' }
            $accountName = Split-Path -Leaf $LiteralPath
            $entry = @($script:TestRegistryEntries | Where-Object { $_.PSChildName -eq $accountName } | Select-Object -First 1)
            if ($entry.Count -eq 0) { throw "No mocked account properties exist for $LiteralPath." }
            if (-not $entry[0].TestHasUserFolder) { return [pscustomobject]@{ DisplayName = $accountName } }
            return [pscustomobject]@{ DisplayName = $accountName; UserFolder = $entry[0].TestUserFolder }
        } -ParameterFilter { $LiteralPath -like "$script:TestAccountsPath\*" }
    }

    function Add-TestOneDriveAccount {
        param(
            [Parameter(Mandatory = $true)][string] $Name,
            [AllowNull()][AllowEmptyString()][string] $UserFolder,
            [switch] $OmitUserFolder
        )

        $script:TestRegistryEntries += [pscustomobject]@{
            PSChildName = $Name
            TestHasUserFolder = -not $OmitUserFolder
            TestUserFolder = $UserFolder
        }
    }

    It 'reads a valid Personal account root' {
        Add-TestOneDriveAccount -Name 'Personal' -UserFolder 'C:\Users\operator\OneDrive'
        $roots = Get-BearlyOneDriveRoots
        ($roots -contains 'C:\Users\operator\OneDrive') | Should Be $true
    }

    It 'reads a valid Business account root' {
        Add-TestOneDriveAccount -Name 'Business1' -UserFolder 'D:\Sync\OneDrive - Contoso'
        $roots = Get-BearlyOneDriveRoots
        ($roots -contains 'D:\Sync\OneDrive - Contoso') | Should Be $true
    }

    It 'reads roots from multiple registered Personal and Business accounts' {
        Add-TestOneDriveAccount -Name 'Personal' -UserFolder 'C:\Users\operator\OneDrive'
        Add-TestOneDriveAccount -Name 'Business1' -UserFolder 'D:\Sync\OneDrive - Contoso'
        Add-TestOneDriveAccount -Name 'Business2' -UserFolder 'E:\Work\OneDrive - Fabrikam'
        $roots = Get-BearlyOneDriveRoots
        ($roots -contains 'C:\Users\operator\OneDrive') | Should Be $true
        ($roots -contains 'D:\Sync\OneDrive - Contoso') | Should Be $true
        ($roots -contains 'E:\Work\OneDrive - Fabrikam') | Should Be $true
    }

    It 'does not read UserFolder from the irrelevant Accounts parent key' {
        Add-TestOneDriveAccount -Name 'Personal' -UserFolder 'C:\Users\operator\OneDrive'
        $roots = Get-BearlyOneDriveRoots
        $script:TestParentReadCount | Should Be 1
        ($roots -contains 'C:\Users\operator\OneDrive') | Should Be $true
        Assert-MockCalled Get-ItemProperty -Times 1 -Scope It -ParameterFilter { $LiteralPath -eq (Join-Path $script:TestAccountsPath 'Personal') }
    }

    It 'treats a genuinely missing Accounts key separately from a registry access failure' {
        $script:TestAccountsKeyMissing = $true
        { Get-BearlyOneDriveRoots } | Should Not Throw
        Assert-MockCalled Get-ChildItem -Times 0 -Scope It
    }

    It 'fails closed when a configured account has no UserFolder property' {
        Add-TestOneDriveAccount -Name 'Business1' -OmitUserFolder
        { Get-BearlyOneDriveRoots } | Should Throw
    }

    It 'fails closed when a configured account has an empty UserFolder value' {
        Add-TestOneDriveAccount -Name 'Personal' -UserFolder ''
        { Get-BearlyOneDriveRoots } | Should Throw
    }

    It 'fails closed when a configured account has a malformed UserFolder path' {
        Add-TestOneDriveAccount -Name 'Business1' -UserFolder 'relative\OneDrive'
        { Get-BearlyOneDriveRoots } | Should Throw
    }

    It 'fails closed when registry access is denied' {
        Add-TestOneDriveAccount -Name 'Personal' -UserFolder 'C:\Users\operator\OneDrive'
        $script:TestRegistryAccessDenied = $true
        { Get-BearlyOneDriveRoots } | Should Throw
    }

    It 'fails closed when access to a configured account UserFolder value is denied' {
        Add-TestOneDriveAccount -Name 'Business1' -UserFolder 'D:\Sync\OneDrive - Contoso'
        $script:TestRegistryPropertyAccessDenied = $true
        { Get-BearlyOneDriveRoots } | Should Throw
    }

    It 'fails closed on an unrecognized direct child instead of ignoring a possible account' {
        $script:TestRegistryEntries += [pscustomobject]@{ PSChildName = 'BusinessX' }
        { Get-BearlyOneDriveRoots } | Should Throw
        Assert-MockCalled Get-ItemProperty -Times 0 -Scope It
    }

    It 'rejects a disposable target located inside a registered OneDrive root' {
        $facts = New-TestPreflightFacts
        $facts.OneDriveRoots = @('C:\Users\operator\AppData\Local')
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Throw
    }

    It 'accepts a disposable target outside all verified OneDrive roots' {
        $facts = New-TestPreflightFacts
        $facts.OneDriveRoots = @('D:\Sync\OneDrive - Contoso')
        { Assert-BearlyPreflightFacts -Facts $facts -Mode Inspect } | Should Not Throw
    }

    It 'uses path boundaries so a OneDrive sibling directory does not collide' {
        (Test-BearlyPathsOverlap -First 'C:\Users\operator\OneDrive\Project' -Second 'C:\Users\operator\OneDrive') | Should Be $true
        (Test-BearlyPathsOverlap -First 'C:\Users\operator\OneDriveBackup' -Second 'C:\Users\operator\OneDrive') | Should Be $false
        (Test-BearlyPathsOverlap -First 'C:\Users\operator\AppData\Local\BearlyDisposable\mysql-8.0.43-backup' -Second 'C:\Users\operator\AppData\Local\BearlyDisposable\mysql-8.0.43') | Should Be $false
    }
}
