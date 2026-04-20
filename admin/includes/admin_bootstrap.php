<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_admin();
csrf_require_post();

if (!function_exists('columnExists')) {
    function columnExists($pdo, $table, $column) {
        try {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
            if ($table === '') {
                return false;
            }
            $sql = "SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote((string)$column);
            $stmt = $pdo->query($sql);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('tableExists')) {
    function tableExists($pdo, $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote((string)$table));
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('ensureSettingsTableSafe')) {
    function ensureSettingsTableSafe(PDO $pdo) {
        if (function_exists('ensure_settings_table')) {
            return ensure_settings_table($pdo);
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `settings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(50) NOT NULL UNIQUE,
                    `value` TEXT DEFAULT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            try {
                $pdo->exec("ALTER TABLE `settings` MODIFY COLUMN `value` TEXT DEFAULT NULL");
            } catch (Throwable $e) {
                // Keep existing schema if ALTER is unsupported.
            }

            $defaults = [
                'announcement_time' => '',
                'announcement_timezone' => 'Asia/Jakarta',
                'logo' => 'logo.png',
                'background' => '',
                'skl_link' => '',
                'skl_label' => 'Download SKL.Pdf',
                'result_info_note' => '',
                'result_info_note_color' => '#f5f8ff',
                'result_info_note_opacity' => '1',
                'result_info_note_icon' => 'fas fa-circle-info',
                'result_info_items' => '[]'
            ];

            $stmt = $pdo->prepare(
                "INSERT INTO settings (name, value)
                 VALUES (:name, :value)
                 ON DUPLICATE KEY UPDATE value = COALESCE(value, VALUES(value))"
            );

            foreach ($defaults as $name => $value) {
                $stmt->execute([
                    'name' => $name,
                    'value' => $value
                ]);
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

function admin_sanitize_result_info_inline_style($style) {
    $style = trim((string)$style);
    if ($style === '') {
        return '';
    }

    $allowedProperties = [
        'color',
        'background-color',
        'text-align',
        'font-weight',
        'font-style',
        'text-decoration',
        'font-size',
        'font-family',
        'line-height',
        'letter-spacing',
        'word-spacing',
        'white-space',
        'float',
        'clear',
        'display',
        'margin',
        'margin-left',
        'margin-right',
        'margin-top',
        'margin-bottom',
        'padding',
        'padding-left',
        'padding-right',
        'padding-top',
        'padding-bottom',
        'width',
        'height',
        'max-width',
        'min-width',
        'max-height',
        'min-height',
        'border',
        'border-radius',
        'vertical-align'
    ];

    $safeDeclarations = [];
    foreach (explode(';', $style) as $declaration) {
        $parts = explode(':', $declaration, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        if ($property === '' || $value === '') {
            continue;
        }
        if (!in_array($property, $allowedProperties, true)) {
            continue;
        }
        if (preg_match('/(?:expression|javascript:|vbscript:|url\s*\(|behavior\s*:|@import)/i', $value)) {
            continue;
        }

        $safeDeclarations[] = $property . ': ' . $value;
    }

    return implode('; ', $safeDeclarations);
}

function admin_sanitize_result_info_note_html($html) {
    $html = (string)$html;
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
    $html = preg_replace_callback('/\s+style\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', function ($matches) {
        $rawStyle = '';
        if (isset($matches[2]) && $matches[2] !== '') {
            $rawStyle = $matches[2];
        } elseif (isset($matches[3]) && $matches[3] !== '') {
            $rawStyle = $matches[3];
        } elseif (isset($matches[4])) {
            $rawStyle = $matches[4];
        }

        $safeStyle = admin_sanitize_result_info_inline_style($rawStyle);
        return $safeStyle !== '' ? ' style="' . htmlspecialchars($safeStyle, ENT_QUOTES, 'UTF-8') . '"' : '';
    }, $html);
    $html = preg_replace('/\s+href\s*=\s*("javascript:.*?"|\'javascript:.*?\'|javascript:[^\s>]+)/i', '', $html);
    $html = preg_replace('/\s+src\s*=\s*("javascript:.*?"|\'javascript:.*?\'|javascript:[^\s>]+)/i', '', $html);
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><span><img><div><a><h1><h2><h3><h4><h5><h6><blockquote><pre><table><thead><tbody><tr><th><td><hr><sup><sub>');
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
    return trim($html);
}

function admin_allowed_result_info_icons() {
    return [
        'fas fa-circle-info',
        'fas fa-bullhorn',
        'fas fa-triangle-exclamation',
        'fas fa-bell',
        'fas fa-circle-check',
        'fas fa-book-open',
        'fas fa-clipboard-list',
        'fas fa-graduation-cap'
    ];
}

function admin_normalize_result_info_icon($icon) {
    $icon = trim((string)$icon);
    return in_array($icon, admin_allowed_result_info_icons(), true) ? $icon : 'fas fa-circle-info';
}

function admin_normalize_result_info_color($color) {
    $color = trim((string)$color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#f5f8ff';
}

function admin_normalize_result_info_opacity($opacity) {
    if (is_string($opacity)) {
        $opacity = str_replace(',', '.', trim($opacity));
    }
    if (!is_numeric($opacity)) {
        return '1';
    }
    $value = (float)$opacity;
    if ($value < 0) {
        $value = 0;
    } elseif ($value > 1) {
        $value = 1;
    }
    $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function admin_take_flash_message($key = 'admin_flash_message') {
    $message = $_SESSION[$key] ?? '';
    unset($_SESSION[$key]);
    return $message;
}

function admin_redirect_with_message($location, $message, $key = 'admin_flash_message') {
    $_SESSION[$key] = (string)$message;
    header('Location: ' . $location);
    exit;
}

function admin_get_base_context(PDO $pdo) {
    $regCol = columnExists($pdo, 'students', 'nisn') ? 'nisn' : (columnExists($pdo, 'students', 'registration_number') ? 'registration_number' : null);
    $dobCol = columnExists($pdo, 'students', 'birth_date') ? 'birth_date' : (columnExists($pdo, 'students', 'date_of_birth') ? 'date_of_birth' : null);
    $photoCol = admin_ensure_student_photo_column($pdo) ? 'photo' : null;
    $activeCol = admin_ensure_student_active_column($pdo) ? 'is_active' : null;

    return [
        'regCol' => $regCol,
        'dobCol' => $dobCol,
        'photoCol' => $photoCol,
        'activeCol' => $activeCol,
        'hasStatus' => columnExists($pdo, 'students', 'status'),
        'hasPredikat' => columnExists($pdo, 'students', 'predikat_id'),
        'hasSettings' => ensureSettingsTableSafe($pdo) || tableExists($pdo, 'settings'),
        'currentAdminId' => (int)($_SESSION['admin_id'] ?? 0),
    ];
}

function admin_ensure_student_active_column(PDO $pdo) {
    if (columnExists($pdo, 'students', 'is_active')) {
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER predikat_id");
        return true;
    } catch (Throwable $e) {
        return columnExists($pdo, 'students', 'is_active');
    }
}

function admin_ensure_student_photo_column(PDO $pdo) {
    if (columnExists($pdo, 'students', 'photo')) {
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER predikat_id");
        return true;
    } catch (Throwable $e) {
        return columnExists($pdo, 'students', 'photo');
    }
}

function admin_load_settings(PDO $pdo) {
    try {
        $stmt = $pdo->query("SELECT name, value FROM settings WHERE name IN ('announcement_time', 'announcement_timezone', 'logo', 'skl_link', 'skl_label', 'result_info_note', 'result_info_note_color', 'result_info_note_opacity', 'result_info_note_icon', 'result_info_items')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $settings = [];
    }

    $logo = !empty($settings['logo']) ? $settings['logo'] : 'logo.png';
    $logoPath = dirname(__DIR__, 2) . '/assets/' . basename($logo);
    if (!file_exists($logoPath)) {
        $logo = 'logo.png';
    }

    $announcementTime = $settings['announcement_time'] ?? '';
    if ($announcementTime) {
        try {
            $dt = new DateTime($announcementTime);
            $announcementTime = $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            $announcementTime = '';
        }
    }

    $allowedTimezones = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];
    $announcementTimezone = (string)($settings['announcement_timezone'] ?? 'Asia/Jakarta');
    if (!in_array($announcementTimezone, $allowedTimezones, true)) {
        $announcementTimezone = 'Asia/Jakarta';
    }

    return [
        'announcement_time' => $announcementTime,
        'announcement_timezone' => $announcementTimezone,
        'logo' => $logo,
        'skl_link' => $settings['skl_link'] ?? '',
        'skl_label' => !empty($settings['skl_label']) ? $settings['skl_label'] : 'Download SKL.Pdf',
        'result_info_note' => $settings['result_info_note'] ?? '',
        'result_info_note_color' => !empty($settings['result_info_note_color']) ? $settings['result_info_note_color'] : '#f5f8ff',
        'result_info_note_opacity' => admin_normalize_result_info_opacity($settings['result_info_note_opacity'] ?? '1'),
        'result_info_note_icon' => !empty($settings['result_info_note_icon']) ? $settings['result_info_note_icon'] : 'fas fa-circle-info',
        'result_info_items' => $settings['result_info_items'] ?? '[]',
    ];
}

function admin_load_predicates(PDO $pdo) {
    try {
        return $pdo->query('SELECT id, name FROM predikat ORDER BY name')->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function admin_load_students(PDO $pdo, $regCol, $dobCol, $photoCol = null) {
    try {
        $selectFields = ['s.id', 's.name', 's.status', 's.predikat_id', 'p.name as predikat_name'];
        if (!empty($regCol)) {
            $selectFields[] = 's.' . $regCol;
        }
        if (!empty($dobCol)) {
            $selectFields[] = 's.' . $dobCol;
        }
        if (!empty($photoCol)) {
            $selectFields[] = 's.' . $photoCol;
        }
        if (columnExists($pdo, 'students', 'is_active')) {
            $selectFields[] = 's.is_active';
        }
        $selectSql = implode(', ', $selectFields);
        return $pdo->query("SELECT $selectSql FROM students s LEFT JOIN predikat p ON s.predikat_id = p.id ORDER BY s.id")->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function admin_count_students(PDO $pdo) {
    try {
        $result = $pdo->query("SELECT COUNT(*) as total FROM students")->fetch();
        return (int)$result['total'];
    } catch (Exception $e) {
        return 0;
    }
}

function admin_load_students_paginated(PDO $pdo, $regCol, $dobCol, $page = 1, $perPage = 10, $photoCol = null) {
    try {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $selectFields = ['s.id', 's.name', 's.status', 's.predikat_id', 'p.name as predikat_name'];
        if (!empty($regCol)) {
            $selectFields[] = 's.' . $regCol;
        }
        if (!empty($dobCol)) {
            $selectFields[] = 's.' . $dobCol;
        }
        if (!empty($photoCol)) {
            $selectFields[] = 's.' . $photoCol;
        }
        if (columnExists($pdo, 'students', 'is_active')) {
            $selectFields[] = 's.is_active';
        }
        $selectSql = implode(', ', $selectFields);
        $sql = "SELECT $selectSql FROM students s LEFT JOIN predikat p ON s.predikat_id = p.id ORDER BY s.id LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function admin_update_announcement_time(PDO $pdo, $hasSettings, $rawTime, $rawTimezone = 'Asia/Jakarta') {
    $time = str_replace('T', ' ', (string)$rawTime);
    $allowedTimezones = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];
    $timezone = trim((string)$rawTimezone);
    if (!in_array($timezone, $allowedTimezones, true)) {
        $timezone = 'Asia/Jakarta';
    }
    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $timeStmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('announcement_time', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $timezoneStmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('announcement_timezone', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $timeStmt->execute(['v' => $time]);
    $timezoneStmt->execute(['v' => $timezone]);
    return 'Waktu pengumuman diperbarui.';
}

function admin_update_result_info_note(PDO $pdo, $hasSettings, $rawNote) {
    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $note = admin_sanitize_result_info_note_html($rawNote);
    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('result_info_note', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['v' => $note]);
    return 'Informasi tambahan hasil pengumuman diperbarui.';
}

function admin_normalize_result_info_image_paths($html) {
    // Fix broken image paths that may have been stored with incorrect prefixes
    $html = str_replace('/web_kelulusan/daz/', '/daz/', $html);
    $html = str_replace('/web_kelulusan/assets/', '/daz/assets/', $html);
    return $html;
}

function admin_load_result_info_items(array $settings) {
    $items = json_decode((string)($settings['result_info_items'] ?? '[]'), true);
    $normalized = [];

    if (is_array($items)) {
        foreach ($items as $item) {
            $html = $item['text'] ?? '';
            // Normalize image paths before sanitizing
            $html = admin_normalize_result_info_image_paths($html);
            $html = admin_sanitize_result_info_note_html($html);
            if ($html === '') {
                continue;
            }
            $normalized[] = [
                'text' => $html,
                'opacity' => admin_normalize_result_info_opacity($item['opacity'] ?? '1'),
            ];
        }
    }

    if ($normalized === []) {
        $legacyHtml = $settings['result_info_note'] ?? '';
        // Normalize paths for legacy note as well
        $legacyHtml = admin_normalize_result_info_image_paths($legacyHtml);
        $legacyHtml = admin_sanitize_result_info_note_html($legacyHtml);
        if ($legacyHtml !== '') {
            $normalized[] = [
                'text' => $legacyHtml,
                'opacity' => admin_normalize_result_info_opacity($settings['result_info_note_opacity'] ?? '1'),
            ];
        }
    }

    return $normalized;
}

function admin_save_result_info_items(PDO $pdo, $hasSettings, array $items) {
    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $payload = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('result_info_items', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['v' => $payload]);

    // Clear legacy single-item fallback once the JSON-based items are managed,
    // so old information does not keep reappearing as "Informasi 1".
    $clearLegacyStmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES (:name, :value)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $clearLegacyStmt->execute([
        'name' => 'result_info_note',
        'value' => ''
    ]);

    return 'Informasi tambahan hasil pengumuman diperbarui.';
}

function admin_add_result_info_item(PDO $pdo, $hasSettings, array $settings, array $post) {
    $items = admin_load_result_info_items($settings);
    $html = admin_sanitize_result_info_note_html($post['result_info_note'] ?? '');
    if ($html === '') {
        return 'Teks informasi tidak boleh kosong.';
    }

    $items[] = [
        'text' => $html,
        'opacity' => admin_normalize_result_info_opacity($post['result_info_note_opacity'] ?? '1'),
    ];

    return admin_save_result_info_items($pdo, $hasSettings, $items);
}

function admin_delete_result_info_item(PDO $pdo, $hasSettings, array $settings, $index) {
    $items = admin_load_result_info_items($settings);
    $index = (int)$index;
    if (!isset($items[$index])) {
        return 'Item informasi tidak ditemukan.';
    }
    array_splice($items, $index, 1);
    return admin_save_result_info_items($pdo, $hasSettings, $items);
}

function admin_reorder_result_info_items(PDO $pdo, $hasSettings, array $settings, array $order) {
    $items = admin_load_result_info_items($settings);
    $itemCount = count($items);
    
    // Validasi bahwa semua index ada dan benar
    $orderArray = [];
    foreach ($order as $idx) {
        $idx = (int)$idx;
        if (isset($items[$idx])) {
            $orderArray[] = $idx;
        }
    }
    
    if (count($orderArray) !== $itemCount) {
        return 'Urutan item tidak valid.';
    }
    
    // Reorder items sesuai urutan yang diberikan
    $reorderedItems = [];
    foreach ($orderArray as $idx) {
        $reorderedItems[] = $items[$idx];
    }
    
    return admin_save_result_info_items($pdo, $hasSettings, $reorderedItems);
}

function admin_update_result_info_note_style(PDO $pdo, $hasSettings, $rawColor) {
    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $color = admin_normalize_result_info_color($rawColor);

    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('result_info_note_color', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['v' => $color]);
    return 'Warna teks informasi hasil pengumuman diperbarui.';
}

function admin_update_result_info_note_icon(PDO $pdo, $hasSettings, $rawIcon) {
    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $icon = admin_normalize_result_info_icon($rawIcon);

    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('result_info_note_icon', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['v' => $icon]);
    return 'Ikon informasi hasil pengumuman diperbarui.';
}

function admin_update_skl_settings(PDO $pdo, $hasSettings, $sklLink, $sklLabel) {
    $sklLink = trim((string)$sklLink);
    $sklLabel = trim((string)$sklLabel);
    if ($sklLabel === '') {
        $sklLabel = 'Download SKL.Pdf';
    }

    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $linkStmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('skl_link', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $labelStmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('skl_label', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $linkStmt->execute(['v' => $sklLink]);
    $labelStmt->execute(['v' => $sklLabel]);
    return 'Pengaturan Download SKL diperbarui.';
}

function admin_update_logo(PDO $pdo, $hasSettings, array $files, array $post) {
    if (isset($files['logofile']) && $files['logofile']['error'] === UPLOAD_ERR_OK) {
        $tmp = $files['logofile']['tmp_name'];
        $name = basename($files['logofile']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedExt = 'png';
        $maxSize = 2 * 1024 * 1024; // 2 MB

        if ($ext !== $allowedExt) {
            return 'Error: Hanya file PNG yang diizinkan.';
        }
        if ($files['logofile']['size'] > $maxSize) {
            return 'Error: Ukuran logo maksimal 2MB.';
        }

        $mime = mime_content_type($tmp);
        if ($mime !== 'image/png') {
            return 'Error: File harus berupa PNG yang valid.';
        }

        $name = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $name);
        $dest = dirname(__DIR__, 2) . '/assets/' . $name;
        if (!file_exists(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }

        if (!move_uploaded_file($tmp, $dest)) {
            return 'Error: Gagal mengupload file.';
        }

        if (!$hasSettings) {
            return 'File logo terupload, tapi tabel settings belum tersedia.';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO settings (name, value)
             VALUES ('logo', :v)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        $stmt->execute(['v' => $name]);
        return 'Logo diperbarui.';
    }

    $name = trim((string)($post['logoname'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $ext !== 'png') {
        return 'Error: Nama file harus PNG dan tidak boleh kosong.';
    }

    $sanitized = basename($name);
    if ($sanitized !== $name || preg_match('/[^A-Za-z0-9_\-.]/', $name)) {
        return 'Error: Nama file tidak valid.';
    }

    $assetPath = dirname(__DIR__, 2) . '/assets/' . $name;
    if (!file_exists($assetPath)) {
        return 'Error: File logo tidak ditemukan di folder assets.';
    }

    if (!$hasSettings) {
        return 'Tabel settings belum tersedia.';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value)
         VALUES ('logo', :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['v' => $name]);
    return 'Logo diperbarui.';
}

function admin_change_password(PDO $pdo, $currentAdminId, array $post) {
    $currentPassword = $post['current_password'] ?? '';
    $newPassword = $post['new_password'] ?? '';
    $confirmPassword = $post['confirm_password'] ?? '';

    if ($currentAdminId <= 0) {
        return 'Error: Sesi admin tidak valid.';
    }
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        return 'Error: Semua field kata sandi wajib diisi.';
    }
    if (strlen($newPassword) < 6) {
        return 'Error: Kata sandi baru minimal 6 karakter.';
    }
    if ($newPassword !== $confirmPassword) {
        return 'Error: Konfirmasi kata sandi baru tidak cocok.';
    }

    $stmt = $pdo->prepare('SELECT password FROM admins WHERE id = :id');
    $stmt->execute(['id' => $currentAdminId]);
    $storedPassword = $stmt->fetchColumn();

    if ($storedPassword === false) {
        return 'Error: Data admin tidak ditemukan.';
    }
    if (!password_verify($currentPassword, $storedPassword) && $currentPassword !== $storedPassword) {
        return 'Error: Kata sandi saat ini salah.';
    }

    $update = $pdo->prepare('UPDATE admins SET password = :password WHERE id = :id');
    $update->execute([
        'password' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $currentAdminId
    ]);

    return 'Kata sandi admin berhasil diperbarui.';
}

function admin_add_manual_student(PDO $pdo, $regCol, $dobCol, array $post) {
    if ($regCol === null || $dobCol === null) {
        return 'Error: Struktur tabel students belum sesuai (kolom identitas/tanggal lahir tidak ditemukan).';
    }

    $reg = trim((string)($post['nisn'] ?? ''));
    $name = trim((string)($post['name'] ?? ''));
    $dob = $post['birth_date'] ?? '';
    $status = $post['status'] ?? '';
    $predikatId = !empty($post['predikat_id']) ? $post['predikat_id'] : null;

    $errors = [];
    if ($name === '') {
        $errors[] = 'Nama tidak boleh kosong.';
    }
    if ($reg === '') {
        $errors[] = 'NISN tidak boleh kosong.';
    }
    if ($dob === '') {
        $errors[] = 'Tanggal lahir tidak boleh kosong.';
    }
    if (!in_array($status, ['Lulus', 'Tidak Lulus'], true)) {
        $errors[] = 'Status kelulusan tidak valid.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE ' . $regCol . ' = ?');
    $stmt->execute([$reg]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'NISN sudah ada.';
    }

    if ($errors) {
        return 'Error: ' . implode(' ', $errors);
    }

    $insertData = [
        'name' => $name,
        $regCol => $reg,
        $dobCol => $dob,
        'status' => $status,
        'predikat_id' => $predikatId
    ];
    $insertFields = ['name', $regCol, $dobCol, 'status', 'predikat_id'];
    $placeholders = ':' . implode(', :', $insertFields);

    $stmt = $pdo->prepare('INSERT INTO students (' . implode(', ', $insertFields) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($insertData);

    return 'Siswa berhasil ditambahkan.';
}

function admin_add_bulk_students(PDO $pdo, $regCol, $dobCol, $bulkData) {
    if ($regCol === null || $dobCol === null) {
        return 'Error: Struktur tabel students belum sesuai (kolom identitas/tanggal lahir tidak ditemukan).';
    }

    $bulkData = trim((string)$bulkData);
    if ($bulkData === '') {
        return 'Error: Data bulk kosong.';
    }

    $lines = array_filter(array_map('trim', explode("\n", $bulkData)));
    $errors = [];
    $inserted = 0;

    foreach ($lines as $index => $line) {
        $parts = array_map('trim', str_getcsv($line));
        if (count($parts) < 5) {
            $errors[] = 'Baris ' . ($index + 1) . ' salah format.';
            continue;
        }

        list($reg, $name, $birthDate, $status, $predikatId) = $parts;
        if ($reg === '' || $name === '' || $birthDate === '' || $status === '') {
            $errors[] = 'Baris ' . ($index + 1) . ': semua kolom wajib terisi.';
            continue;
        }
        if (!in_array($status, ['Lulus', 'Tidak Lulus'], true)) {
            $errors[] = 'Baris ' . ($index + 1) . ': status harus Lulus/Tidak Lulus.';
            continue;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE ' . $regCol . ' = ?');
        $stmt->execute([$reg]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Baris ' . ($index + 1) . ': NISN/No. Pendaftaran ' . htmlspecialchars($reg) . ' sudah ada.';
            continue;
        }

        $stmt = $pdo->prepare('INSERT INTO students (' . $regCol . ', name, ' . $dobCol . ', status, predikat_id) VALUES (:reg, :name, :birth_date, :status, :predikat_id)');
        $stmt->execute([
            'reg' => $reg,
            'name' => $name,
            'birth_date' => $birthDate,
            'status' => $status,
            'predikat_id' => ($predikatId === '' ? null : (int)$predikatId)
        ]);
        $inserted++;
    }

    $message = 'Bulk add selesai: ' . $inserted . ' siswa ditambahkan.';
    if ($errors) {
        $message .= ' Errors: ' . implode(' ', $errors);
    }
    return $message;
}

function admin_update_student(PDO $pdo, $regCol, $dobCol, array $post) {
    if ($regCol === null || $dobCol === null) {
        return 'Error: Struktur tabel students belum sesuai (kolom identitas/tanggal lahir tidak ditemukan).';
    }

    $id = $post['edit_id'] ?? '';
    $nisn = trim((string)($post['nisn'] ?? ''));
    $name = trim((string)($post['name'] ?? ''));
    $birthDate = $post['birth_date'] ?? '';
    $status = $post['status'] ?? '';
    $predikatId = !empty($post['predikat_id']) ? $post['predikat_id'] : null;

    $errors = [];
    if ($name === '') {
        $errors[] = 'Nama tidak boleh kosong.';
    }
    if ($nisn === '') {
        $errors[] = 'NISN tidak boleh kosong.';
    }
    if ($birthDate === '') {
        $errors[] = 'Tanggal lahir tidak boleh kosong.';
    }
    if (!in_array($status, ['Lulus', 'Tidak Lulus'], true)) {
        $errors[] = 'Status kelulusan tidak valid.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE ' . $regCol . ' = ? AND id != ?');
    $stmt->execute([$nisn, $id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'NISN sudah ada.';
    }

    if ($errors) {
        return 'Error: ' . implode(' ', $errors);
    }

    $stmt = $pdo->prepare('UPDATE students SET ' . $regCol . ' = ?, name = ?, ' . $dobCol . ' = ?, status = ?, predikat_id = ? WHERE id = ?');
    $stmt->execute([$nisn, $name, $birthDate, $status, $predikatId, $id]);
    return 'Siswa berhasil diperbarui.';
}

function admin_delete_students(PDO $pdo, array $ids) {
    if (!$ids) {
        return 'Tidak ada data yang dipilih.';
    }
    $in = implode(',', array_map('intval', $ids));
    $pdo->query("DELETE FROM students WHERE id IN ($in)");
    return 'Data siswa terpilih telah dihapus.';
}

function admin_get_student_json(PDO $pdo, $regCol, $dobCol, $id) {
    if ($regCol === null || $dobCol === null) {
        return false;
    }

    $select = 'id, ' . $regCol . ' AS nisn, name, ' . $dobCol . ' AS birth_date, status, predikat_id';
    if (columnExists($pdo, 'students', 'is_active')) {
        $select .= ', is_active';
    }

    $stmt = $pdo->prepare('SELECT ' . $select . ' FROM students WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function admin_set_student_active(PDO $pdo, $id, $active) {
    if (!columnExists($pdo, 'students', 'is_active')) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE students SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
    return true;
}

function admin_set_students_active(PDO $pdo, array $ids, $active) {
    if (!$ids || !columnExists($pdo, 'students', 'is_active')) {
        return false;
    }

    $filteredIds = array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    });
    if (!$filteredIds) {
        return false;
    }

    $placeholders = implode(', ', array_fill(0, count($filteredIds), '?'));
    $stmt = $pdo->prepare("UPDATE students SET is_active = ? WHERE id IN ($placeholders)");
    return $stmt->execute(array_merge([$active ? 1 : 0], $filteredIds));
}

function admin_update_student_photo(PDO $pdo, $photoCol, array $post, array $files) {
    if ($photoCol === null) {
        return 'Error: Kolom foto siswa belum tersedia.';
    }

    $uploadDir = dirname(__DIR__, 2) . '/assets/students';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (isset($post['delete_photo_id'])) {
        $studentId = (int)$post['delete_photo_id'];
        if ($studentId <= 0) {
            return 'Error: Siswa tidak valid.';
        }

        $stmt = $pdo->prepare('SELECT ' . $photoCol . ' FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $oldPhoto = $stmt->fetchColumn();
        if ($oldPhoto === false) {
            return 'Error: Data siswa tidak ditemukan.';
        }
        if (empty($oldPhoto)) {
            return 'Foto siswa sudah kosong.';
        }

        $stmt = $pdo->prepare('UPDATE students SET ' . $photoCol . ' = NULL WHERE id = ?');
        $stmt->execute([$studentId]);

        $oldPath = $uploadDir . '/' . basename((string)$oldPhoto);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }

        return 'Foto siswa berhasil dihapus.';
    }

    if (!isset($files['student_photos']) || !isset($files['student_photos']['name']) || !is_array($files['student_photos']['name'])) {
        return 'Error: Tidak ada file foto yang dipilih.';
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    $uploadedCount = 0;
    $errors = [];

    foreach ($files['student_photos']['name'] as $studentIdRaw => $originalName) {
        $studentId = (int)$studentIdRaw;
        if ($studentId <= 0) {
            continue;
        }

        $errorCode = $files['student_photos']['error'][$studentIdRaw] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload gagal untuk siswa ID ' . $studentId . '.';
            continue;
        }

        $tmp = $files['student_photos']['tmp_name'][$studentIdRaw] ?? '';
        $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Format foto untuk siswa ID ' . $studentId . ' harus JPG, JPEG, PNG, atau WEBP.';
            continue;
        }

        if ($tmp === '' || @getimagesize($tmp) === false) {
            $errors[] = 'File untuk siswa ID ' . $studentId . ' bukan gambar valid.';
            continue;
        }

        $stmt = $pdo->prepare('SELECT ' . $photoCol . ' FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $oldPhoto = $stmt->fetchColumn();
        if ($oldPhoto === false) {
            $errors[] = 'Data siswa ID ' . $studentId . ' tidak ditemukan.';
            continue;
        }

        $fileName = 'student_' . $studentId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
        $destination = $uploadDir . '/' . $fileName;
        if (!move_uploaded_file($tmp, $destination)) {
            $errors[] = 'Gagal menyimpan foto untuk siswa ID ' . $studentId . '.';
            continue;
        }

        $stmt = $pdo->prepare('UPDATE students SET ' . $photoCol . ' = ? WHERE id = ?');
        $stmt->execute([$fileName, $studentId]);

        if (!empty($oldPhoto)) {
            $oldPath = $uploadDir . '/' . basename((string)$oldPhoto);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        $uploadedCount++;
    }

    if ($uploadedCount === 0 && $errors === []) {
        return 'Tidak ada foto yang dipilih.';
    }
    if ($uploadedCount > 0 && $errors === []) {
        return 'Berhasil upload/ganti foto untuk ' . $uploadedCount . ' siswa.';
    }
    if ($uploadedCount > 0) {
        return 'Sebagian berhasil (' . $uploadedCount . ' siswa). ' . implode(' ', $errors);
    }

    return 'Error: ' . implode(' ', $errors);
}
