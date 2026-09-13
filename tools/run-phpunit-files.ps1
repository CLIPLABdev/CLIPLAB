param(
    [Parameter(Mandatory = $true)]
    [string] $PhpBin,
    [Parameter(Mandatory = $true)]
    [string] $TestPath,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $AdditionalTestPath
)
if ([string]::IsNullOrWhiteSpace($PhpBin) -or -not (Test-Path -LiteralPath $PhpBin -PathType Leaf)) { exit 2 }
$failed = $false
foreach ($path in @($TestPath) + $AdditionalTestPath) {
    & $PhpBin 'vendor\bin\phpunit' $path
    if ($LASTEXITCODE -ne 0) { $failed = $true }
}
if ($failed) { exit 1 }
exit 0