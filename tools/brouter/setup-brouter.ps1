<#
  ElTouro - BRouter-Testserver auf einem Windows-10/11-Rechner einrichten.

  Ausfuehren: Rechtsklick auf die Datei -> "Mit PowerShell ausfuehren".
  Das Skript holt sich selbst Administratorrechte (fuer die Firewall-Regel).

  Was es tut:
    1. Java 11+ suchen (die neueste installierte Version wird genommen)
    2. BRouter herunterladen und nach C:\brouter entpacken
    3. Kartendaten fuer ganz Deutschland laden (ca. 1,5 GB, Abbruch und Neustart sind kein Problem)
    4. C:\brouter\start.cmd schreiben
    5. Firewall: Port 17777 NUR fuer den ElTouro-Server und das Heimnetz oeffnen
    6. BRouter starten und eine Testroute in Bonn rechnen

  Die Portweiterleitung am Router macht das Skript nicht - die steht am Ende in der Ausgabe.
  Mehrfach ausfuehren ist ok: Fertiges wird uebersprungen.
#>
param(
    [string]$ServerIp = '85.13.146.178',   # ausgehende IP von test.eltouro.de (siehe /check)
    [string]$Dir = 'C:\brouter',
    [int]$Port = 17777,
    [string]$Version = '1.7.10'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$RuleName = 'BRouter ElTouro'
# 5x5-Grad-Kacheln, die Deutschland abdecken
$Tiles = 'E5_N45', 'E5_N50', 'E5_N55', 'E10_N45', 'E10_N50', 'E15_N50'

function Step($text) { Write-Host ''; Write-Host "==> $text" -ForegroundColor Cyan }
function Ok($text) { Write-Host "    $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    $text" -ForegroundColor Yellow }
function Capture($exe, $arguments) {
    # Ausgabe eines Programms einsammeln (java -version schreibt auf stderr)
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = $exe; $psi.Arguments = $arguments; $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $p = [Diagnostics.Process]::Start($psi)
    $err = $p.StandardError.ReadToEndAsync()
    $out = $p.StandardOutput.ReadToEnd()
    $p.WaitForExit()
    return $out + $err.Result
}
function Fail($text) {
    Write-Host ''; Write-Host "FEHLER: $text" -ForegroundColor Red
    Read-Host 'Enter zum Schliessen'; exit 1
}

# --- Administratorrechte holen (Fenster oeffnet sich neu) ---
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $argList = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -ServerIp $ServerIp -Dir `"$Dir`" -Port $Port -Version $Version"
    Start-Process powershell.exe -Verb RunAs -ArgumentList $argList
    exit
}

try {
    $curl = (Get-Command curl.exe -ErrorAction SilentlyContinue).Source
    if (-not $curl) { Fail 'curl.exe fehlt - Windows 10 ist zu alt (vor Version 1803). Bitte Windows aktualisieren.' }

    # --- 1. Java ---
    Step 'Java suchen'
    $candidates = @()
    $onPath = Get-Command java.exe -All -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath | ForEach-Object { $_.Source } }
    foreach ($root in 'C:\Program Files\Java', 'C:\Program Files\Eclipse Adoptium', 'C:\Program Files\Microsoft', 'C:\Program Files\Zulu') {
        if (Test-Path $root) {
            $candidates += Get-ChildItem $root -Directory -ErrorAction SilentlyContinue |
                           ForEach-Object { Join-Path $_.FullName 'bin\java.exe' } | Where-Object { Test-Path $_ }
        }
    }
    $java = $null; $javaMajor = 0
    foreach ($c in ($candidates | Select-Object -Unique)) {
        $out = Capture $c '-version'
        if ($out -match 'version "(\d+)(?:\.(\d+))?') {
            $major = [int]$Matches[1]
            if ($major -eq 1 -and $Matches[2]) { $major = [int]$Matches[2] }   # alte Schreibweise 1.8.0_xxx
            if ($major -gt $javaMajor) { $javaMajor = $major; $java = $c }
        }
    }
    if (-not $java -or $javaMajor -lt 11) {
        Fail "Kein Java 11 oder neuer gefunden (gefunden: Java $javaMajor). Bitte ein JDK installieren, z.B. JDK 25 von https://adoptium.net"
    }
    # javapath-Verknuepfungen von Oracle auf die echte java.exe aufloesen (wichtig fuer die Firewall-Regel)
    $props = Capture $java '-XshowSettings:properties -version'
    if ($props -match '(?m)^\s*java\.home = (.+?)\s*$' -and (Test-Path (Join-Path $Matches[1].Trim() 'bin\java.exe'))) {
        $java = Join-Path $Matches[1].Trim() 'bin\java.exe'
    }
    Ok "Java $javaMajor : $java"

    # --- 2. BRouter ---
    Step "BRouter $Version nach $Dir"
    New-Item -ItemType Directory -Force -Path $Dir, "$Dir\segments4", "$Dir\customprofiles" | Out-Null
    $jar = Get-ChildItem $Dir -Filter "brouter-$Version-all.jar" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($jar -and (Test-Path "$Dir\profiles2\trekking.brf")) {
        Ok 'schon vorhanden'
    } else {
        $zip = Join-Path $env:TEMP "brouter-$Version.zip"
        $tmp = Join-Path $env:TEMP "brouter-$Version-unpacked"
        & $curl -L --fail --silent --show-error -o $zip "https://github.com/abrensch/brouter/releases/download/v$Version/brouter-$Version.zip"
        if ($LASTEXITCODE -ne 0) { Fail 'Download von GitHub fehlgeschlagen.' }
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
        Expand-Archive -Path $zip -DestinationPath $tmp -Force
        # Das Zip hat einen Oberordner brouter-x.y.z - dessen Inhalt kommt direkt nach C:\brouter
        $inner = Get-ChildItem $tmp -Recurse -Filter "brouter-$Version-all.jar" | Select-Object -First 1
        if (-not $inner) { Fail "brouter-$Version-all.jar nicht im Zip gefunden." }
        Copy-Item -Path (Join-Path $inner.DirectoryName '*') -Destination $Dir -Recurse -Force
        Remove-Item $tmp -Recurse -Force; Remove-Item $zip -Force
        Ok 'entpackt'
    }

    # --- 3. Kartendaten ---
    Step 'Kartendaten Deutschland (segments4)'
    $free = (Get-PSDrive -Name $Dir.Substring(0, 1)).Free
    if ($free -lt 3GB) { Warn ("Nur noch {0:N1} GB frei - es werden ca. 1,5 GB gebraucht." -f ($free / 1GB)) }
    foreach ($t in $Tiles) {
        $file = "$Dir\segments4\$t.rd5"
        $url = "https://brouter.de/brouter/segments4/$t.rd5"
        $head = & $curl -sIL $url
        $len = ($head | Select-String -Pattern '^Content-Length:\s*(\d+)' | Select-Object -Last 1)
        $remote = if ($len) { [long]$len.Matches[0].Groups[1].Value } else { -1 }
        if ((Test-Path $file) -and $remote -gt 0 -and (Get-Item $file).Length -eq $remote) {
            Ok "$t schon da"; continue
        }
        Write-Host ("    {0} laden ({1:N0} MB) ..." -f $t, ($remote / 1MB))
        & $curl -L --fail --retry 3 -C - --progress-bar -o $file $url
        if ($LASTEXITCODE -ne 0) { Fail "Download $t fehlgeschlagen - Skript einfach nochmal starten, es setzt fort." }
        Ok "$t fertig"
    }

    # --- 4. start.cmd ---
    # 4 Threads: bei nur 1 bricht BRouter eine laufende Route nach 2 s ab, sobald eine zweite Verbindung kommt
    # (z. B. die favicon-Anfrage des Browsers) - die erste Route nach dem Start dauert wegen der Kacheln laenger.
    Step 'start.cmd schreiben'
    $startCmd = "$Dir\start.cmd"
    @(
        '@echo off'
        "title BRouter ElTouro - Port $Port (Fenster offen lassen)"
        "cd /d `"$Dir`""
        "`"$java`" -Xmx512M -Xms128M -Xmn8M -DmaxRunningTime=60 -DuseRFCMimeType=false -cp brouter-$Version-all.jar btools.server.RouteServer segments4 profiles2 customprofiles $Port 4"
        'pause'
    ) | Set-Content -Path $startCmd -Encoding ASCII
    Ok $startCmd

    # --- 5. Firewall ---
    Step "Firewall: Port $Port nur fuer $ServerIp und das Heimnetz"
    Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    # Ein abgebrochener Windows-Dialog "Java zulassen?" hinterlaesst Sperrregeln, die jede Freigabe schlagen
    $blocks = Get-NetFirewallApplicationFilter | Where-Object { $_.Program -ieq $java } | Get-NetFirewallRule |
              Where-Object { $_.Direction -eq 'Inbound' -and $_.Action -eq 'Block' }
    foreach ($b in $blocks) { Warn "entferne Sperrregel '$($b.DisplayName)' fuer diese java.exe"; $b | Remove-NetFirewallRule }
    # Regel fuer genau diese java.exe und diesen Port - damit fragt Windows beim Start auch nicht nach
    New-NetFirewallRule -DisplayName $RuleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port `
        -Program $java -RemoteAddress $ServerIp, 'LocalSubnet' -Profile Any | Out-Null
    Ok "Regel '$RuleName' angelegt"

    # --- 6. Starten und testen ---
    Step 'BRouter starten'
    $running = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    if ($running) {
        Warn "Auf Port $Port laeuft schon etwas - ich starte nicht nochmal (altes BRouter-Fenster schliessen, um neu zu starten)."
    } else {
        Start-Process cmd.exe -ArgumentList '/c', "`"$startCmd`""
    }
    $test = "http://localhost:$Port/brouter?lonlats=7.0982,50.7374|7.1166,50.7333&profile=trekking&alternativeidx=0&format=geojson"
    $okRoute = $false
    for ($i = 0; $i -lt 30 -and -not $okRoute; $i++) {
        Start-Sleep -Seconds 2
        $body = (& $curl -s --max-time 20 $test) -join ''
        $okRoute = $body -match '"coordinates"'
    }
    if (-not $okRoute) { Fail "BRouter antwortet nicht auf $test - Fehlermeldung im BRouter-Fenster ansehen." }
    Ok 'Testroute Bonn erfolgreich gerechnet'

    # --- Was noch zu tun ist ---
    $lan = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
           Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1 -ExpandProperty IPAddress
    $public = (& $curl -s --max-time 5 https://api.ipify.org) -join ''
    Write-Host ''
    Write-Host '==================== FERTIG ====================' -ForegroundColor Green
    Write-Host "Im Heimnetz erreichbar unter:  http://${lan}:$Port/brouter"
    Write-Host ''
    Write-Host 'Jetzt noch am Router (FRITZ!Box: Internet -> Freigaben -> Portfreigaben):'
    Write-Host "  Geraet: dieser PC ($lan), TCP, Port extern 43117 -> intern $Port"
    Write-Host ''
    Write-Host 'Dann in config.php auf test.eltouro.de:'
    Write-Host "  'brouter' => ['url' => 'http://<MyFRITZ-Adresse>:43117/brouter', 'profile' => 'trekking', 'profile_2027' => 'trekking', 'timeout' => 10],"
    if ($public) { Write-Host "  (Zum schnellen Test geht statt MyFRITZ auch die aktuelle IP $public - die aendert sich aber.)" }
    Write-Host ''
    Write-Host 'Nach dem Test: Portfreigabe am Router wieder aus. Neustart von BRouter: C:\brouter\start.cmd'
    Read-Host 'Enter zum Schliessen'
} catch {
    Fail $_.Exception.Message
}
