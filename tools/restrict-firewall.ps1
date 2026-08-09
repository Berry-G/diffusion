<#
.SYNOPSIS
    Apache 인바운드를 로컬과 Tailscale 대역에서만 받도록 제한합니다.

.DESCRIPTION
    Laragon 의 Apache 는 지금 모든 원격 주소를 허용하고 있습니다.
    이 PC 는 공인 IP 가 랜카드에 직접 붙어 있어서, 그대로 두면 인터넷에서
    www 폴더의 모든 프로젝트에 닿을 수 있습니다.

    Windows 가 자동으로 만든 'Apache HTTP Server' 규칙은 이른바
    '사용자가 결정(Query User)' 형식이라 원격 주소 조건을 붙일 수 없습니다.
    그래서 그 규칙을 끄고, 대상을 좁힌 규칙을 새로 만듭니다.

      끄는 것   : TCP/UDP Query User ... httpd.exe   (모든 원격 주소 허용)
      만드는 것 : Apache (local + Tailscale only)    (127.0.0.1, 100.64.0.0/10 만)

    방화벽 기본 정책이 BlockInbound 이므로, 허용 규칙에 없는 주소는 차단됩니다.

    적용하면 LAN(192.168.x.x) 과 인터넷에서 Laragon 의 모든 사이트가 막힙니다.
    comfy 뿐 아니라 같은 Laragon 아래에 있는 다른 프로젝트까지 전부입니다. localhost 는 그대로 됩니다.

.PARAMETER Revert
    새로 만든 규칙을 지우고, 원래 규칙을 다시 켭니다.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\restrict-firewall.ps1
    powershell -ExecutionPolicy Bypass -File tools\restrict-firewall.ps1 -Revert
#>
param(
    [switch]$Revert
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [Console]::OutputEncoding = [Text.Encoding]::UTF8

$OLD_RULE = 'Apache HTTP Server'
$NEW_NAME = 'Apache-Local-Tailscale-Only'
$NEW_DISP = 'Apache (local + Tailscale only)'
$ALLOWED  = @('127.0.0.1', '100.64.0.0/10')

# 관리자가 아니면 UAC 를 띄워 자기 자신을 다시 실행한다.
$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host '관리자 권한이 필요합니다. UAC 창에서 [예] 를 눌러 주세요.' -ForegroundColor Yellow
    $argList = @('-NoExit', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    if ($Revert) { $argList += '-Revert' }
    Start-Process powershell -Verb RunAs -ArgumentList $argList
    return
}

function Show-State {
    Write-Host ''
    Write-Host '=== 현재 Apache 관련 방화벽 규칙 ===' -ForegroundColor Cyan
    Get-NetFirewallRule -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -eq $OLD_RULE -or $_.Name -eq $NEW_NAME } |
        ForEach-Object {
            $addr = $_ | Get-NetFirewallAddressFilter
            $port = $_ | Get-NetFirewallPortFilter
            '{0,-6} {1,-5} {2,-4} port={3,-8} remote={4}' -f `
                $(if ($_.Enabled -eq 'True') { '켜짐' } else { '꺼짐' }),
                $_.Action, $port.Protocol, ($port.LocalPort -join ','),
                ($addr.RemoteAddress -join ', ')
        }
}

if ($Revert) {
    Get-NetFirewallRule -Name $NEW_NAME -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Get-NetFirewallRule -DisplayName $OLD_RULE -ErrorAction SilentlyContinue | Enable-NetFirewallRule
    Write-Host '되돌렸습니다. 모든 원격 주소에서 다시 접근할 수 있습니다.' -ForegroundColor Yellow
    Show-State
    return
}

# httpd.exe 경로 찾기 — Apache 버전이 올라가면 폴더 이름이 바뀐다.
$httpd = Get-ChildItem 'C:\laragon\bin\apache' -Recurse -Filter 'httpd.exe' -ErrorAction SilentlyContinue |
    Select-Object -First 1 -ExpandProperty FullName
if (-not $httpd) {
    Write-Host 'httpd.exe 를 찾지 못했습니다. Laragon 설치 경로를 확인하세요.' -ForegroundColor Red
    return
}
Write-Host "대상: $httpd"

# 이미 만들어 둔 규칙이 있으면 지우고 새로 만든다 (경로가 바뀌었을 수 있다).
Get-NetFirewallRule -Name $NEW_NAME -ErrorAction SilentlyContinue | Remove-NetFirewallRule

New-NetFirewallRule `
    -Name $NEW_NAME `
    -DisplayName $NEW_DISP `
    -Description '로컬과 Tailscale 대역에서만 Apache 에 접근하도록 제한' `
    -Direction Inbound `
    -Action Allow `
    -Program $httpd `
    -Protocol TCP `
    -LocalPort 80, 443 `
    -RemoteAddress $ALLOWED `
    -Profile Any `
    -Enabled True | Out-Null

# 모든 원격 주소를 허용하던 원래 규칙을 끈다. (지우지 않으므로 -Revert 로 되살릴 수 있다)
$old = Get-NetFirewallRule -DisplayName $OLD_RULE -ErrorAction SilentlyContinue
if ($old) {
    $old | Disable-NetFirewallRule
    Write-Host ("원래 규칙 {0}개를 껐습니다." -f @($old).Count) -ForegroundColor Green
}

Write-Host '적용했습니다.' -ForegroundColor Green
Show-State

Write-Host ''
Write-Host 'Apache 를 다시 켤 때 Windows 방화벽 팝업이 뜨면 [취소] 를 누르세요.' -ForegroundColor Yellow
Write-Host '[액세스 허용] 을 누르면 모든 주소를 허용하는 규칙이 다시 생깁니다.' -ForegroundColor Yellow
