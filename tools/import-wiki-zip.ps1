param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath,

    [string]$WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,

    [string]$ImportRoot = "wiki_source/imported"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $ZipPath)) {
    throw "ZIP not found: $ZipPath"
}

$resolvedWorkspace = (Resolve-Path $WorkspaceRoot).Path
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$targetRoot = Join-Path $resolvedWorkspace $ImportRoot
$destination = Join-Path $targetRoot $timestamp

New-Item -ItemType Directory -Path $destination -Force | Out-Null

Expand-Archive -LiteralPath $ZipPath -DestinationPath $destination -Force

$inventory = Join-Path $destination "_inventory.txt"
"Imported: $ZipPath" | Out-File -LiteralPath $inventory -Encoding utf8
"Timestamp: $timestamp" | Out-File -LiteralPath $inventory -Append -Encoding utf8
"" | Out-File -LiteralPath $inventory -Append -Encoding utf8
"Top-level contents:" | Out-File -LiteralPath $inventory -Append -Encoding utf8
Get-ChildItem -LiteralPath $destination | Select-Object Name, Mode, LastWriteTime |
    Format-Table -AutoSize | Out-String |
    Out-File -LiteralPath $inventory -Append -Encoding utf8

Write-Host "Wiki ZIP imported to: $destination"
Write-Host "Inventory file: $inventory"
Write-Host "Next step: copy curated content/assets into public/wiki/."
