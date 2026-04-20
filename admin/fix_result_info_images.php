<?php
/**
 * Script untuk memperbaiki path gambar di tabel settings
 * Mengubah path yang salah (dengan /web_kelulusan/) menjadi path yang benar (dengan /daz/)
 * 
 * Jalankan script ini sekali untuk memperbaiki semua data yang ada
 */

require_once __DIR__ . '/includes/admin_bootstrap.php';

// Only allow access from localhost or authenticated admin
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', 'localhost', '::1'], true) && !is_admin_logged_in()) {
    http_response_code(403);
    echo "Akses Ditolak. Silakan login terlebih dahulu.";
    exit;
}

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Perbaikan Path Gambar Informasi Tambahan Hasil</h2>";

try {
    // Get the application base path (e.g., /daz/)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $pathParts = explode('/', trim($scriptName, '/'));
    array_pop($pathParts); // Remove script
    array_pop($pathParts); // Remove admin folder
    $appBaseUrl = '/' . implode('/', $pathParts);
    if ($appBaseUrl === '/') {
        $appBaseUrl = '';
    }

    // Read current settings
    $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'result_info_items' LIMIT 1");
    $row = $stmt->fetch();
    
    if (!$row) {
        echo "<p style='color: orange;'>Tidak ada data result_info_items yang ditemukan.</p>";
        exit;
    }

    $originalValue = $row['value'];
    $items = json_decode($originalValue, true);
    
    if (!is_array($items)) {
        echo "<p style='color: orange;'>Format data tidak valid atau kosong.</p>";
        exit;
    }

    $fixCount = 0;
    $itemsFixed = [];

    // Process each item and fix paths
    foreach ($items as $item) {
        $text = $item['text'] ?? '';
        
        // Replace wrong paths in img tags
        // Fix 1: Replace /web_kelulusan/daz/ with /daz/
        $text = str_replace('/web_kelulusan/daz/', '/daz/', $text);
        
        // Fix 2: Replace /web_kelulusan/assets/ with /daz/assets/
        $text = str_replace('/web_kelulusan/assets/', '/daz/assets/', $text);
        
        // Count fixes
        if ($text !== ($item['text'] ?? '')) {
            $fixCount++;
        }
        
        $itemsFixed[] = [
            'text' => $text,
            'opacity' => $item['opacity'] ?? '1',
        ];
    }

    if ($fixCount === 0) {
        echo "<p style='color: green;'>✓ Tidak ada path yang perlu diperbaiki. Semua path sudah benar.</p>";
        exit;
    }

    // Save the fixed data
    $newValue = json_encode($itemsFixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    $stmt = $pdo->prepare(
        "UPDATE settings SET value = :value WHERE name = 'result_info_items'"
    );
    $stmt->execute(['value' => $newValue]);

    echo "<p style='color: green;'>✓ Perbaikan berhasil!</p>";
    echo "<p>Jumlah item yang diperbaiki: <strong>$fixCount</strong></p>";
    echo "<p>Data telah diperbarui di database. Gambar sekarang akan ditampilkan dengan path yang benar.</p>";
    echo "<p><a href='result_info_settings.php'>← Kembali ke Pengaturan Informasi Tambahan Hasil</a></p>";

} catch (Exception $e) {
    http_response_code(500);
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
