<#
.SYNOPSIS
    Configures Windows Server 2022 / IIS & DNS for iPhone Personal Hotspot (172.20.10.2).
.DESCRIPTION
    1. Sets active network adapter to static IP 172.20.10.2 / 255.255.255.240 (/28), Gateway 172.20.10.1, DNS 172.20.10.2.
    2. Changes network connection profile to Private (unblocks inbound ports).
    3. Opens Windows Firewall for HTTP (80), HTTPS (443), and DNS (53 UDP/TCP).
    4. Updates Windows DNS Server for couriva.com (@, www, mail, couriva -> 172.20.10.2).
    5. Configures DNS Forwarders (172.20.10.1, 1.1.1.1) so devices keep full internet.
    6. Ensures IIS site bindings accept couriva.com / All Unassigned and restarts IIS.
#>

[CmdletBinding()]
param(
    [string]$IPAddress = "172.20.10.2",
    [int]$PrefixLength = 28, # iOS hotspot standard /28 (255.255.255.240)
    [string]$Gateway = "172.20.10.1",
    [string]$DNS = "172.20.10.2",
    [string]$ZoneName = "couriva.com",
    [string]$SiteName = "CourivaWebMail"
)

# Enforce Administrator privileges
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "This script must be run as Administrator! Please right-click configure-hotspot-ip.bat and select 'Run as Administrator'."
    exit 1
}

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "   Couriva WebMail - Windows Server Hotspot & DNS Setup          " -ForegroundColor Cyan
Write-Host "   Target IP: $IPAddress | Gateway: $Gateway                     " -ForegroundColor Cyan
Write-Host "   Domain: $ZoneName (mail.$ZoneName, www.$ZoneName)            " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

# 1. Detect active network adapter
$adapter = Get-NetAdapter | Where-Object { $_.Status -eq 'Up' -and $_.InterfaceDescription -notlike '*Virtual*' -and $_.InterfaceDescription -notlike '*Loopback*' } | Select-Object -First 1
if (-not $adapter) {
    $adapter = Get-NetAdapter | Where-Object { $_.Status -eq 'Up' } | Select-Object -First 1
}

if (-not $adapter) {
    Write-Warning "No active network adapter found in 'Up' status. Listing all adapters:"
    Get-NetAdapter | Format-Table Name, InterfaceDescription, Status
    Write-Error "Please connect the Windows Server to the iPhone Wi-Fi Hotspot first and re-run."
    exit 1
}

Write-Host "`n[1/6] Found active adapter: $($adapter.Name) ($($adapter.InterfaceDescription))" -ForegroundColor Green

# 2. Configure Static IP on adapter
try {
    $existingIP = Get-NetIPAddress -InterfaceAlias $adapter.Name -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -eq $IPAddress }
    if ($existingIP) {
        Write-Host "  [*] Adapter already has IP $IPAddress assigned." -ForegroundColor Yellow
    } else {
        Remove-NetIPAddress -InterfaceAlias $adapter.Name -AddressFamily IPv4 -Confirm:$false -ErrorAction SilentlyContinue
        Remove-NetRoute -InterfaceAlias $adapter.Name -AddressFamily IPv4 -Confirm:$false -ErrorAction SilentlyContinue
        New-NetIPAddress -InterfaceAlias $adapter.Name -IPAddress $IPAddress -PrefixLength $PrefixLength -DefaultGateway $Gateway -ErrorAction Stop | Out-Null
        Write-Host "  [+] Set static IP: $IPAddress / PrefixLength: $PrefixLength (Gateway: $Gateway)" -ForegroundColor Green
    }
    # Server points DNS to itself (172.20.10.2) + fallback (172.20.10.1, 1.1.1.1)
    Set-DnsClientServerAddress -InterfaceAlias $adapter.Name -ServerAddresses @($IPAddress, "127.0.0.1", $Gateway, "1.1.1.1")
    Write-Host "  [+] Configured DNS client addresses: $IPAddress, 127.0.0.1, $Gateway" -ForegroundColor Green
} catch {
    Write-Warning "  [!] IP configuration notice: $($_.Exception.Message)"
}

# 3. Set Network Category to Private
try {
    Get-NetConnectionProfile -InterfaceAlias $adapter.Name | Set-NetConnectionProfile -NetworkCategory Private
    Write-Host "`n[2/6] Connection profile set to 'Private' (Firewall permits LAN traffic)" -ForegroundColor Green
} catch {
    Write-Warning "  [!] Connection profile notice: $($_.Exception.Message)"
}

# 4. Open Windows Firewall for HTTP, HTTPS, and DNS
Write-Host "`n[3/6] Configuring Windows Firewall rules..." -ForegroundColor Green
$rules = @(
    @{ Name = "Couriva WebMail (HTTP 80)"; Port = 80; Protocol = "TCP" },
    @{ Name = "Couriva WebMail (HTTPS 443)"; Port = 443; Protocol = "TCP" },
    @{ Name = "Couriva DNS (Port 53 UDP)"; Port = 53; Protocol = "UDP" },
    @{ Name = "Couriva DNS (Port 53 TCP)"; Port = 53; Protocol = "TCP" }
)

foreach ($r in $rules) {
    if (-not (Get-NetFirewallRule -DisplayName $r.Name -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -DisplayName $r.Name -Direction Inbound -LocalPort $r.Port -Protocol $r.Protocol -Action Allow -Profile Any -ErrorAction SilentlyContinue | Out-Null
        Write-Host "  [+] Created firewall rule: $($r.Name)" -ForegroundColor Green
    } else {
        Write-Host "  [*] Rule already active: $($r.Name)" -ForegroundColor Yellow
    }
}

# 5. Update Windows DNS Server records for couriva.com
Write-Host "`n[4/6] Updating Windows DNS Server zone ($ZoneName)..." -ForegroundColor Green
try {
    if (Get-DnsServerZone -Name $ZoneName -ErrorAction SilentlyContinue) {
        $hostnames = @("@", "www", "mail", "couriva")
        foreach ($h in $hostnames) {
            $old = Get-DnsServerResourceRecord -ZoneName $ZoneName -Name $h -RRType A -ErrorAction SilentlyContinue
            if ($old) {
                $new = $old.Clone()
                $new.RecordData.IPv4Address = [System.Net.IPAddress]::Parse($IPAddress)
                Set-DnsServerResourceRecord -ZoneName $ZoneName -OldInputObject $old -NewInputObject $new -Force -ErrorAction SilentlyContinue
                Write-Host "  [+] Updated DNS A-record: $h.$ZoneName -> $IPAddress" -ForegroundColor Green
            } else {
                Add-DnsServerResourceRecordA -ZoneName $ZoneName -Name $h -IPv4Address $IPAddress -CreatePtr:$false -ErrorAction SilentlyContinue
                Write-Host "  [+] Created DNS A-record: $h.$ZoneName -> $IPAddress" -ForegroundColor Green
            }
        }
        # Configure DNS Forwarders so devices using this DNS server still have internet
        Set-DnsServerForwarder -IPAddress @($Gateway, "1.1.1.1", "8.8.8.8") -ErrorAction SilentlyContinue
        Write-Host "  [+] Configured DNS Forwarders: $Gateway (iPhone Hotspot), 1.1.1.1 (Cloudflare), 8.8.8.8 (Google)" -ForegroundColor Green
        Clear-DnsServerCache -Force -ErrorAction SilentlyContinue
        Write-Host "  [+] Flushed DNS Server Cache." -ForegroundColor Green
    } else {
        Write-Warning "  [!] DNS Zone '$ZoneName' not found on this server. Check Active Directory DNS."
    }
} catch {
    Write-Warning "  [!] DNS Server configuration notice: $($_.Exception.Message)"
}

# 6. Ensure IIS Site Bindings & Restart IIS
Write-Host "`n[5/6] Verifying IIS Web Site bindings..." -ForegroundColor Green
try {
    Import-Module WebAdministration -ErrorAction SilentlyContinue
    if (Get-Module WebAdministration) {
        $targetSite = if (Test-Path "IIS:\Sites\$SiteName") { $SiteName } elseif (Test-Path "IIS:\Sites\Default Web Site") { "Default Web Site" } else { (Get-Website | Select-Object -First 1).Name }
        if ($targetSite) {
            $httpBind = Get-WebBinding -Name $targetSite -Port 80
            if (-not $httpBind) {
                New-WebBinding -Name $targetSite -Port 80 -IPAddress "*"
                Write-Host "  [+] Added HTTP Port 80 binding (*:80:) to $targetSite" -ForegroundColor Green
            }
            iisreset /restart | Out-Null
            Write-Host "  [+] IIS service restarted successfully." -ForegroundColor Green
        }
    }
} catch {
    Write-Warning "  [!] IIS binding check notice: $($_.Exception.Message)"
}

Write-Host "`n[6/6] VERIFICATION & CLIENT INSTRUCTIONS" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "Couriva is now listening on: $IPAddress" -ForegroundColor Green
Write-Host "DNS is serving:" -ForegroundColor Green
Write-Host "  - http://couriva.com/" -ForegroundColor White
Write-Host "  - http://mail.couriva.com/" -ForegroundColor White
Write-Host "  - http://www.couriva.com/" -ForegroundColor White
Write-Host ""
Write-Host "HOW TO CONNECT OTHER DEVICES (iPhone, Android, Laptops):" -ForegroundColor Yellow
Write-Host "1. Connect device to iPhone Hotspot Wi-Fi." -ForegroundColor White
Write-Host "2. In device Wi-Fi Settings:" -ForegroundColor White
Write-Host "   - iPhone/iPad: Tap (i) on the Wi-Fi network -> Configure DNS -> Manual" -ForegroundColor White
Write-Host "                  Remove existing DNS and ADD SERVER: $IPAddress" -ForegroundColor White
Write-Host "   - Android: Tap gear on Wi-Fi -> Advanced / IP Settings -> Static" -ForegroundColor White
Write-Host "              Set DNS 1: $IPAddress (keep IP/Gateway as assigned)" -ForegroundColor White
Write-Host "   - Laptop (Windows/Mac): Edit Wi-Fi DNS -> set to $IPAddress" -ForegroundColor White
Write-Host "     (OR add to hosts file: $IPAddress couriva.com mail.couriva.com www.couriva.com)" -ForegroundColor White
Write-Host "3. Open browser on device and visit: http://couriva.com/ or http://mail.couriva.com/" -ForegroundColor Green
Write-Host "=================================================================`n" -ForegroundColor Cyan
