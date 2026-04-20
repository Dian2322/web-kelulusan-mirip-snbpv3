<?php
require_once 'config.php';

if (isset($_GET['server_time']) && $_GET['server_time'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'server_now_unix_ms' => (int)round(microtime(true) * 1000),
    ]);
    exit;
}

// Function to check if column exists in table
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

function tableExists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote((string)$table));
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

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

function sanitizeResultInfoInlineStyle($style) {
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

function sanitizeResultInfoNoteHtml($html) {
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

        $safeStyle = sanitizeResultInfoInlineStyle($rawStyle);
        return $safeStyle !== '' ? ' style="' . htmlspecialchars($safeStyle, ENT_QUOTES, 'UTF-8') . '"' : '';
    }, $html);
    $html = preg_replace('/\s+href\s*=\s*("javascript:.*?"|\'javascript:.*?\'|javascript:[^\s>]+)/i', '', $html);
    $html = preg_replace('/\s+src\s*=\s*("javascript:.*?"|\'javascript:.*?\'|javascript:[^\s>]+)/i', '', $html);
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><span><img><div><a><h1><h2><h3><h4><h5><h6><blockquote><pre><table><thead><tbody><tr><th><td><hr><sup><sub>');
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
    return trim($html);
}

function hasMeaningfulResultInfoNote($html) {
    $plain = trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $plain !== '';
}

function ensureStudentActiveColumn(PDO $pdo) {
    if (columnExists($pdo, 'students', 'is_active')) {
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER predikat_id");
        return columnExists($pdo, 'students', 'is_active');
    } catch (Throwable $e) {
        return false;
    }
}

function normalizeResultInfoImagePaths($html) {
    // Fix broken image paths that may have been stored with incorrect prefixes
    // Replace /web_kelulusan/daz/ with /daz/ or appropriate app path
    $html = str_replace('/web_kelulusan/daz/', '/daz/', $html);
    
    // Also handle case where /web_kelulusan/assets/ was used
    $html = str_replace('/web_kelulusan/assets/', '/daz/assets/', $html);
    
    return $html;
}

function normalizeResultInfoOpacity($opacity) {
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

function loadResultInfoItems(array $settings) {
    $items = json_decode((string)($settings['result_info_items'] ?? '[]'), true);
    $normalized = [];

    if (is_array($items)) {
        foreach ($items as $item) {
            $html = $item['text'] ?? '';
            // Normalize image paths before sanitizing
            $html = normalizeResultInfoImagePaths($html);
            $html = sanitizeResultInfoNoteHtml($html);
            if (!hasMeaningfulResultInfoNote($html)) {
                continue;
            }
            $normalized[] = [
                'text' => $html,
                'opacity' => normalizeResultInfoOpacity($item['opacity'] ?? '1'),
            ];
        }
    }

    if ($normalized === []) {
        $legacyHtml = $settings['result_info_note'] ?? '';
        // Normalize paths for legacy note as well
        $legacyHtml = normalizeResultInfoImagePaths($legacyHtml);
        $legacyHtml = sanitizeResultInfoNoteHtml($legacyHtml);
        if (hasMeaningfulResultInfoNote($legacyHtml)) {
            $normalized[] = [
                'text' => $legacyHtml,
                'opacity' => normalizeResultInfoOpacity($settings['result_info_note_opacity'] ?? '1'),
            ];
        }
    }

    return $normalized;
}

function getStudentPredikatText(PDO $pdo, array $row) {
    // Nilai teks yang mungkin tersimpan langsung di tabel students.
    $candidates = ['predikat', 'predikat_name', 'predikat_text', 'predikat_label', 'resolved_predikat', 'predikat_ref_name'];

    foreach ($candidates as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    if (isset($row['predikat_id']) && (string)$row['predikat_id'] !== '' && tableExists($pdo, 'predikat')) {
        try {
            $stmt = $pdo->prepare('SELECT name FROM predikat WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$row['predikat_id']]);
            $name = $stmt->fetchColumn();
            if ($name !== false && trim((string)$name) !== '') {
                return (string)$name;
            }
        } catch (Exception $e) {
            // Ignore and use fallback below.
        }
    }

    return '-';
}

function resolveStudentPredikatFromDatabase(PDO $pdo, array $row, $regCol = null) {
    // Prioritas utama: predikat_id pada tabel students.
    if (isset($row['predikat_id']) && (int)$row['predikat_id'] > 0 && tableExists($pdo, 'predikat')) {
        try {
            $stmt = $pdo->prepare('SELECT name FROM predikat WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$row['predikat_id']]);
            $name = $stmt->fetchColumn();
            if ($name !== false && trim((string)$name) !== '') {
                return (string)$name;
            }
        } catch (Exception $e) {
            // Fallback ke kandidat teks di bawah.
        }
    }

    $directPredikat = getStudentPredikatText($pdo, $row);
    if (trim((string)$directPredikat) !== '' && $directPredikat !== '-') {
        return $directPredikat;
    }

    if (!tableExists($pdo, 'predikat')) {
        return $directPredikat;
    }

    try {
        if (isset($row['id']) && (int)$row['id'] > 0) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(NULLIF(TRIM(p.name), \'\'), \'-\')
                 FROM students s
                 LEFT JOIN predikat p ON s.predikat_id = p.id
                 WHERE s.id = ?
                 LIMIT 1'
            );
            $stmt->execute([(int)$row['id']]);
            $value = $stmt->fetchColumn();
            if ($value !== false && trim((string)$value) !== '') {
                return (string)$value;
            }
        }

        if ($regCol !== null && isset($row[$regCol]) && trim((string)$row[$regCol]) !== '') {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(NULLIF(TRIM(p.name), \'\'), \'-\')
                 FROM students s
                 LEFT JOIN predikat p ON s.predikat_id = p.id
                 WHERE s.' . $regCol . ' = ?
                 LIMIT 1'
            );
            $stmt->execute([(string)$row[$regCol]]);
            $value = $stmt->fetchColumn();
            if ($value !== false && trim((string)$value) !== '') {
                return (string)$value;
            }
        }
    } catch (Exception $e) {
        // Fallback below.
    }

    return $directPredikat;
}

function buildPredikatSelectSql(PDO $pdo) {
    $parts = [];

    if (columnExists($pdo, 'students', 'predikat')) {
        $parts[] = "NULLIF(TRIM(s.predikat), '')";
    }
    if (columnExists($pdo, 'students', 'predikat_name')) {
        $parts[] = "NULLIF(TRIM(s.predikat_name), '')";
    }
    if (columnExists($pdo, 'students', 'predikat_text')) {
        $parts[] = "NULLIF(TRIM(s.predikat_text), '')";
    }
    if (columnExists($pdo, 'students', 'predikat_label')) {
        $parts[] = "NULLIF(TRIM(s.predikat_label), '')";
    }
    if (tableExists($pdo, 'predikat') && columnExists($pdo, 'students', 'predikat_id')) {
        $parts[] = "NULLIF(TRIM(p.name), '')";
    }

    if ($parts === []) {
        return "'-'";
    }

    return 'COALESCE(' . implode(', ', $parts) . ", '-')";
}

// Support both schema variants:
// - nisn + birth_date (kelulusan_import.sql)
// - registration_number + date_of_birth (db.sql)
$regCol = columnExists($pdo, 'students', 'nisn') ? 'nisn' : (columnExists($pdo, 'students', 'registration_number') ? 'registration_number' : null);
$dobCol = columnExists($pdo, 'students', 'birth_date') ? 'birth_date' : (columnExists($pdo, 'students', 'date_of_birth') ? 'date_of_birth' : null);
$photoCol = columnExists($pdo, 'students', 'photo') ? 'photo' : null;
$hasStatus = columnExists($pdo, 'students', 'status');
$hasPredikat = columnExists($pdo, 'students', 'predikat_id');
$predikatCol = columnExists($pdo, 'students', 'predikat') ? 'predikat' : (columnExists($pdo, 'students', 'predikat_name') ? 'predikat_name' : null);
$hasPredikatTable = tableExists($pdo, 'predikat');
$predikatSelectSql = buildPredikatSelectSql($pdo);
$idLabel = $regCol === 'registration_number' ? 'No. Pendaftaran' : 'NISN';

$hasActive = ensureStudentActiveColumn($pdo) && columnExists($pdo, 'students', 'is_active');
$activeCondition = $hasActive ? ' AND s.is_active = 1' : ''; 

// fetch announcement_time and background
$settings = [];
if (ensureSettingsTableSafe($pdo) || tableExists($pdo, 'settings')) {
    $stmt = $pdo->query("SELECT name,value FROM settings WHERE name IN ('announcement_time','announcement_timezone','background','logo','skl_link','skl_label','result_info_note','result_info_note_color','result_info_note_opacity','result_info_note_icon','result_info_items')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
$announcement_time = $settings['announcement_time'] ?? '';
$allowedAnnouncementTimezones = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];
$announcementTimezone = trim((string)($settings['announcement_timezone'] ?? 'Asia/Jakarta'));
if (!in_array($announcementTimezone, $allowedAnnouncementTimezones, true)) {
    $announcementTimezone = 'Asia/Jakarta';
}
$announcementTimestamp = false;
if ($announcement_time !== '') {
    try {
        $announcementDt = new DateTime($announcement_time, new DateTimeZone($announcementTimezone));
        $announcementTimestamp = $announcementDt->getTimestamp();
    } catch (Exception $e) {
        $announcementTimestamp = false;
    }
}
$announcementUnixMs = $announcementTimestamp !== false ? ((int)$announcementTimestamp * 1000) : 0;
$serverNowUnixMs = (int)round(microtime(true) * 1000);
$isAnnouncementOpen = !($announcementTimestamp !== false && $announcementTimestamp > time());
$background = $settings['background'] ?? '';
$logo = trim($settings['logo'] ?? 'logo.png');
if ($logo === '') {
    $logo = 'logo.png';
}
$logoPath = 'assets/' . $logo;
if (!file_exists($logoPath)) {
    $logo = 'logo.png';
}
$sklLink = trim($settings['skl_link'] ?? '');
$sklLabel = trim($settings['skl_label'] ?? 'Download SKL.Pdf');
$resultInfoItems = loadResultInfoItems($settings);
if ($sklLabel === '') {
    $sklLabel = 'Download SKL.Pdf';
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];
$isReset = isset($_GET['reset']);
$showResult = isset($_GET['nisn']) && (isset($_GET['dob']) || (isset($_GET['dob_year']) && isset($_GET['dob_month']) && isset($_GET['dob_day']))) && !$isReset;
$alertMessage = '';

// Support manual date input (year, month, day) and normalize to YYYY-MM-DD
$dob = isset($_GET['dob']) ? trim($_GET['dob']) : '';
$dobYear = isset($_GET['dob_year']) ? trim($_GET['dob_year']) : '';
$dobMonth = isset($_GET['dob_month']) ? trim($_GET['dob_month']) : '';
$dobDay = isset($_GET['dob_day']) ? trim($_GET['dob_day']) : '';
if ($dob === '' && $dobYear !== '' && $dobMonth !== '' && $dobDay !== '') {
    $y = (int)$dobYear;
    $m = (int)$dobMonth;
    $d = (int)$dobDay;
    if (checkdate($m, $d, $y)) {
        $dob = sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
    $parts = explode('-', $dob);
    $dobYear = $parts[0];
    $dobMonth = $parts[1];
    $dobDay = $parts[2];
}
if ($search !== '') {
    if ($regCol !== null) {
        $sql = "SELECT * FROM students WHERE ({$regCol} = :n OR name LIKE :name)";
        if ($hasActive) {
            $sql .= ' AND is_active = 1';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['n' => $search, 'name' => "%$search%"]);
    } else {
        $sql = 'SELECT * FROM students WHERE name LIKE :name';
        if ($hasActive) {
            $sql .= ' AND is_active = 1';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['name' => "%$search%"]);
    }
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengumuman Kelulusan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <?php if (!empty($background)): ?>
    <style>body{background:url('assets/<?php echo htmlspecialchars($background); ?>') no-repeat center/cover;}</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php if (!$isAnnouncementOpen): ?>
            <div class="snbp-countdown-wrapper">
                <div class="countdown-footer-bar">
                    <span class="countdown-announcement-left">PENGUMUMAN</span>
                    <span class="countdown-date-info">DIBUKA PADA TANGGAL <?php echo $announcementTimestamp !== false ? date('d M Y', $announcementTimestamp) : date('d M Y'); ?></span>
                </div>
                <div class="snbp-countdown-top">
                    <!-- Isi countdown, misal angka dan label -->
                    <div id="countdown" style="color:#fff;font-size:2rem;font-weight:bold;"></div>
                </div>
                <div class="snbp-countdown-bottom">
                    <!-- Tambahkan info atau label tambahan di sini -->
                </div>
            </div>
        <?php endif; ?>
        <!-- Kelulusan header dihapus sesuai permintaan -->
        <?php if ($isAnnouncementOpen): ?>
            <div class="snbp-search-wrapper">
                <div class="snbp-search-top">
                <?php if (!empty($logo)): ?>
                    <div class="snbp-search-logo">
                        <img src="assets/<?php echo htmlspecialchars($logo); ?>" alt="Logo Pengumuman">
                    </div>
                <?php endif; ?>
                <div class="snbp-search-header">
                    <h2 class="snbp-search-title">PENGUMUMAN KELULUSAN</h2>
                    <p class="snbp-search-subtitle">Masukkan Nomor Induk Siswa Nasional dan Tanggal Lahir.</p>
                </div>
            </div>
            <div class="snbp-search-bottom">
                    <form method="get" action="" class="snbp-search-form">
                        <label for="nisn" class="form-label"><?php echo htmlspecialchars($idLabel); ?></label>
                        <input type="text" id="nisn" name="nisn" value="<?php echo isset($_GET['nisn']) ? htmlspecialchars($_GET['nisn']) : ''; ?>" placeholder="Nomor Induk Siswa Nasional" required>
                        <label class="form-label">Tanggal Lahir</label>
                        <div class="dob-row">
                            <input type="text" id="dob_year" name="dob_year" value="<?php echo htmlspecialchars($dobYear); ?>" placeholder="Tahun" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                            <span class="dob-divider">/</span>
                            <input type="text" id="dob_month" name="dob_month" value="<?php echo htmlspecialchars($dobMonth); ?>" placeholder="Bulan" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required>
                            <span class="dob-divider">/</span>
                            <input type="text" id="dob_day" name="dob_day" value="<?php echo htmlspecialchars($dobDay); ?>" placeholder="Tanggal" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required>
                        </div>
                        <div class="button-container">
                            <button type="submit">Cek Kelulusan</button>
                            <a
                                href="<?php echo $sklLink !== '' ? htmlspecialchars($sklLink) : '#'; ?>"
                                <?php if ($sklLink !== ''): ?>
                                    target="_blank" rel="noopener noreferrer"
                                <?php else: ?>
                                    aria-disabled="true"
                                <?php endif; ?>
                                class="download-skl-text<?php echo $sklLink === '' ? ' is-disabled' : ''; ?>"
                            ><?php echo htmlspecialchars($sklLabel); ?></a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Pencarian berdasarkan NISN
        if (isset($_GET['nisn']) && ($dob !== '' || ($dobYear !== '' && $dobMonth !== '' && $dobDay !== ''))) {
            if (!$isAnnouncementOpen) {
                $alertMessage = 'Pengumuman belum dibuka. Silakan tunggu sampai waktu pengumuman resmi.';
                $results = [];
            } else {
            $nisn = trim($_GET['nisn']);
            if ($dob === '') {
                $alertMessage = 'Format tanggal lahir tidak valid. Gunakan urutan Tahun, Bulan, Hari yang benar.';
            }
            if ($regCol === null || $dobCol === null) {
                $results = [];
                $alertMessage = 'Struktur tabel students belum sesuai. Hubungi admin untuk sinkronisasi database.';
            } elseif ($dob !== '') {
                if ($hasPredikat && $hasPredikatTable) {
                    $stmt = $pdo->prepare("SELECT s.*, p.name as predikat_ref_name, {$predikatSelectSql} AS resolved_predikat, COALESCE({$predikatSelectSql}, '-') AS student_predikat_name FROM students s LEFT JOIN predikat p ON s.predikat_id = p.id WHERE s.{$regCol} = :n" . ($hasActive ? ' AND s.is_active = 1' : ''));
                } elseif ($predikatCol !== null) {
                    $stmt = $pdo->prepare("SELECT s.*, {$predikatSelectSql} AS resolved_predikat, COALESCE({$predikatSelectSql}, '-') AS student_predikat_name FROM students s WHERE s.{$regCol} = :n" . ($hasActive ? ' AND s.is_active = 1' : ''));
                } else {
                    $stmt = $pdo->prepare("SELECT s.*, {$predikatSelectSql} AS resolved_predikat, COALESCE({$predikatSelectSql}, '-') AS student_predikat_name FROM students s WHERE s.{$regCol} = :n" . ($hasActive ? ' AND s.is_active = 1' : ''));
                }
                $stmt->execute(['n' => $nisn]);
                $results = $stmt->fetchAll();
            }
            if (count($results) > 0) {
                $row = $results[0];
                if (isset($row[$dobCol]) && $row[$dobCol] !== $dob) {
                    $alertMessage = 'Data ditemukan tetapi tanggal lahir tidak cocok. Silakan periksa kembali.';
                } else {
                    $statusClass = strtolower(str_replace(' ', '-', $row['status'] ?? ''));
                    $isLulus = ($row['status'] ?? '') === 'Lulus';
                    $bgColor = $isLulus ? '#0d47a1' : '#c62828';
                    $dobFormatted = isset($row[$dobCol]) ? date('d F Y', strtotime($row[$dobCol])) : 'Tidak tersedia';
                    $announcementDate = $announcementTimestamp !== false ? date('d F Y', $announcementTimestamp) : date('d F Y');
                    
                    echo '<div class="result-page-wrapper" style="animation: slideInFrom 0.6s ease-out;">';
                    echo '<div class="result-page-top" style="background: linear-gradient(135deg, ' . $bgColor . ' 0%, ' . ($isLulus ? '#1565c0' : '#d32f2f') . ' 100%);">';
                    if ($isLulus) {
                        echo '<div class="result-page-title-wrap">';
                        if (!empty($logo)) {
                            echo '<img src="assets/' . htmlspecialchars($logo) . '" alt="Logo Sekolah" class="result-page-title-logo">';
                        }
                        echo '<h2 class="result-page-title">SELAMAT! ANDA DINYATAKAN LULUS, SEMOGA SUKSES DI JENJANG SELANJUTNYA</h2>';
                        echo '</div>';
                    } else {
                        echo '<h2 class="result-page-title">ANDA DINYATAKAN<br>TIDAK LULUS</h2>';
                    }
                    echo '</div>';
                    echo '<div class="result-page-bottom">';
                    echo '<div class="result-content fade-in">';
                    echo '<div class="result-divider"></div>';
                    echo '<div class="result-info-box result-info-layout">';
                    echo '<div class="result-photo-column">';
                    echo '<div class="result-photo-container">';
                    echo '<div class="result-info-photo-col">';
                    echo '<div class="result-photo-frame">';
                    if ($photoCol !== null && !empty($row[$photoCol])) {
                        echo '<img src="assets/students/' . htmlspecialchars($row[$photoCol]) . '" alt="Foto siswa" class="result-student-photo">';
                    } else {
                        echo '<div class="result-student-photo result-student-photo-placeholder">Foto</div>';
                    }
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="result-data-column">';
                    echo '<div class="result-info-details">';
                    echo '<p class="result-label">Nama Peserta</p>';
                    echo '<p class="result-name">' . htmlspecialchars($row['name']) . '</p>';
                    $predikatText = trim((string)($row['student_predikat_name'] ?? ''));
                    if ($predikatText === '' || $predikatText === '-') {
                        $predikatText = resolveStudentPredikatFromDatabase($pdo, $row, $regCol);
                    }
                    echo '<div class="result-row-2col">';
                    echo '<div class="result-col">';
                    echo '<p class="result-label">' . htmlspecialchars($idLabel) . '</p>';
                    echo '<p class="result-info-text">' . htmlspecialchars($row[$regCol] ?? '') . '</p>';
                    echo '</div>';
                    echo '<div class="result-col">';
                    echo '<p class="result-label">Tanggal Lahir</p>';
                    echo '<p class="result-info-text">' . $dobFormatted . '</p>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="result-row-2col">';
                    echo '<div class="result-col">';
                    echo '<p class="result-label">Predikat</p>';
                    echo '<p class="result-info-text">' . htmlspecialchars($predikatText) . '</p>';
                    echo '</div>';
                    echo '<div class="result-col">';
                    echo '<p class="result-label">Tanggal Pengumuman</p>';
                    echo '<p class="result-info-text">' . $announcementDate . '</p>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    $backUrl = 'index.php';
                    foreach ($resultInfoItems as $resultInfoItem) {
                        echo '<div class="result-extra-note">';
                        echo '<div class="result-extra-note-text" style="opacity: ' . htmlspecialchars($resultInfoItem['opacity'] ?? '1') . ';">' . $resultInfoItem['text'] . '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="result-page-footer">';
                    echo '<a href="' . htmlspecialchars($backUrl) . '" class="result-back-button">Kembali Ke Pencarian</a>';
                    echo '</div>';
                    echo '</div>';
                }
            } elseif ($alertMessage === '') {
                $alertMessage = 'Data tidak ditemukan.';
            }
            }
        }
        ?>

        <div id="alertModal" class="alert-modal-overlay" style="display:none;">
            <div class="alert-modal-card">
                <div class="alert-modal-header">
                    <span class="alert-modal-title">Pemberitahuan</span>
                    <button type="button" class="alert-modal-close" onclick="hideAlert()" aria-label="Tutup">&times;</button>
                </div>
                <div class="alert-modal-body" id="alertModalMessage"></div>
                <div class="alert-modal-footer">
                    <button type="button" class="alert-modal-action" onclick="hideAlert()">OK</button>
                </div>
            </div>
        </div>

        <script>
            function attachKelulusanSubmitDelay(scope) {
                var root = scope || document;
                var forms = root.querySelectorAll('form.snbp-search-form');
                forms.forEach(function (form) {
                    if (form.dataset.submitDelayAttached === '1') {
                        return;
                    }
                    form.dataset.submitDelayAttached = '1';
                    form.addEventListener('submit', function (event) {
                        if (form.dataset.processing === '1') {
                            event.preventDefault();
                            return;
                        }
                        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                            return;
                        }

                        event.preventDefault();
                        form.dataset.processing = '1';

                        var submitBtn = form.querySelector('button[type="submit"]');
                        if (!submitBtn) {
                            form.submit();
                            return;
                        }

                        if (!submitBtn.dataset.originalText) {
                            submitBtn.dataset.originalText = submitBtn.textContent;
                        }
                        var steps = ['Memproses', 'Memproses.', 'Memproses..', 'Memproses...'];
                        var totalTicks = steps.length * 2; // 2 kali pengulangan penuh
                        var tick = 0;
                        submitBtn.textContent = steps[0];
                        submitBtn.disabled = true;
                        submitBtn.dataset.pulseInterval = String(window.setInterval(function () {
                            tick += 1;
                            submitBtn.textContent = steps[tick % steps.length];
                            if (tick >= totalTicks) {
                                var intervalId = Number(submitBtn.dataset.pulseInterval || '0');
                                if (intervalId) {
                                    window.clearInterval(intervalId);
                                }
                                form.submit();
                            }
                        }, 350));
                    });
                });
            }

            function showAlert(message) {
                const overlay = document.getElementById('alertModal');
                const messageEl = document.getElementById('alertModalMessage');
                if (!overlay || !messageEl) return;
                messageEl.textContent = message;
                overlay.style.display = 'flex';
            }
            function hideAlert() {
                const overlay = document.getElementById('alertModal');
                if (!overlay) return;
                overlay.style.display = 'none';
            }
            document.addEventListener('DOMContentLoaded', function () {
                var message = <?php echo json_encode($alertMessage); ?>;
                attachKelulusanSubmitDelay(document);
                if (message) {
                    showAlert(message);
                }
            });
        </script>

        <?php if (!$isAnnouncementOpen): ?>
        <script>
            let countdownTargetUnixMs = <?php echo (int)$announcementUnixMs; ?>;
            let trustedServerNowUnixMs = <?php echo (int)$serverNowUnixMs; ?>;
            let trustedNowAnchorPerfMs = performance.now();
            const countdownEl = document.getElementById('countdown');
            const formHTML = `
                <div class="snbp-search-wrapper">
                    <div class="snbp-search-top">
                        <?php if (!empty($logo)): ?>
                            <div class="snbp-search-logo">
                                <img src="assets/<?php echo htmlspecialchars($logo); ?>" alt="Logo Pengumuman">
                            </div>
                        <?php endif; ?>
                        <div class="snbp-search-header">
                            <h2 class="snbp-search-title">PENGUMUMAN KELULUSAN</h2>
                            <p class="snbp-search-subtitle">Masukkan Nomor Induk Siswa Nasional dan Tanggal Lahir.</p>
                        </div>
                    </div>
                    <div class="snbp-search-bottom">
                        <form method="get" action="" class="snbp-search-form">
                            <label for="nisn" class="form-label"><?php echo htmlspecialchars($idLabel); ?></label>
                            <input type="text" id="nisn" name="nisn" placeholder="Nomor Induk Siswa Nasional" required>
                            <label class="form-label">Tanggal Lahir</label>
                            <div class="dob-row">
                                <input type="text" id="dob_year" name="dob_year" placeholder="Tahun" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                                <span class="dob-divider">/</span>
                                <input type="text" id="dob_month" name="dob_month" placeholder="Bulan" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required>
                                <span class="dob-divider">/</span>
                                <input type="text" id="dob_day" name="dob_day" placeholder="Tanggal" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" required>
                            </div>
                            <div class="button-container">
                                <button type="submit">Cek Kelulusan</button>
                                <a
                                    href="<?php echo $sklLink !== '' ? htmlspecialchars($sklLink) : '#'; ?>"
                                    <?php if ($sklLink !== ''): ?>
                                        target="_blank" rel="noopener noreferrer"
                                    <?php else: ?>
                                        aria-disabled="true"
                                    <?php endif; ?>
                                    class="download-skl-text<?php echo $sklLink === '' ? ' is-disabled' : ''; ?>"
                                ><?php echo htmlspecialchars($sklLabel); ?></a>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            function getTrustedNowUnixMs() {
                // Monotonic server baseline; changing device clock won't affect countdown.
                return trustedServerNowUnixMs + (performance.now() - trustedNowAnchorPerfMs);
            }
            function syncTrustedClock() {
                return fetch('index.php?server_time=1', {
                    method: 'GET',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Failed to sync server time');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || typeof payload.server_now_unix_ms !== 'number') {
                        return;
                    }
                    trustedServerNowUnixMs = payload.server_now_unix_ms;
                    trustedNowAnchorPerfMs = performance.now();
                })
                .catch(function () {
                    // Keep local monotonic baseline if sync fails.
                });
            }
            function updateCount() {
                const nowUnixMs = getTrustedNowUnixMs();
                const diff = countdownTargetUnixMs - nowUnixMs;
                if (diff <= 0) {
                    document.querySelector('.snbp-countdown-wrapper').style.display = 'none';
                    document.querySelector('.container').insertAdjacentHTML('beforeend', formHTML);
                    attachKelulusanSubmitDelay(document);
                    clearInterval(timer);
                    clearInterval(syncTimer);
                    return;
                }
                const days = Math.floor(diff/1000/60/60/24);
                const hrs  = Math.floor(diff/1000/60/60)%24;
                const mins = Math.floor(diff/1000/60)%60;
                const secs = Math.floor(diff/1000)%60;
                function pad2(n) { return n.toString().padStart(2, '0'); }
                countdownEl.innerHTML = `
                    <div class="countdown-wrapper">
                        <div class="countdown-item">
                            <span class="countdown-value">${pad2(days)}</span>
                            <span class="countdown-label">Hari</span>
                        </div>
                        <div class="countdown-separator">:</div>
                        <div class="countdown-item">
                            <span class="countdown-value">${pad2(hrs)}</span>
                            <span class="countdown-label">Jam</span>
                        </div>
                        <div class="countdown-separator">:</div>
                        <div class="countdown-item">
                            <span class="countdown-value">${pad2(mins)}</span>
                            <span class="countdown-label">Menit</span>
                        </div>
                        <div class="countdown-separator">:</div>
                        <div class="countdown-item">
                            <span class="countdown-value">${pad2(secs)}</span>
                            <span class="countdown-label">Detik</span>
                        </div>
                    </div>
                `;
            }
            updateCount();
            const timer = setInterval(updateCount, 1000);
            const syncTimer = setInterval(syncTrustedClock, 45000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    syncTrustedClock();
                }
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
