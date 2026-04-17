#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Package BigCommerce for WordPress (Suma) plugin for distribution
.DESCRIPTION
    Creates a distributable ZIP file of the plugin, excluding development files,
    tests, source JS, and unnecessary folders. The resulting package can be
    installed via WordPress admin interface or deployed directly.
.EXAMPLE
    .\package-plugin.ps1
#>

[CmdletBinding()]
param()

# Configuration
$pluginName = "bigcommerce-suma"
$pluginFile = "bigcommerce.php"

# Read version from the main plugin file header.
$pluginVersion = (Select-String -Path ".\$pluginFile" -Pattern "^\s*Version:\s*(.+)$" | ForEach-Object { $_.Matches[0].Groups[1].Value.Trim() })
if (-not $pluginVersion) {
    Write-Host "ERROR: Could not read version from $pluginFile" -ForegroundColor Red
    exit 1
}

$buildDir = ".\dist"
$packageDir = "$buildDir\$pluginName"
$outputZip = "$buildDir\$pluginName-$pluginVersion.zip"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  BigCommerce (Suma) Plugin Packager" -ForegroundColor Cyan
Write-Host "  Version: $pluginVersion" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Validate that required directories exist
$requiredDirs = @("assets", "src", "vendor", "templates")
foreach ($requiredDir in $requiredDirs) {
    if (-not (Test-Path $requiredDir)) {
        Write-Host "ERROR: Required directory '$requiredDir' not found!" -ForegroundColor Red
        exit 1
    }
}

# Validate JS dist files exist
if (-not (Test-Path "assets\js\dist")) {
    Write-Host ""
    Write-Host "ERROR: assets\js\dist directory not found!" -ForegroundColor Red
    Write-Host "The compiled JavaScript files are required for the plugin to work." -ForegroundColor Red
    Write-Host ""
    exit 1
}

# Clean previous builds
Write-Host "Cleaning previous builds..." -ForegroundColor Yellow
if (Test-Path $buildDir) {
    Remove-Item -Path $buildDir -Recurse -Force
}
New-Item -ItemType Directory -Path $packageDir -Force | Out-Null

# Files and directories to include
$includePaths = @(
    "bigcommerce.php",
    "build-timestamp.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE",
    "assets",
    "src",
    "templates",
    "vendor"
)

# Copy included files and directories
Write-Host "Copying plugin files..." -ForegroundColor Yellow
foreach ($path in $includePaths) {
    if (Test-Path $path) {
        if (Test-Path $path -PathType Container) {
            Write-Host "  + $path/" -ForegroundColor Green
            Copy-Item -Path $path -Destination "$packageDir\$path" -Recurse -Force
        } else {
            Write-Host "  + $path" -ForegroundColor Green
            Copy-Item -Path $path -Destination $packageDir -Force
        }
    } else {
        Write-Host "  - $path (not found, skipping)" -ForegroundColor DarkGray
    }
}

# Remove development/source files that should not be distributed
Write-Host ""
Write-Host "Removing development files from package..." -ForegroundColor Yellow

# JS source and test directories (only dist should ship)
$devDirectories = @(
    "$packageDir\assets\js\src",
    "$packageDir\assets\js\test",
    "$packageDir\assets\pcss"
)

foreach ($devDir in $devDirectories) {
    if (Test-Path $devDir) {
        Remove-Item -Path $devDir -Recurse -Force
        Write-Host "  - Removed $($devDir.Replace($packageDir, ''))" -ForegroundColor DarkGray
    }
}

# Clean up development file patterns
$cleanupPatterns = @(
    "*.log",
    "*.md~",
    ".DS_Store",
    "Thumbs.db",
    ".phpcs.xml",
    "phpstan.neon",
    ".editorconfig",
    ".gitignore",
    ".gitattributes",
    "phpunit.xml",
    "phpunit.xml.dist",
    "codeception.yml",
    "codeception.dist.yml",
    ".travis.yml"
)

foreach ($pattern in $cleanupPatterns) {
    Get-ChildItem -Path $packageDir -Filter $pattern -Recurse -Force -ErrorAction SilentlyContinue | ForEach-Object {
        Remove-Item $_.FullName -Force
        Write-Host "  - Removed $($_.Name)" -ForegroundColor DarkGray
    }
}

# Create ZIP archive
Write-Host ""
Write-Host "Creating ZIP archive..." -ForegroundColor Yellow

if (Test-Path $outputZip) {
    Remove-Item -Path $outputZip -Force
}

try {
    # Change to dist directory to create zip with proper structure
    Push-Location $buildDir
    Compress-Archive -Path $pluginName -DestinationPath "$pluginName-$pluginVersion.zip" -CompressionLevel Optimal
    Pop-Location

    $zipSize = (Get-Item $outputZip).Length / 1MB
    Write-Host "  + Created: $outputZip" -ForegroundColor Green
    Write-Host "  + Size: $([math]::Round($zipSize, 2)) MB" -ForegroundColor Green
} catch {
    Write-Host "  ! Error creating ZIP: $_" -ForegroundColor Red
    Pop-Location
    exit 1
}

# Clean up temporary package directory
Write-Host ""
Write-Host "Cleaning up..." -ForegroundColor Yellow
Remove-Item -Path $packageDir -Recurse -Force
Write-Host "  + Removed temporary files" -ForegroundColor Green

# Summary
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Package Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Plugin: $pluginName v$pluginVersion" -ForegroundColor Green
Write-Host "Output: $outputZip" -ForegroundColor White
Write-Host ""
Write-Host "To install:" -ForegroundColor Yellow
Write-Host "  1. Go to WordPress Admin > Plugins > Add New" -ForegroundColor White
Write-Host "  2. Click 'Upload Plugin'" -ForegroundColor White
Write-Host "  3. Choose the ZIP file and click 'Install Now'" -ForegroundColor White
Write-Host ""
Write-Host "Or deploy directly:" -ForegroundColor Yellow
Write-Host "  Extract to wp-content/plugins/$pluginName/" -ForegroundColor White
Write-Host ""

# Verify package contents
Write-Host "Package contents (first 30 entries):" -ForegroundColor Yellow
try {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $fullZipPath = Resolve-Path $outputZip
    $zip = [System.IO.Compression.ZipFile]::OpenRead($fullZipPath)
    $zip.Entries | Select-Object -First 30 | ForEach-Object {
        Write-Host "  $($_.FullName)" -ForegroundColor DarkGray
    }
    if ($zip.Entries.Count -gt 30) {
        Write-Host "  ... and $($zip.Entries.Count - 30) more files" -ForegroundColor DarkGray
    }
    Write-Host ""
    Write-Host "Total files in package: $($zip.Entries.Count)" -ForegroundColor Green
    $zip.Dispose()
} catch {
    Write-Host "  (Unable to list contents: $_)" -ForegroundColor DarkGray
}
Write-Host ""
