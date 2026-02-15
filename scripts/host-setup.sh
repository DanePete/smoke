#!/usr/bin/env bash
#
# Smoke — Host-side setup script
#
# Run this from your project root (where .ddev/ lives):
#   bash web/modules/contrib/smoke/scripts/host-setup.sh
#
# This script:
#   1. Installs the Lullabot/ddev-playwright DDEV addon
#   2. Fixes the expired Sury PHP GPG key in the Docker build
#   3. Installs Playwright browsers (rebuilds the DDEV container)
#   4. Runs drush smoke:setup to finish configuration
#
set -euo pipefail

BOLD='\033[1m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
NC='\033[0m'

step()  { echo -e "  ${BLUE}▸${NC} $1"; }
ok()    { echo -e "    ${GREEN}✓${NC} $1"; }
warn()  { echo -e "    ${YELLOW}⚠${NC} $1"; }
fail()  { echo -e "    ${RED}✕${NC} $1"; exit 1; }

echo ""
echo -e "  ${BOLD}Smoke — Full Setup${NC}"
echo "  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ── Verify we're in a DDEV project ──
step "Checking for DDEV project..."
if [ ! -d ".ddev" ]; then
  fail "No .ddev/ directory found. Run this from your project root."
fi
ok "DDEV project found."

# ── Step 1: Install the Playwright addon ──
step "Checking for Playwright addon..."
if [ -f ".ddev/config.playwright.yml" ]; then
  ok "Lullabot/ddev-playwright already installed."
else
  echo "    Installing Lullabot/ddev-playwright..."
  ddev add-on get Lullabot/ddev-playwright
  ok "Addon installed."
fi

# ── Step 2: Fix expired Sury PHP GPG key ──
step "Patching Sury PHP GPG key in Docker build..."
DOCKERFILE=".ddev/web-build/disabled.Dockerfile.playwright"

if [ -f "$DOCKERFILE" ]; then
  if grep -q "debsuryorg-archive-keyring" "$DOCKERFILE"; then
    ok "GPG key fix already applied."
  else
    # Insert the key refresh before 'RUN apt-get update'
    sed -i.bak '/^RUN apt-get update$/i\
# Refresh expired Sury PHP GPG key.\
RUN curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \\\
  \&\& dpkg -i /tmp/debsuryorg-archive-keyring.deb \\\
  \&\& rm /tmp/debsuryorg-archive-keyring.deb\
' "$DOCKERFILE"
    rm -f "${DOCKERFILE}.bak"
    ok "GPG key fix applied."
  fi
else
  warn "Dockerfile not found at $DOCKERFILE — skipping patch."
fi

# ── Step 3: Install Playwright browsers ──
step "Installing Playwright browsers (this rebuilds the container, 1-3 min)..."
ddev install-playwright
ok "Browsers installed."

# ── Step 4: Run Drush setup ──
step "Running drush smoke:setup..."
echo ""
ddev drush smoke:setup
