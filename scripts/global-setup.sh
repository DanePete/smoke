#!/bin/bash
#
# Global Playwright Setup for Agencies
#
# Installs Playwright and Chromium once on your Mac, shared across all projects.
# This is the recommended approach for agencies using VS Code / Cursor.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/.../global-setup.sh | bash
#   # or
#   bash web/modules/contrib/smoke/scripts/global-setup.sh
#
# After running this script:
#   1. Tests run from your IDE use the global Playwright
#   2. No per-project Chromium downloads needed
#   3. ddev drush smoke --run still works (uses container fallback)
#

set -e

echo ""
echo "  ┌─────────────────────────────────────────────────────┐"
echo "  │  Smoke Tests - Global Playwright Setup              │"
echo "  └─────────────────────────────────────────────────────┘"
echo ""

# Check for Node.js
if ! command -v node &> /dev/null; then
  echo "  ❌ Node.js not found."
  echo ""
  echo "  Install via Homebrew:"
  echo "    brew install node"
  echo ""
  exit 1
fi

NODE_VERSION=$(node -v)
echo "  ✓ Node.js ${NODE_VERSION}"

# Check for npm
if ! command -v npm &> /dev/null; then
  echo "  ❌ npm not found."
  exit 1
fi

echo "  ✓ npm $(npm -v)"
echo ""

# Global Playwright directory
PLAYWRIGHT_GLOBAL_DIR="${HOME}/.playwright-smoke"

echo "  Installing Playwright globally to: ${PLAYWRIGHT_GLOBAL_DIR}"
echo ""

# Create directory
mkdir -p "${PLAYWRIGHT_GLOBAL_DIR}"

# Create package.json if it doesn't exist
if [ ! -f "${PLAYWRIGHT_GLOBAL_DIR}/package.json" ]; then
  cat > "${PLAYWRIGHT_GLOBAL_DIR}/package.json" << 'EOF'
{
  "name": "playwright-smoke-global",
  "version": "1.0.0",
  "description": "Global Playwright installation for Smoke Tests",
  "private": true,
  "dependencies": {
    "@playwright/test": "^1.40.0"
  }
}
EOF
fi

# Install Playwright
cd "${PLAYWRIGHT_GLOBAL_DIR}"
npm install

echo ""
echo "  Installing Chromium browser..."
echo ""

# Install only Chromium (not all browsers)
npx playwright install chromium

echo ""
echo "  ✓ Playwright installed globally"
echo ""

# Set up shell configuration
SHELL_CONFIG=""
if [ -f "${HOME}/.zshrc" ]; then
  SHELL_CONFIG="${HOME}/.zshrc"
elif [ -f "${HOME}/.bashrc" ]; then
  SHELL_CONFIG="${HOME}/.bashrc"
elif [ -f "${HOME}/.bash_profile" ]; then
  SHELL_CONFIG="${HOME}/.bash_profile"
fi

# Check if PATH already includes our directory
if [ -n "${SHELL_CONFIG}" ]; then
  if ! grep -q "PLAYWRIGHT_SMOKE" "${SHELL_CONFIG}" 2>/dev/null; then
    echo "" >> "${SHELL_CONFIG}"
    echo "# Smoke Tests - Global Playwright" >> "${SHELL_CONFIG}"
    echo "export PLAYWRIGHT_SMOKE_GLOBAL=\"${PLAYWRIGHT_GLOBAL_DIR}\"" >> "${SHELL_CONFIG}"
    echo "export PATH=\"\${PLAYWRIGHT_SMOKE_GLOBAL}/node_modules/.bin:\${PATH}\"" >> "${SHELL_CONFIG}"
    echo ""
    echo "  ✓ Added to ${SHELL_CONFIG}"
    echo ""
    echo "  Run this to use immediately (or open a new terminal):"
    echo "    source ${SHELL_CONFIG}"
  else
    echo "  ✓ Shell config already set up"
  fi
fi

echo ""
echo "  ┌─────────────────────────────────────────────────────┐"
echo "  │  Setup Complete!                                    │"
echo "  └─────────────────────────────────────────────────────┘"
echo ""
echo "  Next steps:"
echo ""
echo "  1. Open a new terminal (or run: source ${SHELL_CONFIG})"
echo ""
echo "  2. In any Drupal project with Smoke installed:"
echo "     ddev drush smoke:init"
echo ""
echo "  3. Open in VS Code / Cursor - tests appear in Testing sidebar"
echo ""
echo "  4. Run from command line (outside DDEV):"
echo "     cd /path/to/project"
echo "     npx playwright test"
echo ""
echo "  The global installation is at: ${PLAYWRIGHT_GLOBAL_DIR}"
echo "  Chromium browsers are at: ~/Library/Caches/ms-playwright"
echo ""
