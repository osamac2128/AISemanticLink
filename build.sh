#!/bin/bash
#
# AI Entity Index - Build Script
# Creates a production-ready WordPress plugin zip file
#
# Usage: ./build.sh
# Output: ai-entity-index-{version}.zip
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Extract version from main plugin file
VERSION=$(grep -o "Version: [0-9.]*" ai-entity-index.php | cut -d' ' -f2)
PLUGIN_SLUG="ai-entity-index"
BUILD_DIR="./build"
DIST_DIR="./dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  AI Entity Index Build Script${NC}"
echo -e "${GREEN}  Version: ${VERSION}${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check for required tools
echo -e "${YELLOW}Checking dependencies...${NC}"
command -v composer >/dev/null 2>&1 || { echo -e "${RED}Error: composer is required but not installed.${NC}" >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo -e "${RED}Error: npm is required but not installed.${NC}" >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo -e "${RED}Error: zip is required but not installed.${NC}" >&2; exit 1; }
echo -e "${GREEN}✓ All dependencies found${NC}"
echo ""

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
rm -rf "$DIST_DIR"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
mkdir -p "$DIST_DIR"
echo -e "${GREEN}✓ Cleaned${NC}"
echo ""

# Install Composer dependencies (production only)
echo -e "${YELLOW}Installing Composer dependencies...${NC}"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
echo -e "${GREEN}✓ Composer dependencies installed${NC}"
echo ""

# Install npm dependencies and build
echo -e "${YELLOW}Installing npm dependencies...${NC}"
npm ci --silent
echo -e "${GREEN}✓ npm dependencies installed${NC}"
echo ""

echo -e "${YELLOW}Building React admin interface...${NC}"
npm run build
echo -e "${GREEN}✓ React build complete${NC}"
echo ""

# Copy files to build directory
echo -e "${YELLOW}Copying files to build directory...${NC}"

# Copy PHP files
cp ai-entity-index.php "$BUILD_DIR/$PLUGIN_SLUG/"
cp -r includes "$BUILD_DIR/$PLUGIN_SLUG/"
cp -r vendor "$BUILD_DIR/$PLUGIN_SLUG/"

# Copy admin assets (built files only)
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG/admin/js"
cp -r admin/js/build "$BUILD_DIR/$PLUGIN_SLUG/admin/js/"

# Copy config files needed at runtime
cp LICENSE "$BUILD_DIR/$PLUGIN_SLUG/"

# Copy readme if exists
if [ -f "readme.txt" ]; then
    cp readme.txt "$BUILD_DIR/$PLUGIN_SLUG/"
fi

echo -e "${GREEN}✓ Files copied${NC}"
echo ""

# Remove unnecessary files from vendor
echo -e "${YELLOW}Optimizing vendor directory...${NC}"
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type f -name "*.md" -delete 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type f -name "*.txt" -not -name "LICENSE*" -delete 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type f -name "phpunit.xml*" -delete 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type f -name ".gitignore" -delete 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type f -name ".gitattributes" -delete 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
find "$BUILD_DIR/$PLUGIN_SLUG/vendor" -type d -name ".git" -exec rm -rf {} + 2>/dev/null || true
echo -e "${GREEN}✓ Vendor optimized${NC}"
echo ""

# Create zip file
echo -e "${YELLOW}Creating zip archive...${NC}"
cd "$BUILD_DIR"
zip -r "../$DIST_DIR/$ZIP_NAME" "$PLUGIN_SLUG" -x "*.DS_Store" -x "*__MACOSX*"
cd "$SCRIPT_DIR"
echo -e "${GREEN}✓ Zip created: $DIST_DIR/$ZIP_NAME${NC}"
echo ""

# Calculate zip size
ZIP_SIZE=$(du -h "$DIST_DIR/$ZIP_NAME" | cut -f1)

# Clean up build directory
echo -e "${YELLOW}Cleaning up...${NC}"
rm -rf "$BUILD_DIR"
echo -e "${GREEN}✓ Cleanup complete${NC}"
echo ""

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Build Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "  Output: ${YELLOW}$DIST_DIR/$ZIP_NAME${NC}"
echo -e "  Size:   ${YELLOW}$ZIP_SIZE${NC}"
echo ""
echo -e "  To install:"
echo -e "  1. Go to WordPress Admin → Plugins → Add New"
echo -e "  2. Click 'Upload Plugin'"
echo -e "  3. Select ${YELLOW}$ZIP_NAME${NC}"
echo -e "  4. Click 'Install Now' and then 'Activate'"
echo ""
