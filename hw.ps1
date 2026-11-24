<#
.SYNOPSIS
  HwAdmin Windows 统一 CLI 启动入口 (PowerShell)
.DESCRIPTION
  统一封装 doctor, bootstrap, dev, stop, inspect, verify, package, deploy, verify-runtime 等核心能力。
#>
param(
  [Parameter(Position = 0)]
  [string]$Action = 'help',
  [Parameter(ValueFromRemainingArguments = $true)]
  [string[]]$ArgsList
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path $PSScriptRoot).Path

function Get-WslPath([string]$winPath) {
  try {
    $argsL = @()
    if ($env:HW_WSL_DISTRO) { $argsL += @('-d', $env:HW_WSL_DISTRO) }
    $argsL += @('wslpath', '-a', '-u', $winPath)
    $res = (& wsl.exe @argsL 2>$null).Trim()
    if ($LASTEXITCODE -eq 0 -and $res) { return $res }
  } catch {}
  if ($winPath -match '^([A-Za-z]):\\(.*)$') {
    $d = $Matches[1].ToLower()
    $sp = $Matches[2].Replace('\', '/')
    return "/mnt/$d/$sp"
  }
  return $winPath.Replace('\', '/')
}

$WslRoot = Get-WslPath $ProjectRoot

function Invoke-WslCommand([string]$subCmd) {
  $wslArgs = @()
  if ($env:HW_WSL_DISTRO) { $wslArgs += @('-d', $env:HW_WSL_DISTRO) }
  $quotedRoot = "'" + $WslRoot.Replace("'", "'`"'`"'") + "'"
  $wslArgs += @('-e', 'bash', '-c', "cd $quotedRoot && export PATH=`"`$PWD/.hw-state/runtime/bin:`$HOME/.local/bin:`$PATH`" && $subCmd")
  & wsl.exe @wslArgs
  if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
  }
}

function Find-LocalPython {
  foreach ($cand in @('python', 'python3')) {
    $cmds = @(Get-Command $cand -All -ErrorAction SilentlyContinue)
    foreach ($cmd in $cmds) {
      if ($cmd.Source -like '*\WindowsApps\*') { continue }
      try {
        & $cmd.Source --version *> $null
        if ($LASTEXITCODE -eq 0) { return $cmd.Source }
      } catch {}
    }
  }
  return $null
}

function Format-BashArgs([string[]]$list) {
  if (-not $list -or $list.Count -eq 0) { return '' }
  return ($list | ForEach-Object { "'" + $_.Replace("'", "'`"'`"'") + "'" }) -join ' '
}

switch ($Action.ToLower()) {
  'doctor' {
    $argStr = Format-BashArgs $ArgsList
    $wslArgs = @()
    if ($env:HW_WSL_DISTRO) { $wslArgs += @('-d', $env:HW_WSL_DISTRO) }
    $quotedRoot = "'" + $WslRoot.Replace("'", "'`"'`"'") + "'"
    $wslArgs += @('-e', 'bash', '-c', "cd $quotedRoot && export PATH=`"`$PWD/.hw-state/runtime/bin:`$HOME/.local/bin:`$PATH`" && bash scripts/wsl-dev.sh doctor $argStr")
    & wsl.exe @wslArgs
    $rc = $LASTEXITCODE
    if ($rc -ne 0) {
      $py = Find-LocalPython
      if ($rc -ne 1 -and $py) {
        if ($ArgsList -notcontains '--json') {
          Write-Host '[!] WSL 后端环境不可用，已切换本地运行时状态诊断：' -ForegroundColor Yellow
        }
        & $py "$ProjectRoot\scripts\hw-runtime.py" --project-root "$ProjectRoot" status @ArgsList
      }
      exit $rc
    }
    break
  }
  'runtime' {
    $useWsl = $false
    $passArgs = @()
    foreach ($a in $ArgsList) {
      if ($a -eq '--in-wsl') { $useWsl = $true } else { $passArgs += $a }
    }
    $py = if (-not $useWsl) { Find-LocalPython } else { $null }
    if ($py) {
      & $py "$ProjectRoot\scripts\hw-runtime.py" --project-root "$ProjectRoot" @passArgs
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    } else {
      $argStr = Format-BashArgs $passArgs
      Invoke-WslCommand "python3 scripts/hw-runtime.py --project-root . $argStr"
    }
    break
  }
  'start' {
    $argStr = Format-BashArgs $ArgsList
    Invoke-WslCommand "python3 scripts/start-project.py $argStr"
    if ($ArgsList -notcontains '--prepare-only') {
      & "$ProjectRoot\scripts\dev.ps1" -AutoPort
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
    break
  }
  'bootstrap' {
    $argStr = Format-BashArgs $ArgsList
    Invoke-WslCommand "bash ./hw bootstrap $argStr"
    break
  }
  'dev' {
    & "$ProjectRoot\scripts\dev.ps1" @ArgsList
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    break
  }
  'stop' {
    $argStr = Format-BashArgs $ArgsList
    Invoke-WslCommand "bash scripts/wsl-dev.sh stop $argStr"
    break
  }
  'inspect' {
    $argStr = Format-BashArgs $ArgsList
    Invoke-WslCommand "bash scripts/wsl-dev.sh inspect $argStr"
    break
  }
  'verify' {
    $argStr = Format-BashArgs $ArgsList
    Invoke-WslCommand "bash ./hw verify $argStr"
    break
  }
  'verify-runtime' {
    $rtArgs = @($ArgsList)
    if ($rtArgs.Count -ge 1 -and (Test-Path $rtArgs[0])) {
      $rtArgs[0] = Get-WslPath (Resolve-Path $rtArgs[0]).Path
    }
    $argStr = Format-BashArgs $rtArgs
    Invoke-WslCommand "bash ./hw runtime-verify $argStr"
    break
  }
  'runtime-verify' {
    $rtArgs = @($ArgsList)
    if ($rtArgs.Count -ge 1 -and (Test-Path $rtArgs[0])) {
      $rtArgs[0] = Get-WslPath (Resolve-Path $rtArgs[0]).Path
    }
    $argStr = Format-BashArgs $rtArgs
    Invoke-WslCommand "bash ./hw runtime-verify $argStr"
    break
  }
  'package' {
    $py = Find-LocalPython
    if (-not $py) { throw '未找到本地 Python 3 运行时' }
    & $py "$ProjectRoot\scripts\build-release.py" @ArgsList
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    break
  }
  'deploy' {
    $py = Find-LocalPython
    if (-not $py) { throw '未找到本地 Python 3 运行时' }
    & $py "$ProjectRoot\scripts\hw-deploy.py" @ArgsList
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    break
  }
  default {
    Write-Host @"
================================================================
 HwAdmin 统一 CLI 启动入口 (Windows PowerShell)
================================================================
使用方式: .\hw.ps1 <command> [arguments...]

可用命令:
  start           一步准备业务模板并启动预览 [website|admin|miniapp] [--name 名称]
  doctor          运行运行环境与依赖健康体检 [--json] [--mode=sqlite|mysql|pgsql]
  bootstrap       非破坏性引导初始化系统与数据库 (保留既有数据与管理员)
  dev             一键启动全栈本地联调服务 (后端:8000 + 前端:15173)
  stop            安全停止当前项目的本地开发服务进程
  inspect         输出项目路由与架构自省事实清单 [--json]
  verify          运行完整放行验证套件 (lint/doctor/phpunit/smoke/pc_types/mobile_types/package) [--json] [--skip-package]
  package         构建生产发布包与 Agent 开发包 [--mode=deploy|dev|all]
  deploy          声明式生产部署编排工具 (plan|apply|rollback|report)
  runtime         跨平台运行时矩阵、环境探测与受管准备器 (matrix|status|prepare)
  runtime-verify  严格校验受管运行时归档包 SHA-256 签名 (别名: verify-runtime)
  help            查看帮助说明
"@
    if ($Action.ToLower() -notin @('help', '--help', '-h')) {
      exit 1
    }
    exit 0
  }
}
