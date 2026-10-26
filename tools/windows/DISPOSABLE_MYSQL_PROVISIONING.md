# Bearly disposable MySQL 8.0.43 provisioning

This document and `Prepare-BearlyDisposableMysql.ps1` are preparation artifacts. During SYNC.3B.2C.1B, only the offline Pester tests and syntax checks are run. No database connection, initialization, directory creation, server startup, service change, or migration is performed.

## Safe inspection

The script defaults to `-Mode Inspect`. It reads Windows service, process, TCP-listener, option-file, filesystem, ACL, and free-space metadata. It does not start `mysqld`, connect to MySQL, create files, or change `MySQL80`.

From an elevated PowerShell session, run:

```powershell
Set-Location -LiteralPath '<path-to-repository>\bearly-fresh'
& .\tools\windows\Prepare-BearlyDisposableMysql.ps1
```

The inspection fails closed if process ownership cannot be read. An access-denied query is reported as an inability to verify identity, never as evidence that the executable is wrong. It rediscovers all current PIDs from the service and listener tables each time; it does not rely on historical PIDs.

The script requires MySQL80 to be running from the expected 8.0.43 binary and explicit option file, with the configured existing data directory. The 3306 and 33060 listeners must resolve to that service's process tree. TCP port 3307 must be unused. Inspection rejects unexpected target processes/services, target path overlap, OneDrive overlap, reparse-point ancestors, a nonempty target, unsafe parent ACLs, or less than 5 GiB free.

OneDrive registry discovery reads the `Accounts` key only as a container and reads roots only from direct `Personal` and `Business`-numbered account subkeys. Each recognized account must have a nonempty, absolute local `UserFolder` path. A missing property, malformed value, unknown direct child, or registry access failure blocks inspection; it is never treated as proof that the account has no sync root.

## Proposed isolated layout

The target is fixed to `$env:LOCALAPPDATA\BearlyDisposable\mysql-8.0.43`; it cannot be overridden by a caller:

```text
mysql-8.0.43\
  config\initialize.cnf
  config\runtime.cnf
  data\                 # initialized independently; generates server UUID
  logs\initialize.err
  logs\mysql.err
  run\mysql.pid
  tmp\
  files\
  credentials\root-temporary-password.dpapi
```

The initialization file contains only explicit basedir/datadir settings. The runtime file has explicit basedir/datadir, `bind-address=127.0.0.1`, port 3307, `mysqlx=OFF`, `local_infile=OFF`, disabled named pipe/shared memory, a random server ID distinct from MySQL80's one explicit server ID (or 1 when none is set), private file locations, and its own error log. Initialization output is captured privately, the generated password is protected, and sanitized diagnostics are stored separately. The MySQL data directory generates its own server UUID. No path or option is inherited from the existing MySQL80 option file. Config path overlap with the existing installation/data is rejected.

MySQL's documented Windows option-file syntax supports quoted values and forward-slash paths. The X Plugin can be disabled with `mysqlx=0`; secure initialization uses `--initialize`, and `--defaults-file` is the first server argument. These settings are covered by the [MySQL 8.0 option-file reference](https://dev.mysql.com/doc/refman/8.0/en/option-files.html), [X Plugin disablement reference](https://dev.mysql.com/doc/refman/8.0/en/x-plugin-disabling.html), and [data-directory initialization reference](https://dev.mysql.com/doc/refman/8.0/en/data-directory-initialization.html).

## Provisioning command for a later, separately approved run

Provision mode requires a fresh preflight, a non-elevated PowerShell caller, exact target confirmation, and PowerShell's `ShouldProcess` confirmation. If the non-elevated process metadata query is denied, stop; do not run provisioning elevated to bypass the guard. A separate least-privilege process-identity method would need review.

After a separate explicit approval, and only after a clean inspection, an operator could run:

```powershell
$root = Join-Path $env:LOCALAPPDATA 'BearlyDisposable\mysql-8.0.43'
$confirmTarget = "INITIALIZE|127.0.0.1:3307|$root"
& .\tools\windows\Prepare-BearlyDisposableMysql.ps1 `
    -Mode Provision `
    -ConfirmTarget $confirmTarget `
    -Confirm
```

The script asks for the exact target in the `ShouldProcess` prompt. `-WhatIf` performs the preflight and displays the plan only. Provision mode creates a fresh private directory layout, writes the two isolated option files, runs `mysqld --defaults-file=<initialize.cnf> --initialize` (with `--defaults-file` first), and exits after initialization. It never uses `--initialize-insecure`, never starts the server, never creates a Windows service, and never creates an application schema or user.

MySQL's generated temporary root password is marked expired by `--initialize`. The script never emits stdout, stderr, or credential-bearing log lines. It stores the temporary password encrypted with the current Windows user's DPAPI protection and a private ACL, then redacts the password line in initialization diagnostics. It does not print or place the password in an argument, source file, or terminal transcript. If initialization or credential protection fails, the partially initialized directory is preserved; do not rerun initialization or delete it automatically.

The encrypted credential file is decryptable only in the same Windows account context. A later reviewed credential-handling step must pass it to the MySQL client through a short-lived, ACL-restricted client option file and remove that file in a `finally` block. Do not decrypt it to console or clipboard, put a password in `-pPASSWORD`, or enable PowerShell transcription while handling it. MySQL 8.0.43 does not have the newer `--no-login-paths` option; isolate login-path settings with the documented `MYSQL_TEST_LOGIN_FILE` override when preparing a transient client config. This artifact intentionally does not run that later login/client step.

## Start and stop commands for later operator review

Only after initialization has been reviewed and a separate start approval is given, start the isolated process as the regular current user. The dedicated runtime option file is explicit and is the first MySQL server argument:

```powershell
$mysqld = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe'
$runtime = Join-Path $env:LOCALAPPDATA 'BearlyDisposable\mysql-8.0.43\config\runtime.cnf'
$root = Split-Path -Parent (Split-Path -Parent $runtime)
$mode = 'Start'
$expectedConfirmation = "START|127.0.0.1:3307|$root"
$confirmation = Read-Host "Type exactly: $expectedConfirmation"
if ($mode -cne 'Start' -or $confirmation -cne $expectedConfirmation) { throw 'Start was not explicitly confirmed for this exact target.' }
Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=`"$runtime`"" -WindowStyle Hidden -PassThru
```

Do not use `--install`, `--install-manual`, `sc.exe`, or `MySQL80` service controls. To stop it, first dynamically locate the listener on 3307, verify its executable, current user, parent chain, and command line reference the isolated runtime config and data directory, then require an exact `STOP|127.0.0.1:3307|<root>` operator confirmation and run `Stop-Process -Id <verified-listener-PID> -Confirm`. Never stop a process by the generic name `mysqld` or a PID copied from an earlier session.

## Verify identity before any disposable schema creation

Use a reviewed transient client option file for the encrypted temporary credential. Force TCP and the exact IPv4 endpoint so the client cannot fall back to a named pipe, socket, 3306, or a default connection:

```powershell
$mysql = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
$clientOptions = Join-Path $env:LOCALAPPDATA 'BearlyDisposable\mysql-8.0.43\credentials\client-once.cnf'
$oldLoginFile = $env:MYSQL_TEST_LOGIN_FILE
$env:MYSQL_TEST_LOGIN_FILE = Join-Path $env:TEMP ('bearly-no-login-' + [guid]::NewGuid().ToString('N') + '.cnf')
try {
    & $mysql "--defaults-file=$clientOptions" --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --batch --raw `
        --execute='SELECT @@hostname, @@port, @@server_uuid, @@version, @@datadir, @@bind_address, CURRENT_USER(), DATABASE();'
    if ($LASTEXITCODE -ne 0) { throw 'Identity query failed; stop before schema creation.' }
}
finally {
    $env:MYSQL_TEST_LOGIN_FILE = $oldLoginFile
    # Remove only this temporary credential file after the separately reviewed
    # credential helper has created it; never remove the data directory here.
    if (Test-Path -LiteralPath $clientOptions) { Remove-Item -LiteralPath $clientOptions -Force }
}
```

The client option file must be created only by the separately reviewed credential step, ACL-restricted to the current user/SYSTEM/Administrators, excluded from source control, and removed in `finally`. Restore `MYSQL_TEST_LOGIN_FILE` in that same `finally` block. Because MySQL marks the initial root password expired, it must first be changed without placing the new password in command arguments, a SQL source file, or a terminal transcript. A secure stdin-based password-rotation helper is not part of this preparation task. Implement and review that helper before attempting the identity query; this is a blocker to real client verification. The commands above are for later operator review and were not run.

Before creating any disposable schema, compare the query result with the approved target and the current process evidence. Also verify the current listener PID belongs to this executable and user, its command line names `runtime.cnf`, the actual datadir is the target's `data` directory, the bound address is `127.0.0.1`, the port is 3307, the X Protocol port has no listener, and no other unexpected listener exists. Record server UUID and version as trusted identity evidence. Any mismatch means stop before schema creation. Later migration-runner allowlists and grants must continue to enforce a unique disposable schema/user and must never target `bearly_friend_snapshot_dev`.

The query does not verify grants or a schema marker; those checks are required before the separate disposable migration runner is authorized. Do not create a schema or application account as part of this provisioning phase.

## Partial failure and recovery

Initialization is not retryable over an existing or partially populated `data` directory. On any failure, the script preserves all files and does not remove the root. Keep the isolated logs private, do not publish raw log lines, and have an operator inspect the sanitized error log and inventory the data directory. A recovery or reinitialization procedure requires separate review and approval; do not use automatic cleanup or point the instance at MySQL80 data.

After provisioning, verify the target contains both dedicated config files, a nonempty initialized data directory, a generated `data\auto.cnf`, private ACLs, separate logs/runtime paths, and the DPAPI credential file. Before any future schema work, require successful endpoint, version, UUID, datadir, bind/port, authenticated account, process ancestry, runtime-config, listener, schema allowlist, schema marker, and grant checks. Keep all database migration execution in the separately guarded offline runner.
