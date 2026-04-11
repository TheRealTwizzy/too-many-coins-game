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
        throw "Missing required config value '$Label' in $resolvedConfigPath"
    }

    return $value
}

function Assert-CommandAvailable {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Name
    )

    $command = Get-Command -Name $Name -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw "Required command not found: $Name. Install OpenSSH client and ensure $Name is on PATH."
    }
}

function Require-ExistingPath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "$Label not found: $Path"
    }

    return [string](Resolve-Path -LiteralPath $Path)
}

function New-SshCommonArgs {
    param(
        [Parameter(Mandatory = $true)]
        [string]$IdentityPath,
        [Parameter(Mandatory = $true)]
        [string]$Port,
        [Parameter(Mandatory = $true)]
        [string]$ConnectTimeoutSeconds,
        [string]$KnownHostsPath
    )

    $args = @(
        '-i', $IdentityPath,
        '-p', $Port,
        '-o', 'BatchMode=yes',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'PubkeyAuthentication=yes',
        '-o', 'PasswordAuthentication=no',
        '-o', 'KbdInteractiveAuthentication=no',
        '-o', 'ChallengeResponseAuthentication=no',
        '-o', 'PreferredAuthentications=publickey',
        '-o', 'NumberOfPasswordPrompts=0',
        '-o', "ConnectTimeout=$ConnectTimeoutSeconds",
        '-o', 'StrictHostKeyChecking=yes'
    )

    if (-not [string]::IsNullOrWhiteSpace($KnownHostsPath)) {
        $args += @('-o', "UserKnownHostsFile=$KnownHostsPath")
    }

    return $args
}

function Get-SshFailureMessage {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Context,
        [Parameter(Mandatory = $true)]
        [string]$Target,
        [Parameter(Mandatory = $true)]
        [int]$ExitCode,
        [string]$OutputText
    )

    $detail = if ([string]::IsNullOrWhiteSpace($OutputText)) { '<no ssh output>' } else { $OutputText.Trim() }

    if ($detail -match 'Permission denied') {
        return "$Context failed for $Target because SSH key authentication was rejected. Confirm TmcSshIdentityFile/TmcSshKeyPath points to the correct private key and that the public key is installed for the SSH user. ssh.exe exit code: $ExitCode. ssh output: $detail"
    }

    if ($detail -match 'Host key verification failed|REMOTE HOST IDENTIFICATION HAS CHANGED|No .* host key is known') {
        return "$Context failed for $Target due to strict host key verification. Add or update the host key in known_hosts, then retry. ssh.exe exit code: $ExitCode. ssh output: $detail"
    }

    if ($detail -match 'Connection timed out|Connection refused|Could not resolve hostname|No route to host|Connection closed by remote host') {
        return "$Context failed for $Target due to network/connectivity errors. Verify SSH host, port, firewall rules, and DNS resolution. ssh.exe exit code: $ExitCode. ssh output: $detail"
    }

    if ($detail -match 'identity file .* not accessible') {
        return "$Context failed because the SSH identity file is not accessible. Confirm TmcSshIdentityFile/TmcSshKeyPath exists and is readable. ssh.exe exit code: $ExitCode. ssh output: $detail"
    }

    return "$Context failed for $Target. ssh.exe exit code: $ExitCode. ssh output: $detail"
}

function Invoke-SshPreflight {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$CommonArgs,
        [Parameter(Mandatory = $true)]
        [string]$Target
    )

    $preflightArgs = $CommonArgs + @('-o', 'RequestTTY=no', $Target, 'exit')

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $outputLines = @(& ssh.exe @preflightArgs 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        $outputText = ($outputLines | ForEach-Object { [string]$_ }) -join [Environment]::NewLine
        throw (Get-SshFailureMessage -Context 'SSH preflight authentication check' -Target $Target -ExitCode $exitCode -OutputText $outputText)
    }
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$resolvedConfigPath = [string](Resolve-Path -LiteralPath $ConfigPath)
$config = Get-TmcSimConfig -Path $resolvedConfigPath

Assert-CommandAvailable -Name 'ssh.exe'

$sshHost = Require-AnyConfigValue -Config $config -Names @('TmcSshHost', 'SshHost') -Label 'TmcSshHost'
$sshUser = Require-AnyConfigValue -Config $config -Names @('TmcSshUser', 'SshUser') -Label 'TmcSshUser'
$remoteDbHost = Require-AnyConfigValue -Config $config -Names @('TmcRemoteDbHost', 'RemoteDbHost') -Label 'TmcRemoteDbHost'
$remoteDbPort = Require-AnyConfigValue -Config $config -Names @('TmcRemoteDbPort', 'RemoteDbPort') -Label 'TmcRemoteDbPort'
$localForwardPort = Require-AnyConfigValue -Config $config -Names @('TmcLocalForwardPort', 'LocalForwardPort') -Label 'TmcLocalForwardPort'

$sshPort = Get-ConfigValue -Config $config -Names @('TmcSshPort', 'SshPort')
if ([string]::IsNullOrWhiteSpace($sshPort)) {
    $sshPort = '22'
}

$sshConnectTimeoutSeconds = Get-ConfigValue -Config $config -Names @('TmcSshConnectTimeoutSeconds', 'SshConnectTimeoutSeconds')
if ([string]::IsNullOrWhiteSpace($sshConnectTimeoutSeconds)) {
    $sshConnectTimeoutSeconds = '10'
}

$sshKeyPath = Require-AnyConfigValue -Config $config -Names @('TmcSshIdentityFile', 'TmcSshKeyPath', 'SshIdentityFile', 'SshKeyPath') -Label 'TmcSshIdentityFile'
$sshKnownHostsPath = Get-ConfigValue -Config $config -Names @('TmcSshKnownHostsPath', 'SshKnownHostsPath')

$resolvedKeyPath = Require-ExistingPath -Path $sshKeyPath -Label 'SSH identity file'
$resolvedKnownHostsPath = $null
if (-not [string]::IsNullOrWhiteSpace($sshKnownHostsPath)) {
    $resolvedKnownHostsPath = Require-ExistingPath -Path $sshKnownHostsPath -Label 'SSH known_hosts file'
}

$sshTarget = "${sshUser}@${sshHost}"
$sshCommonArgs = New-SshCommonArgs -IdentityPath $resolvedKeyPath -Port $sshPort -ConnectTimeoutSeconds $sshConnectTimeoutSeconds -KnownHostsPath $resolvedKnownHostsPath

Write-Host "Running SSH preflight check for $sshTarget (non-interactive key auth, strict host-key verification)."
Invoke-SshPreflight -CommonArgs $sshCommonArgs -Target $sshTarget

$sshArgs = $sshCommonArgs + @(
    '-o', 'ExitOnForwardFailure=yes',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-N',
    '-L', "127.0.0.1:${localForwardPort}:${remoteDbHost}:${remoteDbPort}",
    $sshTarget
)

Write-Host "Opening SSH tunnel using $resolvedConfigPath"
Write-Host "ssh.exe -i <key> -p ${sshPort} -o BatchMode=yes -o StrictHostKeyChecking=yes -L 127.0.0.1:${localForwardPort}:${remoteDbHost}:${remoteDbPort} ${sshTarget}"
Write-Host 'Keep this terminal open while export and simulation tasks run.'

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'

try {
    & ssh.exe @sshArgs
    $exitCode = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}

if ($exitCode -ne 0) {
    throw (Get-SshFailureMessage -Context 'SSH tunnel startup' -Target $sshTarget -ExitCode $exitCode)
}