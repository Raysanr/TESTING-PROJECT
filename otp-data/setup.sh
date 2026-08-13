#!/usr/bin/env bash
# Downloads and prepares the GTFS + OSM data OTP needs to build the routing
# graph. Run this once (or whenever otp-data/ is empty) before `docker compose up`.
set -euo pipefail
OTP_DATA_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$OTP_DATA_DIR"

if ! command -v osmium >/dev/null 2>&1; then
  echo "osmium-tool is required. Install it first:"
  echo "  macOS:  brew install osmium-tool"
  echo "  Linux:  apt-get install osmium-tool  (or your distro's equivalent)"
  exit 1
fi

echo "==> Downloading jeepney/bus/train GTFS feed (sakayph/gtfs)..."
curl -sSL -o /tmp/gtfs-raw.zip https://github.com/sakayph/gtfs/archive/refs/heads/master.zip
rm -rf /tmp/gtfs-extract
mkdir /tmp/gtfs-extract
unzip -q /tmp/gtfs-raw.zip -d /tmp/gtfs-extract

echo "==> Patching calendar.txt (source feed's service window expired 2020-06-30)..."
sed -i.bak 's/,20130617,20200630/,20130617,20301231/g' /tmp/gtfs-extract/gtfs-master/calendar.txt

echo "==> Repackaging as gtfs-jeepney-bus.zip..."
rm -f gtfs-jeepney-bus.zip
(cd /tmp/gtfs-extract/gtfs-master && zip -q -j "$OTP_DATA_DIR/gtfs-jeepney-bus.zip" ./*.txt)
rm -rf /tmp/gtfs-raw.zip /tmp/gtfs-extract

echo "==> Downloading Philippines OSM extract (Geofabrik, ~600MB)..."
curl -sSL -o /tmp/philippines-latest.osm.pbf https://download.geofabrik.de/asia/philippines-latest.osm.pbf

echo "==> Clipping to Metro Manila + neighboring provinces (full-country extract OOMs the graph builder)..."
osmium extract -b 120.85,14.25,121.20,14.85 /tmp/philippines-latest.osm.pbf -o metro-manila.osm.pbf --overwrite
rm -f /tmp/philippines-latest.osm.pbf

echo "==> Done. otp-data/ is ready:"
ls -lh gtfs-jeepney-bus.zip metro-manila.osm.pbf

echo ""
echo "Note: sakayph/gtfs already includes MRT-3, so rail routing works out of"
echo "the box. LRT-1/2 and PNR are NOT in this feed — their source (manila.zip"
echo "from hub.tumidata.org) was unreachable when this script was written."
echo "Grab it manually from https://hub.tumidata.org/dataset/gtfs-manila and"
echo "drop it in otp-data/ if you want those lines too."
