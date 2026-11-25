<#
.SYNOPSIS
    Provisions the Couriva member accounts in Active Directory.
.DESCRIPTION
    Creates accounts under OU=CourivaUsers,DC=couriva,DC=com.
    Prompts once for a password and applies it to every new account.
    Existing sAMAccountName values are skipped, never overwritten.
    Run on the Domain Controller (or a management host with RSAT) from an elevated PowerShell.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "Run this script from an elevated PowerShell (Run as Administrator)."
    exit 1
}

Import-Module ActiveDirectory -ErrorAction Stop

$ou = 'OU=CourivaUsers,DC=couriva,DC=com'
$domain = 'couriva.com'

$users = @(
    @{ Sam = 'augusto';    Sn = 'Augusto';  Given = 'Ryle Ian' },
    @{ Sam = 'cuyag';      Sn = 'Cuyag';    Given = 'Johndel' },
    @{ Sam = 'talaba';     Sn = 'Talaba';   Given = 'Lyndel' },
    @{ Sam = 'cabatingan'; Sn = 'Cabatingan'; Given = 'Mark' },
    @{ Sam = 'cabezas';    Sn = 'Cabezas';  Given = 'Kevin' },
    @{ Sam = 'espinosa';   Sn = 'Espinosa'; Given = 'Kate Carl' },
    @{ Sam = 'bosque';     Sn = 'Bosque';   Given = 'Dency Marie' },
    @{ Sam = 'baslag';     Sn = 'Baslag';   Given = 'Rhea' },
    @{ Sam = 'albiso';     Sn = 'Albiso';   Given = 'Ryza' },
    @{ Sam = 'monea';      Sn = 'Monea';    Given = 'Neah' },
    @{ Sam = 'fuentes';    Sn = 'Fuentes';  Given = 'Sheryl' },
    @{ Sam = 'seno';       Sn = 'Seno';     Given = 'Deter Troy' },
    @{ Sam = 'olivar';     Sn = 'Olivar';   Given = 'Jaff' }
)

Get-ADOrganizationalUnit -Identity $ou -ErrorAction Stop | Out-Null

$securePassword = Read-Host 'Password for all new accounts' -AsSecureString

$created = 0
$skipped = 0

foreach ($u in $users) {
    $sam = $u.Sam
    $upn = "$sam@$domain"
    $name = "$($u.Sn), $($u.Given)"

    if (Get-ADUser -Filter "sAMAccountName -eq '$sam'" -ErrorAction SilentlyContinue) {
        Write-Warning "Skipped $sam - account already exists."
        $skipped++
        continue
    }

    New-ADUser -Name $name -DisplayName $name `
        -GivenName $u.Given -Surname $u.Sn `
        -SamAccountName $sam -UserPrincipalName $upn -EmailAddress $upn `
        -Path $ou -AccountPassword $securePassword `
        -Enabled $true -PasswordNeverExpires $false -ChangePasswordAtLogon $false `
        -ErrorAction Stop | Out-Null

    Write-Host "Created $sam ($upn)" -ForegroundColor Green
    $created++
}

Write-Host "`nCreated: $created | Skipped: $skipped" -ForegroundColor Cyan
Write-Host "Accounts in $ou :" -ForegroundColor Cyan
Get-ADUser -Filter * -SearchBase $ou -Properties UserPrincipalName, mail |
    Sort-Object SamAccountName |
    Select-Object SamAccountName, UserPrincipalName, mail |
    Format-Table -AutoSize
