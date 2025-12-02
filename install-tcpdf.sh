#!/bin/bash
# ChronoTrack Live Results - TCPDF Installation Script
# Automatically downloads and installs TCPDF library

echo "=========================================="
echo "TCPDF INSTALLATION"
echo "=========================================="
echo ""

# Set variables
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$PLUGIN_DIR/lib"
TCPDF_DIR="$LIB_DIR/tcpdf"
TCPDF_VERSION="6.7.5"
TCPDF_URL="https://github.com/tecnickcom/TCPDF/archive/refs/tags/${TCPDF_VERSION}.tar.gz"

echo "Plugin directory: $PLUGIN_DIR"
echo "TCPDF version: $TCPDF_VERSION"
echo ""

# Check if TCPDF already exists
if [ -f "$TCPDF_DIR/tcpdf.php" ]; then
    echo "✅ TCPDF is already installed at: $TCPDF_DIR"
    echo ""
    read -p "Do you want to reinstall? (y/n) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Installation cancelled."
        exit 0
    fi
    echo "Removing existing TCPDF..."
    rm -rf "$TCPDF_DIR"
fi

# Create lib directory if it doesn't exist
echo "1. Creating lib directory..."
mkdir -p "$LIB_DIR"

# Download TCPDF
echo "2. Downloading TCPDF ${TCPDF_VERSION}..."
cd "$LIB_DIR"

if command -v wget &> /dev/null; then
    wget -q --show-progress "$TCPDF_URL" -O tcpdf.tar.gz
elif command -v curl &> /dev/null; then
    curl -L --progress-bar "$TCPDF_URL" -o tcpdf.tar.gz
else
    echo "❌ Error: Neither wget nor curl is available."
    echo "Please install wget or curl and try again."
    exit 1
fi

if [ $? -ne 0 ]; then
    echo "❌ Failed to download TCPDF"
    exit 1
fi

# Extract archive
echo "3. Extracting TCPDF..."
tar -xzf tcpdf.tar.gz

if [ $? -ne 0 ]; then
    echo "❌ Failed to extract TCPDF"
    rm -f tcpdf.tar.gz
    exit 1
fi

# Rename directory
mv "TCPDF-${TCPDF_VERSION}" tcpdf

# Clean up
echo "4. Cleaning up..."
rm -f tcpdf.tar.gz

# Verify installation
if [ -f "$TCPDF_DIR/tcpdf.php" ]; then
    echo ""
    echo "=========================================="
    echo "✅ TCPDF INSTALLED SUCCESSFULLY!"
    echo "=========================================="
    echo ""
    echo "Location: $TCPDF_DIR"
    echo "Main file: $TCPDF_DIR/tcpdf.php"
    echo ""
    echo "You can now use the PDF generator in ChronoTrack Live Results."
    echo ""
else
    echo ""
    echo "=========================================="
    echo "❌ INSTALLATION FAILED"
    echo "=========================================="
    echo ""
    echo "TCPDF was not installed correctly."
    echo "Please check the error messages above."
    echo ""
    exit 1
fi
