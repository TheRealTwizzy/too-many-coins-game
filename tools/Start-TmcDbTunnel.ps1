param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'local/tmc-sim.current.ps1')
)

$ErrorActionPreference = 'Stop'

function Get-TmcSimConfig {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Config file not found: $Path"
    }

    $loaded = & $Path
    if ($null -eq $loaded) {
        throw "Config file did not return a configuration hashtable: $Path"
    }

    if ($loaded -isnot [hashtable]) {
        throw "Config file must return a hashtable: $Path"
    }

    return $loaded
}

function Require-ConfigValue {
    param(
        [hashtable]$Config,
        [string]$Name
    )

    $value = $Config[$Name]
    if ($null -eq $value -or [string]::IsNullOrWhiteSpace([string]$value)) {
        throw "Missing required config value '$Name' in $ConfigPath"
    }

    return [string]$value
}

function Get-ConfigValue {
    param(
        [hashtable]$Config,
        [string[]]$Names
    )

    foreach ($name in $Names) {
        if ($Config.ContainsKey($name)) {
            $value = $Config[$name]
            if ($null -ne $value -and -not [string]::IsNullOrWhiteSpace([string]$value)) {
                return [string]$value
            }
        }
    }

    return $null
}

function Require-AnyConfigValue {
    param(
        [hashtable]$Config,
        [string[]]$Names,
        [string]$Label
    )

    $value = Get-ConfigValue -Config $Config -Names $Names
    if ($null -eq $value) {
        throw "Missing required config value '$Label' in $ConfigPath"
    }

    return $value
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$resolvedConfigPath = Resolve-Path -LiteralPath $ConfigPath
$config = Get-TmcSimConfig -Path $resolvedConfigPath

$sshHost = Require-AnyConfigValue -Config $config -Names @('TmcSshHost', 'SshHost') -Label 'TmcSshHost'
$sshUser = Require-AnyConfigValue -Config $config -Names @('TmcSshUser', 'SshUser') -Label 'TmcSshUser'
$remoteDbHost = Require-AnyConfigValue -Config $config -Names @('TmcRemoteDbHost', 'RemoteDbHost') -Label 'TmcRemoteDbHost'
$remoteDbPort = Require-AnyConfigValue -Config $config -Names @('TmcRemoteDbPort', 'RemoteDbPort') -Label 'TmcRemoteDbPort'
$localForwardPort = Require-AnyConfigValue -Config $config -Names @('TmcLocalForwardPort', 'LocalForwardPort') -Label 'TmcLocalForwardPort'

$sshPort = Get-ConfigValue -Config $config -Names @('TmcSshPort', 'SshPort')
if ([string]::IsNullOrWhiteSpace($sshPort)) {
    $sshPort = '22'
}

$sshKeyPath = Require-AnyConfigValue -Config $config -Names @('TmcSshKeyPath', 'SshKeyPath') -Label 'TmcSshKeyPath'
if (-not (Test-Path -LiteralPath $sshKeyPath)) {
    throw "SSH key path not found: $sshKeyPath"
}

$resolvedKeyPath = Resolve-Path -LiteralPath $sshKeyPath

$sshArgs = @(
    '-i', [string]$resolvedKeyPath,
    '-p', $sshPort,
    '-o', 'ExitOnForwardFailure=yes',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-N',
    '-L', "127.0.0.1:${localForwardPort}:${remoteDbHost}:${remoteDbPort}",
    "${sshUser}@${sshHost}"
)

Write-Host "Opening SSH tunnel using $resolvedConfigPath"
Write-Host "ssh.exe -i <key> -p ${sshPort} -L 127.0.0.1:${localForwardPort}:${remoteDbHost}:${remoteDbPort} ${sshUser}@${sshHost}"
Write-Host 'Keep this terminal open while export and simulation tasks run.'

& ssh.exe @sshArgs
$exitCode = $LASTEXITCODE
if ($exitCode -ne 0) {
    throw "ssh.exe exited with code $exitCode"
}