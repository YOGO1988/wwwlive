#!/bin/bash
# ChronoTrack Live Results - Deployment Verification Script
# Use this to check if your server has the latest code

echo "=========================================="
echo "CHRONOTRACK DEPLOYMENT CHECK"
echo "=========================================="
echo ""

# Check current commit
echo "1. Checking current commit..."
CURRENT_COMMIT=$(git log --oneline -1)
echo "   Current: $CURRENT_COMMIT"
echo "   Expected: 75b1e5a CRITICAL FIXES: Pace display, modal positioning..."
echo ""

# Check if country-flags.js exists
echo "2. Checking for country-flags.js..."
if [ -f "assets/js/country-flags.js" ]; then
    echo "   ✅ country-flags.js EXISTS"
else
    echo "   ❌ country-flags.js MISSING - need to git pull!"
fi
echo ""

# Check for modal padding-top fix
echo "3. Checking for modal padding-top fix..."
if grep -q "padding-top: 80px" assets/css/chronotrack-live.css; then
    echo "   ✅ Modal padding-top fix FOUND"
else
    echo "   ❌ Modal padding-top fix MISSING - need to git pull!"
fi
echo ""

# Check for pace display fix
echo "4. Checking for pace display fix..."
if grep -q "Tempo:" assets/js/chronotrack-live.js; then
    echo "   ✅ Pace display fix FOUND"
else
    echo "   ❌ Pace display fix MISSING - need to git pull!"
fi
echo ""

# Check for flag display code
echo "5. Checking for flag display code..."
if grep -q "CountryFlags.getFlag" assets/js/chronotrack-live.js; then
    echo "   ✅ Flag display code FOUND"
else
    echo "   ❌ Flag display code MISSING - need to git pull!"
fi
echo ""

# Summary
echo "=========================================="
echo "SUMMARY:"
echo "=========================================="
echo ""
echo "If ALL checks show ✅ then code is up to date."
echo "If ANY check shows ❌ then run:"
echo ""
echo "   git pull origin claude/fix-blank-plugin-page-019ScpX1aKLfxCSVZAX9kdnk"
echo ""
echo "After git pull, clear WordPress cache:"
echo "- WP Super Cache: Delete Cache"
echo "- W3 Total Cache: Purge All Caches"
echo "- WP Rocket: Clear Cache"
echo ""
echo "=========================================="
