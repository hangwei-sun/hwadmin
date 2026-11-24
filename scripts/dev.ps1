<#
.SYNOPSIS
  HwAdmin Windows + WSL 一键全栈本地联调启动脚本
.DESCRIPTION
  自动在 WSL 中拉起 PHP 测试服务（若缺少 .env 或指定 -Sqlite 则自动初始化 SQLite 开发库），
  并在 Windows 端启动 Vite 开发服务器（自动注入 VITE_HWADMIN_PROXY_TARGET），Ctrl+C 时清理后端进程。
#>
param(
  [switch]$Sqlite,
  [switch]$AutoPort,
  [int]$PhpPort = 8000,
  [int]$VitePort = 15173
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Split-Path -Parent $PSScriptRoot)).Path
if (Test-Path (Join-Path $ProjectRoot 'server\think')) {
  $ServerDir = Join-Path $ProjectRoot 'server'
  $AdminUiDir = Join-Path $ProjectRoot 'admin-ui'
} else {
  $ServerDir = $ProjectRoot
  $AdminUiDir = Join-Path $ProjectRoot 'sources\admin-ui'
}

# 动态转换 Windows 路径为 WSL 路径
$WslDistroArgs = @()
if ($env:HW_WSL_DISTRO) {
  $WslDistroArgs += @('-d', $env:HW_WSL_DISTRO)
}

function ConvertTo-WslPath([string]$winPath) {
  try {
    $argsList = @()
    if ($env:HW_WSL_DISTRO) {
      $argsList += @('-d', $env:HW_WSL_DISTRO)
    }
    $argsList += @('wslpath', '-a', '-u', $winPath)
    $converted = (& wsl.exe @argsList 2>$null).Trim()
    if ($LASTEXITCODE -eq 0 -and $converted) {
      return $converted
    }
  } catch {}
  if ($winPath -match '^([A-Za-z]):\\(.*)$') {
    $drive = $Matches[1].ToLower()
    $subPath = $Matches[2].Replace('\', '/')
    return "/mnt/$drive/$subPath"
  }
  return $winPath.Replace('\', '/')
}

$WslProjectRoot = ConvertTo-WslPath $ProjectRoot

function Invoke-WslBash([string]$command) {
  $cmdArgs = @()
  if ($env:HW_WSL_DISTRO) {
    $cmdArgs += @('-d', $env:HW_WSL_DISTRO)
  }
  $quotedRoot = "'" + $WslProjectRoot.Replace("'", "'`"'`"'") + "'"
  $cmdArgs += @('-e', 'bash', '-c', ('cd ' + $quotedRoot + ' && export PATH="$PWD/.hw-state/runtime/bin:$HOME/.local/bin:$PATH"; ' + $command))
  # Windows PowerShell 将原生命令 stderr（包括 WSL 代理提示）转为错误记录。
  $ErrorActionPreference = 'Continue'
  & wsl.exe @cmdArgs
}

function Test-PortInUse([int]$Port) {
  $client = New-Object System.Net.Sockets.TcpClient
  try {
    $async = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
    if ($async.AsyncWaitHandle.WaitOne(200, $false)) {
      $client.EndConnect($async)
      return $true
    }
    return $false
  } catch {
    return $false
  } finally {
    $client.Close()
  }
}

function Resolve-PortConflict([int]$TargetPort, [string]$Label) {
  if (-not (Test-PortInUse $TargetPort)) { return $TargetPort }
  if ($AutoPort) {
    for ($offset = 1; $offset -le 50; $offset++) {
      $cand = $TargetPort + $offset
      if (-not (Test-PortInUse $cand)) {
        Write-Host "[dev] $Label 端口 $TargetPort 已被占用，已自动切换至隔离端口 $cand" -ForegroundColor Yellow
        return $cand
      }
    }
    throw "$Label 端口 $TargetPort 及后续 50 个端口均已被占用"
  }
  throw "$Label 端口 $TargetPort 已被占用！请指定其他端口或传入 -AutoPort"
}

if ($Sqlite -or -not (Test-Path (Join-Path $ServerDir '.env'))) {
  Write-Host '[dev] 初始化本地 SQLite 开发数据库...' -ForegroundColor Cyan
  Invoke-WslBash 'bash scripts/wsl-dev.sh bootstrap'
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if ($AutoPort) { $PhpPort = Resolve-PortConflict $PhpPort 'PHP 后端' }
$VitePort = Resolve-PortConflict $VitePort 'Vite 前端'
$autoPortFlag = if ($AutoPort) { 'export AUTO_PORT=1; ' } else { '' }

Write-Host "[dev] 启动 WSL PHP 后端服务 (http://127.0.0.1:$PhpPort)..." -ForegroundColor Cyan
$quotedRoot = "'" + $WslProjectRoot.Replace("'", "'`"'`"'") + "'"
$backendLog = Join-Path $ProjectRoot '.hw-state/dev-backend.log'
New-Item -ItemType Directory -Path (Split-Path $backendLog) -Force | Out-Null
$serverBashCmd = 'cd ' + $quotedRoot + ' && export PATH="$PWD/.hw-state/runtime/bin:$HOME/.local/bin:$PATH"; ' + $autoPortFlag + 'export PORT=' + $PhpPort + '; bash scripts/wsl-dev.sh serve > .hw-state/dev-backend.log 2>&1'
$wslProcArgs = @()
if ($env:HW_WSL_DISTRO) {
  $wslProcArgs += @('-d', $env:HW_WSL_DISTRO)
}
$wslProcArgs += @('-e', 'bash', '-c', $serverBashCmd)
# Start-Process 会把 ArgumentList 数组重新拼接；必须保留 bash -c 的单个命令参数。
$startInfo = New-Object System.Diagnostics.ProcessStartInfo
$startInfo.FileName = 'wsl.exe'
$startInfo.UseShellExecute = $false
$startInfo.CreateNoWindow = $true
if ($null -ne $startInfo.ArgumentList) {
  foreach ($argument in $wslProcArgs) { $startInfo.ArgumentList.Add($argument) }
} else {
  # Windows PowerShell 5.1 / .NET Framework 的标准 Windows 参数转义。
  $startInfo.Arguments = ($wslProcArgs | ForEach-Object {
    if ($_ -ne '' -and $_ -notmatch '[\s"]') { $_ }
    else { '"' + [regex]::Replace([regex]::Replace($_, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1') + '"' }
  }) -join ' '
}
$phpProc = [System.Diagnostics.Process]::Start($startInfo)
$locationPushed = $false

try {
  $backendReady = $false
  for ($attempt = 0; $attempt -lt 60; $attempt++) {
    if ($phpProc.HasExited) { throw "后端启动失败，退出码 $($phpProc.ExitCode)，日志：$backendLog" }
    try {
      $health = Invoke-RestMethod "http://127.0.0.1:$PhpPort/api/index/ping" -TimeoutSec 2
      if ($health.code -eq 1) { $backendReady = $true; break }
    } catch {}
    Start-Sleep -Seconds 1
  }
  if (-not $backendReady) { throw "后端健康检查超时，日志：$backendLog" }
  $startStateFile = Join-Path $ProjectRoot '.hw-state\start.json'
  if (Test-Path $startStateFile) {
    try {
      $startState = Get-Content $startStateFile -Raw -Encoding UTF8 | ConvertFrom-Json
      $uiRel = if (Test-Path (Join-Path $ProjectRoot 'admin-ui\package.json')) { 'admin-ui' } else { 'sources/admin-ui' }
      Write-Host '================================================' -ForegroundColor Cyan
      switch ($startState.kind) {
        'website' {
          Write-Host "[dev] 官网前台预览: http://127.0.0.1:$VitePort/admin/site/index.html" -ForegroundColor Green
          Write-Host "[dev] 后端正式首页: http://127.0.0.1:$PhpPort/" -ForegroundColor Green
          Write-Host "[dev] 业务管理后台: http://127.0.0.1:$VitePort/admin/" -ForegroundColor Green
        }
        'admin' {
          Write-Host "[dev] 业务管理后台: http://127.0.0.1:$VitePort/admin/" -ForegroundColor Green
        }
        'miniapp' {
          Write-Host "[dev] 业务管理后台: http://127.0.0.1:$VitePort/admin/" -ForegroundColor Green
          Write-Host "[dev] 小程序编译命令: pnpm --dir $uiRel/mobile dev:mp" -ForegroundColor Yellow
          Write-Host "[dev] 微信开发者工具导入目录: $uiRel/mobile/dist/dev/mp-weixin" -ForegroundColor Yellow
        }
      }
      Write-Host '[dev] 默认超管账号: admin / Admin12345' -ForegroundColor Cyan
      Write-Host '================================================' -ForegroundColor Cyan
    } catch {}
  }
  $env:VITE_HWADMIN_PROXY_TARGET = "http://127.0.0.1:$PhpPort"
  $env:PORT = "$VitePort"
  Write-Host "[dev] 启动前端 Vite 开发服务 (http://127.0.0.1:$VitePort/admin/)..." -ForegroundColor Green
  Push-Location $AdminUiDir
  $locationPushed = $true
  pnpm dev
} finally {
  if ($locationPushed) { Pop-Location }
  Invoke-WslBash 'bash scripts/wsl-dev.sh stop' 2>$null
  if ($phpProc -and -not $phpProc.HasExited) {
    Write-Host '[dev] 正在停止 WSL PHP 服务...' -ForegroundColor Yellow
    Stop-Process -Id $phpProc.Id -Force -ErrorAction SilentlyContinue
  }
}
