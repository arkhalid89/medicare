#!/usr/bin/env bash
# Linux/macOS equivalent of build.ps1: tests, publish all four apps, then compile the three installers
# with Inno Setup running under Wine. Windows build PCs use build.ps1.
#
# One-time setup on Ubuntu/Debian:
#   sudo dpkg --add-architecture i386 && sudo apt-get update && sudo apt-get install wine64 wine32:i386 xvfb
#   download innosetup-6.5.x.exe from https://github.com/jrsoftware/issrc/releases and run:
#   WINEPREFIX=~/.wine-inno wine innosetup-6.5.x.exe /VERYSILENT /SUPPRESSMSGBOXES /DIR='C:\InnoSetup6'
#
# Usage: ./build.sh [--skip-tests] [--skip-installers]
# Environment: WINEPREFIX (default ~/.wine-inno), ISCC (default C:\InnoSetup6\ISCC.exe)
set -euo pipefail

root="$(cd "$(dirname "$0")" && pwd)"
artifacts="$root/artifacts"
skip_tests=0; skip_installers=0
for a in "$@"; do
  case "$a" in
    --skip-tests) skip_tests=1 ;;
    --skip-installers) skip_installers=1 ;;
    *) echo "Unknown option $a" >&2; exit 2 ;;
  esac
done

version="$(grep -oP '(?<=<Version>)[^<]+' "$root/Directory.Build.props" || echo 1.0.0)"
echo "MediCare Pharmacy $version (Release)"

if [ "$skip_tests" = 0 ]; then
  echo "==> Run tests"
  dotnet test "$root/MediCarePharmacy.sln" -c Release --nologo
fi

echo "==> Publish"
rm -rf "$artifacts"
common=(-c Release --nologo "-p:Version=$version")
self=(-r win-x64 --self-contained true -p:PublishSingleFile=false -p:PublishReadyToRun=true)
dotnet publish "$root/src/MediCare.Host/MediCare.Host.csproj" -o "$artifacts/host" "${common[@]}" "${self[@]}"
dotnet publish "$root/src/MediCare.HostMonitor/MediCare.HostMonitor.csproj" -o "$artifacts/hostmonitor" "${common[@]}" "${self[@]}"
dotnet publish "$root/src/MediCare.Client/MediCare.Client.csproj" -f net10.0-windows -o "$artifacts/client-win10" "${common[@]}" "${self[@]}"
dotnet publish "$root/src/MediCare.Client/MediCare.Client.csproj" -f net48 -o "$artifacts/client-win7" "${common[@]}"
for f in host/MediCare.Host.exe hostmonitor/MediCare.HostMonitor.exe client-win10/MediCarePharmacy.exe client-win7/MediCarePharmacy.exe; do
  [ -f "$artifacts/$f" ] || { echo "Publish output missing: $f" >&2; exit 1; }
done

if [ "$skip_installers" = 1 ]; then echo "Installers skipped (--skip-installers)."; exit 0; fi

export WINEPREFIX="${WINEPREFIX:-$HOME/.wine-inno}" WINEDEBUG=-all
iscc="${ISCC:-C:\\InnoSetup6\\ISCC.exe}"
command -v wine >/dev/null || { echo "Wine is not installed; see the setup notes at the top of build.sh." >&2; exit 1; }
# ISCC needs no window, but Wine wants a display; use a virtual one when there is none
if [ -z "${DISPLAY:-}" ] && command -v Xvfb >/dev/null; then
  Xvfb :97 -nolisten tcp >/dev/null 2>&1 & xvfb_pid=$!; export DISPLAY=:97
  trap 'kill $xvfb_pid 2>/dev/null' EXIT
fi
ls "$root"/installer/fonts/*.ttf >/dev/null 2>&1 || echo "WARNING: installer/fonts has no .ttf files; the installers will not install the MediCare fonts (see installer/fonts/README.md)."

win() { echo "Z:${1//\//\\}"; }
out="$artifacts/installers"; mkdir -p "$out"
compile() { echo "==> Installer: $1"; shift; wine "$iscc" /Qp "/DAppVersion=$version" "/O$(win "$out")" "$@"; echo; }
compile host "$(win "$root/installer/MediCareHost.iss")"
compile "client (Windows 10/11)" /DTarget=win10 "$(win "$root/installer/MediCareClient.iss")"
compile "client (Windows 7)" /DTarget=win7 "$(win "$root/installer/MediCareClient.iss")"

echo "Installers written to $out"
ls -lh "$out"/*.exe
