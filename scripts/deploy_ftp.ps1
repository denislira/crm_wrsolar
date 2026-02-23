<#
Enhanced FTP deploy script (PowerShell)
Usage: run from the project root. Supports uploading:
  1) single file
  2) folder recursively
  3) glob pattern
  4) files modified according to `git status --porcelain` (if git is available)

The script preserves relative paths when uploading to the specified remote base directory.
#>

# --- CONFIGURAÇÃO (defina estas variáveis para pular prompts interativos) ---
# Exemplos:
# $AUTO_MODE = '1'                # 1=file, 2=folder, 3=glob, 4=git-modified
# $AUTO_PATH = 'configuracoes.php' # para mode 1: arquivo, mode 2: pasta, mode 3: padrão glob
# $AUTO_HOST = 'ftp.exemplo.com'
# $AUTO_PORT = 21
# $AUTO_USER = 'usuario'
# $AUTO_PASS = $null              # string com senha (inseguro em repo) ou $null para prompt
# $AUTO_USE_FTPS = $null         # $true/$false ou $null para perguntar
# $AUTO_REMOTE_BASE = '/public_html'
# ---------------------------------------------------------------------------

$AUTO_MODE = '1'
$AUTO_PATH = $null
$AUTO_HOST = 'ftp.wrsolare1.hospedagemdesites.ws'
$AUTO_PORT = 21
$AUTO_USER = 'wrsolare1'
$AUTO_PASS = 'Solare22@'
$AUTO_USE_FTPS = $null
$AUTO_REMOTE_BASE = '/public_html/crm'
$AUTO_INCLUDE_UNTRACKED = $false


function Read-PlainPassword {
    param()
    $secure = Read-Host "Senha FTP" -AsSecureString
    return [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
}

function Ensure-RemoteDirectory {
    param(
        [string]$ftpBaseUrl,
        [string]$dirPath,
        [System.Net.NetworkCredential]$cred,
        [bool]$enableSsl
    )
    # dirPath should be like /a/b/c
    $parts = $dirPath.Trim('/').Split('/') | Where-Object { $_ -ne '' }
    $acc = ''
    foreach ($p in $parts) {
        $acc = $acc + '/' + $p
        try {
            $url = "$ftpBaseUrl$acc"
            $req = [System.Net.FtpWebRequest]::Create($url)
            $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $req.Credentials = $cred
            $req.EnableSsl = $enableSsl
            $req.UsePassive = $true
            $req.UseBinary = $true
            $req.Timeout = 15000
            $resp = $req.GetResponse()
            $resp.Close()
        } catch {
            # ignore errors (directory may already exist)
        }
    }
}

function Upload-FileFtp {
    param(
        [string]$localFile,
        [string]$remoteUrl,
        [System.Net.NetworkCredential]$cred,
        [bool]$enableSsl
    )
    try {
        $req = [System.Net.FtpWebRequest]::Create($remoteUrl)
        $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
        $req.Credentials = $cred
        $req.UseBinary = $true
        $req.UsePassive = $true
        $req.EnableSsl = $enableSsl
        $bytes = [System.IO.File]::ReadAllBytes($localFile)
        $req.ContentLength = $bytes.Length
        $rs = $req.GetRequestStream()
        $rs.Write($bytes, 0, $bytes.Length)
        $rs.Close()
        $resp = $req.GetResponse()
        $resp.Close()
        Write-Host "Uploaded: $localFile -> $remoteUrl" -ForegroundColor Green
    } catch {
        Write-Error "Falha ao enviar $localFile: $($_.Exception.Message)"
    }
}

try {
    $projectRoot = (Get-Location).ProviderPath

    if (-not $AUTO_MODE) {
        Write-Host "Modo de envio:\n 1) Arquivo único\n 2) Pasta (recursiva)\n 3) Padrão (glob)\n 4) Arquivos modificados (git)"
        $mode = Read-Host "Escolha o modo (1/2/3/4)";
        if (-not $mode) { $mode = '1' }
    } else {
        $mode = $AUTO_MODE
    }

    $files = @()
    switch ($mode) {
        '1' {
            if ($AUTO_PATH) { $f = $AUTO_PATH } else { $f = Read-Host "Caminho do arquivo local (relativo ou absoluto)" }
            if (-not $f) { Write-Error 'Arquivo não informado'; exit 1 }
            $full = Resolve-Path -Path $f -ErrorAction Stop
            $files += $full.ProviderPath
        }
        '2' {
            if ($AUTO_PATH) { $dir = $AUTO_PATH } else { $dir = Read-Host "Caminho da pasta local (relativa ou absoluta)" }
            if (-not $dir) { Write-Error 'Pasta não informada'; exit 1 }
            if ($AUTO_PATH -and (Test-Path $AUTO_PATH) -and (Get-Item $AUTO_PATH).PSIsContainer) { $pattern = '*.*' }
            else { $pattern = Read-Host "Filtro de arquivos (ex: *.*) - pressione Enter para '*.*'"; if (-not $pattern) { $pattern = '*.*' } }
            $items = Get-ChildItem -Path $dir -Recurse -File -Filter $pattern
            if ($items) { $files += $items | ForEach-Object { $_.FullName } }
        }
        '3' {
            if ($AUTO_PATH) { $pattern = $AUTO_PATH } else { $pattern = Read-Host "Padrão glob (ex: assets/*.js ou **/*.php)" }
            if (-not $pattern) { Write-Error 'Padrão não informado'; exit 1 }
            # Use Get-ChildItem with -Include; run from project root for recursive search
            Push-Location $projectRoot
            $items = Get-ChildItem -Recurse -File -Include $pattern -ErrorAction SilentlyContinue
            Pop-Location
            if ($items) { $files += $items | ForEach-Object { $_.FullName } }
        }
        '4' {
            # git modified files
            try {
                $git = Get-Command git -ErrorAction Stop
                $output = git status --porcelain
                $lines = $output -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }
                foreach ($l in $lines) {
                    # format: XY path
                    $parts = $l -split '\s+', 2
                    if ($parts.Length -ge 2) {
                        $prefix = $parts[0]
                        $path = $parts[1]
                        # skip untracked (prefix '??') unless AUTO_INCLUDE_UNTRACKED is true
                        if ($prefix -eq '??' -and -not $AUTO_INCLUDE_UNTRACKED) { continue }
                        if (Test-Path $path) { $files += (Resolve-Path $path).ProviderPath }
                    }
                }
            } catch {
                Write-Error "Git não disponível ou erro ao executar git: $($_.Exception.Message)"; exit 1
            }
        }
        default { Write-Error 'Modo inválido'; exit 1 }
    }

    if (-not $files -or $files.Count -eq 0) { Write-Error 'Nenhum arquivo encontrado para enviar.'; exit 0 }

    # Host
    if ($AUTO_HOST) { $host = $AUTO_HOST } else { $host = Read-Host "FTP host (ex: ftp.example.com)" }
    if (-not $host) { Write-Error 'Host obrigatório'; exit 1 }

    # Port
    if ($AUTO_PORT) { $port = [int]$AUTO_PORT } else {
        $portInput = Read-Host "Porta (pressione Enter para 21)"
        if (-not $portInput) { $port = 21 } else { $port = [int]$portInput }
    }

    # User
    if ($AUTO_USER) { $user = $AUTO_USER } else { $user = Read-Host "Usuário FTP" }
    if (-not $user) { Write-Error 'Usuário obrigatório'; exit 1 }

    # FTPS
    if ($AUTO_USE_FTPS -ne $null) { $useFtps = [bool]$AUTO_USE_FTPS } else { $useFtpsAns = Read-Host "Usar FTPS (SSL/TLS) ? (s/N)"; $useFtps = $useFtpsAns -match '^[sS]' }

    # Password
    if ($AUTO_PASS) { $plainPass = $AUTO_PASS } else { $plainPass = Read-PlainPassword }
    $cred = New-Object System.Net.NetworkCredential($user,$plainPass)

    # remote base
    if ($AUTO_REMOTE_BASE) { $remoteBase = $AUTO_REMOTE_BASE } else { $remoteBase = Read-Host "Diretório remoto base (ex: /public_html)" }
    if (-not $remoteBase) { $remoteBase = '/' }
    if (-not $remoteBase.StartsWith('/')) { $remoteBase = '/' + $remoteBase }

    # ftp base url (use ftp://; EnableSsl toggles TLS)
    $ftpBaseUrl = "ftp://$host:$port"

    foreach ($f in $files) {
        $fullLocal = [System.IO.Path]::GetFullPath($f)
        $relative = $fullLocal.Substring($projectRoot.Length).TrimStart('\','/')
        $remotePath = ($remoteBase.TrimEnd('/') + '/' + ($relative -replace '\\','/'))

        # ensure remote dir exists
        $remoteDir = '/' + ($remotePath.TrimStart('/') -replace '/[^/]+$','')
        if ($remoteDir -eq '/') { $remoteDir = '/' }
        Ensure-RemoteDirectory -ftpBaseUrl $ftpBaseUrl -dirPath $remoteDir -cred $cred -enableSsl $useFtps

        $uploadUrl = "$ftpBaseUrl/$($remotePath.TrimStart('/'))"
        Upload-FileFtp -localFile $fullLocal -remoteUrl $uploadUrl -cred $cred -enableSsl $useFtps
    }

    Write-Host "Envio concluído." -ForegroundColor Green

} catch {
    Write-Error "Erro: $($_.Exception.Message)"
    exit 20
}
