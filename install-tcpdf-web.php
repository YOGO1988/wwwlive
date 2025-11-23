<?php
/**
 * TCPDF Web Installer
 * Open this file in browser to install TCPDF library
 * URL: https://yogoevents.pl/wp-content/plugins/chronotrack-live-results/install-tcpdf-web.php
 */

// Security check - only allow access from logged-in WordPress admins
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('ERROR: Only administrators can install TCPDF. Please log in to WordPress as admin first.');
}

// Set longer execution time for download
set_time_limit(300);

echo '<html><head><title>TCPDF Installer</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
.container { background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
h1 { color: #FF6600; border-bottom: 2px solid #FF6600; padding-bottom: 10px; }
.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 4px; margin: 10px 0; }
.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin: 10px 0; }
.info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 10px 0; }
.step { background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #FF6600; }
code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
.btn { background: #FF6600; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; margin: 10px 0; }
.btn:hover { background: #E55A00; }
</style></head><body>';

echo '<div class="container">';
echo '<h1>🔧 TCPDF Library Installer</h1>';
echo '<p>This installer will download and install TCPDF library for PDF generation.</p>';

$plugin_dir = dirname(__FILE__);
$lib_dir = $plugin_dir . '/lib';
$tcpdf_dir = $lib_dir . '/tcpdf';
$tcpdf_version = '6.7.5';
$tcpdf_url = "https://github.com/tecnickcom/TCPDF/archive/refs/tags/{$tcpdf_version}.tar.gz";

// Check if already installed
if (file_exists($tcpdf_dir . '/tcpdf.php')) {
    echo '<div class="success">';
    echo '<strong>✅ TCPDF is already installed!</strong><br>';
    echo 'Location: <code>' . $tcpdf_dir . '</code><br>';
    echo 'Main file: <code>' . $tcpdf_dir . '/tcpdf.php</code>';
    echo '</div>';

    echo '<p><a href="' . admin_url() . '" class="btn">← Back to WordPress Admin</a></p>';

    // Check if user wants to reinstall
    if (!isset($_GET['reinstall'])) {
        echo '<p><a href="?reinstall=1" class="btn" style="background: #dc3545;">🔄 Reinstall TCPDF</a></p>';
        echo '</div></body></html>';
        exit;
    } else {
        echo '<div class="info">Removing existing installation...</div>';
        deleteDirectory($tcpdf_dir);
    }
}

echo '<div class="step"><strong>Step 1:</strong> Creating directories...</div>';

// Create lib directory
if (!file_exists($lib_dir)) {
    if (!mkdir($lib_dir, 0755, true)) {
        echo '<div class="error">❌ ERROR: Could not create lib directory at: ' . $lib_dir . '</div>';
        echo '<p>Please check directory permissions.</p>';
        echo '</div></body></html>';
        exit;
    }
    echo '<div class="success">✅ Created lib directory</div>';
} else {
    echo '<div class="info">ℹ️ lib directory already exists</div>';
}

echo '<div class="step"><strong>Step 2:</strong> Downloading TCPDF ' . $tcpdf_version . '...</div>';
echo '<p>Downloading from: <code>' . $tcpdf_url . '</code></p>';

// Download TCPDF
$temp_file = $lib_dir . '/tcpdf.tar.gz';

$ch = curl_init($tcpdf_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$data = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($data === false || $http_code != 200) {
    echo '<div class="error">❌ ERROR: Failed to download TCPDF<br>';
    echo 'HTTP Code: ' . $http_code . '<br>';
    echo 'Error: ' . $error . '</div>';
    echo '</div></body></html>';
    exit;
}

file_put_contents($temp_file, $data);
$size_mb = round(filesize($temp_file) / 1024 / 1024, 2);
echo '<div class="success">✅ Downloaded TCPDF (' . $size_mb . ' MB)</div>';

echo '<div class="step"><strong>Step 3:</strong> Extracting archive...</div>';

// Extract using PharData (built-in PHP)
try {
    $phar = new PharData($temp_file);
    $phar->extractTo($lib_dir, null, true);
    echo '<div class="success">✅ Extracted TCPDF archive</div>';
} catch (Exception $e) {
    echo '<div class="error">❌ ERROR: Failed to extract TCPDF<br>';
    echo 'Error: ' . $e->getMessage() . '</div>';
    @unlink($temp_file);
    echo '</div></body></html>';
    exit;
}

echo '<div class="step"><strong>Step 4:</strong> Renaming directory...</div>';

// Rename extracted directory
$extracted_dir = $lib_dir . '/TCPDF-' . $tcpdf_version;
if (file_exists($extracted_dir)) {
    if (rename($extracted_dir, $tcpdf_dir)) {
        echo '<div class="success">✅ Renamed directory to tcpdf</div>';
    } else {
        echo '<div class="error">❌ ERROR: Failed to rename directory</div>';
        @unlink($temp_file);
        echo '</div></body></html>';
        exit;
    }
} else {
    echo '<div class="error">❌ ERROR: Extracted directory not found at: ' . $extracted_dir . '</div>';
    @unlink($temp_file);
    echo '</div></body></html>';
    exit;
}

echo '<div class="step"><strong>Step 5:</strong> Cleaning up...</div>';

// Clean up temp file
@unlink($temp_file);
echo '<div class="success">✅ Removed temporary files</div>';

echo '<div class="step"><strong>Step 6:</strong> Verifying installation...</div>';

// Verify installation
if (file_exists($tcpdf_dir . '/tcpdf.php')) {
    echo '<div class="success">';
    echo '<h2>🎉 SUCCESS!</h2>';
    echo '<p><strong>TCPDF has been installed successfully!</strong></p>';
    echo '<p>Location: <code>' . $tcpdf_dir . '</code></p>';
    echo '<p>Main file: <code>' . $tcpdf_dir . '/tcpdf.php</code></p>';
    echo '<p><strong>You can now use the PDF generator in ChronoTrack Live Results!</strong></p>';
    echo '</div>';

    echo '<div class="info">';
    echo '<h3>🔒 Security Recommendation:</h3>';
    echo '<p>For security, you should delete this installer file after installation:</p>';
    echo '<code>' . basename(__FILE__) . '</code>';
    echo '</div>';

    echo '<p><a href="' . admin_url() . '" class="btn">← Back to WordPress Admin</a></p>';
} else {
    echo '<div class="error">';
    echo '<h2>❌ Installation Failed</h2>';
    echo '<p>TCPDF main file was not found after installation.</p>';
    echo '<p>Please check the error messages above.</p>';
    echo '</div>';
}

echo '</div></body></html>';

// Helper function to delete directory recursively
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}
?>
