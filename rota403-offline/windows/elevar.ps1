# Abre outro script desta pasta com privilégios de administrador (usado pelos atalhos admin-*.cmd).
param([Parameter(Mandatory = $true)][string]$Script, [Parameter(ValueFromRemainingArguments = $true)][string[]]$Resto)
$alvo = Join-Path $PSScriptRoot $Script
$lista = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-NoExit', '-File', ('"{0}"' -f $alvo)) + $Resto
Start-Process -FilePath 'powershell.exe' -ArgumentList $lista -Verb RunAs
