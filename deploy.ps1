<#
.SYNOPSIS
  Build and deploy PHP journal site (j.nayanovaacademy.ru) to remote server via SSH.

.DESCRIPTION
  Reads config from .env, builds frontend assets (Tailwind + Vite) on this PC,
  packs the PHP application (public/, src/, templates/, data/) and syncs it to
  the remote server via tar over SSH.

  User data is preserved on the server across deploys:
    - db/      (SQLite database app.db)
    - runtime/ (logs, uploaded files)

.PARAMETER DryRun
  Show commands without executing.
.PARAMETER SkipBuild
  Skip asset build step, deploy only.

.EXAMPLE
  .\deploy.ps1
  .\deploy.ps1 -DryRun
  .\deploy.ps1 -SkipBuild
#>

param(
  [switch]$DryRun,
  [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'

# ─── 1. Load .env ───
$envFile = Join-Path $PSScriptRoot '.env'
if (Test-Path $envFile) {
  Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+?)\s*$') {
      [Environment]::SetEnvironmentVariable($matches[1], $matches[2])
    }
  }
}

$sshHost  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_HOST')
$sshPort  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_PORT')
if (-not $sshPort) { $sshPort = '22' }
$sshUser  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_USER')
$remotePath = [Environment]::GetEnvironmentVariable('DEPLOY_REMOTE_PATH')
if ($remotePath) { $remotePath = $remotePath.TrimEnd('/') }

if (-not $sshHost -or -not $sshUser -or -not $remotePath) {
  Write-Host "ERROR: Set DEPLOY_SSH_HOST, DEPLOY_SSH_USER and DEPLOY_REMOTE_PATH in .env" -ForegroundColor Red
  exit 1
}

$identityFile = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_KEY')
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFile = (Resolve-Path $identityFile).Path
}
$identityArg = if ($identityFile) { "-i `"$identityFile`"" } else { '' }
$remote = "${sshUser}@${sshHost}"

# ─── Fix SSH key permissions (Windows OpenSSH requires restrictive ACLs) ───
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFullPath = (Resolve-Path $identityFile).Path
  icacls $identityFullPath /reset 2>$null
  icacls $identityFullPath /inheritance:r 2>$null
  icacls $identityFullPath /grant "${env:USERNAME}:(R)" 2>$null
}

# ─── 2. Build assets (Tailwind + Vite) ───
if (-not $SkipBuild) {
  Write-Host "`n==> Building assets (Vite)..." -ForegroundColor Cyan
  if ($DryRun) {
    Write-Host "  [DryRun] (cd assets-src) npm run build" -ForegroundColor Yellow
  } else {
    Push-Location (Join-Path $PSScriptRoot 'assets-src')
    if (-not (Test-Path 'node_modules')) {
      & npm install
    }
    npm run build
    if ($LASTEXITCODE -ne 0) {
      Write-Host "Build failed" -ForegroundColor Red
      Pop-Location
      exit 1
    }
    Pop-Location
  }
}

# ─── 3. Deploy via tar + ssh ───
$root = $PSScriptRoot
foreach ($d in @('public', 'src', 'templates', 'data', 'db', 'runtime')) {
  if (-not (Test-Path (Join-Path $root $d))) {
    Write-Host "ERROR: missing '$d/' in project. Abort." -ForegroundColor Red
    exit 1
  }
}

$sshArgStr = ""
if ($sshPort -ne '22') { $sshArgStr += "-p $sshPort " }
if ($identityFile) { $sshArgStr += "-i `"$identityFile`" " }
# Сохраняем пользовательские данные (db/, runtime/) на сервере перед очисткой,
# извлекаем обновлённые файлы приложения, восстанавливаем данные и права.
# db/app.db* не входит в тарбол (см. ниже), поэтому БД сервера не теряется,
# но db/migration.sql приходит с обновлённым кодом.
$sshArgStr += "$remote `"cp -r $remotePath/db /tmp/.jweb-db 2>/dev/null; cp -r $remotePath/runtime /tmp/.jweb-rt 2>/dev/null; rm -rf $remotePath/* $remotePath/.[!.]* 2>/dev/null; mkdir -p $remotePath/db $remotePath/runtime 2>/dev/null; cp -r /tmp/.jweb-db/* $remotePath/db/ 2>/dev/null; cp -r /tmp/.jweb-rt/* $remotePath/runtime/ 2>/dev/null; rm -rf /tmp/.jweb-db /tmp/.jweb-rt; tar -xzf - -C $remotePath; chown -R www-data:www-data $remotePath/db $remotePath/runtime 2>/dev/null; chmod -R 775 $remotePath/db $remotePath/runtime 2>/dev/null`""

Write-Host "`n==> Deploying to ${remote}:${remotePath} ..." -ForegroundColor Cyan

if ($DryRun) {
  Write-Host "  [DryRun] tar -czf - -C <package> --exclude node_modules . | ssh $sshArgStr" -ForegroundColor Yellow
} else {
  Write-Host "  Archiving and transferring..." -ForegroundColor Gray

  $targz = Join-Path $env:TEMP "deploy-$(Get-Random).tar.gz"
  try {
    # Создаём временную папку только с файлами, которые должны попасть на сервер
    $tmpDir = Join-Path $env:TEMP "deploy-tmp-$(Get-Random)"
    New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null
    foreach ($sub in @('public', 'src', 'templates', 'data', 'db')) {
      Copy-Item -Recurse -Path (Join-Path $root $sub) -Destination (Join-Path $tmpDir $sub)
    }

    & tar -czf $targz -C $tmpDir `
      --exclude node_modules `
      --exclude __pycache__ `
      --exclude 'app.db' --exclude 'app.db-wal' --exclude 'app.db-shm' .
    if ($LASTEXITCODE -ne 0) {
      Write-Host "  Archive creation failed" -ForegroundColor Red
      exit 1
    }

    $bytes = [System.IO.File]::ReadAllBytes($targz)
    $psi = New-Object System.Diagnostics.ProcessStartInfo('ssh', $sshArgStr)
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $proc = [System.Diagnostics.Process]::Start($psi)

    try {
      $proc.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
      $proc.StandardInput.Close()
    } catch [System.IO.IOException] {
      $stderr = $proc.StandardError.ReadToEnd()
      $proc.WaitForExit()
      Write-Host "  Deploy failed: $($_.Exception.Message)" -ForegroundColor Red
      if ($stderr) { Write-Host "  SSH: $stderr" -ForegroundColor Red }
      exit 1
    }

    $stdoutTask = $proc.StandardOutput.ReadToEndAsync()
    $stderrTask = $proc.StandardError.ReadToEndAsync()
    $proc.WaitForExit()
    $stdout = $stdoutTask.Result
    $stderr = $stderrTask.Result

    if ($proc.ExitCode -ne 0) {
      Write-Host "  Deploy failed (exit code: $($proc.ExitCode))" -ForegroundColor Red
      if ($stdout) { Write-Host "  SSH stdout: $stdout" -ForegroundColor Red }
      if ($stderr) { Write-Host "  SSH stderr: $stderr" -ForegroundColor Red }
      exit 1
    }
  } finally {
    Remove-Item $targz -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Path $tmpDir -ErrorAction SilentlyContinue
  }

  Write-Host "  Done." -ForegroundColor Green
}

Write-Host "`n==> Deploy complete" -ForegroundColor Green