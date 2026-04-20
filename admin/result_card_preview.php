<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/admin_layout.php';

$context = admin_get_base_context($pdo);
$settings = admin_load_settings($pdo);
$logo = $settings['logo'];

$students = admin_load_students_paginated($pdo, $context['regCol'], $context['dobCol'], 1, 1, $context['photoCol']);
$student = $students[0] ?? null;

$status = $student['status'] ?? 'Lulus';
$isLulus = $status === 'Lulus';
$statusHeadline = $isLulus
    ? 'SELAMAT! ANDA DINYATAKAN LULUS, SEMOGA SUKSES DI JENJANG SELANJUTNYA'
    : 'ANDA DINYATAKAN TIDAK LULUS';
$bgColor = $isLulus ? '#0d47a1' : '#c62828';
$bgColor2 = $isLulus ? '#1565c0' : '#d32f2f';

$announcementDate = date('d F Y');
$announcementTimeRaw = (string)($settings['announcement_time'] ?? '');
$announcementTimezone = (string)($settings['announcement_timezone'] ?? 'Asia/Jakarta');
$allowedAnnouncementTimezones = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];
if (!in_array($announcementTimezone, $allowedAnnouncementTimezones, true)) {
    $announcementTimezone = 'Asia/Jakarta';
}
if ($announcementTimeRaw !== '') {
    try {
        $announcementDt = new DateTime($announcementTimeRaw, new DateTimeZone($announcementTimezone));
        $announcementDate = $announcementDt->format('d F Y');
    } catch (Exception $e) {
        $announcementDate = date('d F Y');
    }
}

$resultInfoItems = admin_load_result_info_items($settings);
$predikatText = trim((string)($student['predikat_name'] ?? ''));
if ($predikatText === '') {
    $predikatText = '-';
}
$regCol = $context['regCol'] ?? null;
$dobCol = $context['dobCol'] ?? null;
$photoCol = $context['photoCol'] ?? null;
$regLabel = $regCol === 'registration_number' ? 'No. Pendaftaran' : 'NISN';
$regValue = ($regCol !== null && isset($student[$regCol])) ? $student[$regCol] : '-';
$nameValue = $student['name'] ?? 'Contoh Nama Siswa';
$dobValue = '-';
if ($dobCol !== null && !empty($student[$dobCol] ?? '')) {
    $dobValue = date('d F Y', strtotime((string)$student[$dobCol]));
}

$photoSrc = '';
if ($photoCol !== null && !empty($student[$photoCol] ?? '')) {
    $photoSrc = '../assets/students/' . basename((string)$student[$photoCol]);
}

admin_render_page_start('Preview Kartu Hasil', 'result-preview', $logo, '');
?>
<style>
    .preview-wrap {
        max-width: 1120px;
        margin: 0 auto;
    }
    .preview-note {
        margin-bottom: 1rem;
    }
    .preview-mode-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .preview-mode-label {
        font-weight: 600;
        color: #1f2d3d;
        margin-right: 4px;
    }
    .preview-mode-btn {
        border: 1px solid #c7d8f5;
        background: #fff;
        color: #1f4a86;
        border-radius: 999px;
        padding: 6px 12px;
        font-size: .86rem;
        font-weight: 600;
        cursor: pointer;
    }
    .preview-mode-btn.is-active {
        background: #1f4a86;
        color: #fff;
        border-color: #1f4a86;
    }
    .preview-device-frame {
        border: 1px solid #d5e2f7;
        border-radius: 18px;
        background: #f3f7ff;
        padding: 14px;
        max-height: calc(100vh - 280px);
        overflow-x: scroll;
        overflow-y: scroll;
        overscroll-behavior: contain;
    }
    .preview-result-shell {
        background: linear-gradient(180deg, rgba(229,236,249,.35) 0%, rgba(255,255,255,0) 100%);
        border-radius: 18px;
        padding: 14px;
        width: var(--preview-width, 960px);
        display: inline-block;
        transform-origin: top left;
        transform: scale(var(--preview-scale, 0.86));
    }
    .preview-result-shell .result-page-wrapper {
        position: relative;
        top: auto;
        bottom: auto;
        left: auto;
        transform: none;
        width: 100%;
        max-width: none;
        min-height: 0;
        height: auto;
        margin: 0 auto;
    }
    .preview-result-shell .result-page-footer {
        display: none;
    }
    .preview-result-shell .result-student-photo-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .preview-device-frame[data-mode="desktop"] {
        --preview-width: 960px;
        --preview-scale: 0.86;
    }
    .preview-device-frame[data-mode="tab"] {
        --preview-width: 820px;
        --preview-scale: 0.82;
    }
    .preview-device-frame[data-mode="mobile"] {
        --preview-width: 420px;
        --preview-scale: 0.88;
    }
    @media (max-width: 768px) {
        .preview-device-frame {
            padding: 8px;
            border-radius: 14px;
            max-height: calc(100vh - 250px);
        }
        .preview-result-shell {
            padding: 8px;
            border-radius: 14px;
        }
    }
</style>

<div class="preview-wrap">
    <div class="alert alert-info preview-note">
        Ini adalah preview tampilan kartu hasil pengumuman. Data yang ditampilkan memakai siswa aktif pertama jika tersedia.
        <a href="../index.php" target="_blank" rel="noopener noreferrer" class="alert-link">Buka halaman pengumuman publik</a>.
    </div>

    <div class="preview-mode-toolbar">
        <span class="preview-mode-label">Mode Preview:</span>
        <button type="button" class="preview-mode-btn is-active" data-preview-mode="desktop">Desktop</button>
        <button type="button" class="preview-mode-btn" data-preview-mode="tab">Tab</button>
        <button type="button" class="preview-mode-btn" data-preview-mode="mobile">Mobile</button>
    </div>

    <div class="preview-device-frame" id="previewDeviceFrame" data-mode="desktop">
        <div class="preview-result-shell">
            <div class="result-page-wrapper" style="animation: slideInFrom 0.6s ease-out;">
                <div class="result-page-top" style="background: linear-gradient(135deg, <?php echo $bgColor; ?> 0%, <?php echo $bgColor2; ?> 100%);">
                    <?php if ($isLulus): ?>
                        <div class="result-page-title-wrap">
                            <?php if (!empty($logo)): ?>
                                <img src="../assets/<?php echo htmlspecialchars($logo); ?>" alt="Logo Sekolah" class="result-page-title-logo">
                            <?php endif; ?>
                            <h2 class="result-page-title"><?php echo htmlspecialchars($statusHeadline); ?></h2>
                        </div>
                    <?php else: ?>
                        <h2 class="result-page-title">ANDA DINYATAKAN<br>TIDAK LULUS</h2>
                    <?php endif; ?>
                </div>
                <div class="result-page-bottom">
                    <div class="result-content fade-in">
                        <div class="result-divider"></div>
                        <div class="result-info-box result-info-layout">
                            <div class="result-photo-column">
                                <div class="result-photo-container">
                                    <div class="result-info-photo-col">
                                        <div class="result-photo-frame">
                                            <?php if ($photoSrc !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($photoSrc); ?>" alt="Foto siswa" class="result-student-photo">
                                            <?php else: ?>
                                                <div class="result-student-photo result-student-photo-placeholder">Foto</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="result-data-column">
                                <div class="result-info-details">
                                    <p class="result-label">Nama Peserta</p>
                                    <p class="result-name"><?php echo htmlspecialchars($nameValue); ?></p>
                                    <div class="result-row-2col">
                                        <div class="result-col">
                                            <p class="result-label"><?php echo htmlspecialchars($regLabel); ?></p>
                                            <p class="result-info-text"><?php echo htmlspecialchars((string)$regValue); ?></p>
                                        </div>
                                        <div class="result-col">
                                            <p class="result-label">Tanggal Lahir</p>
                                            <p class="result-info-text"><?php echo htmlspecialchars($dobValue); ?></p>
                                        </div>
                                    </div>
                                    <div class="result-row-2col">
                                        <div class="result-col">
                                            <p class="result-label">Predikat</p>
                                            <p class="result-info-text"><?php echo htmlspecialchars($predikatText); ?></p>
                                        </div>
                                        <div class="result-col">
                                            <p class="result-label">Tanggal Pengumuman</p>
                                            <p class="result-info-text"><?php echo htmlspecialchars($announcementDate); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php foreach ($resultInfoItems as $item): ?>
                            <div class="result-extra-note">
                                <div class="result-extra-note-text" style="opacity: <?php echo htmlspecialchars($item['opacity'] ?? '1'); ?>;"><?php echo $item['text']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="result-page-footer">
                    <a href="#" class="result-back-button">Kembali Ke Pencarian</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$extraScript = <<<'SCRIPT'
<script>
    (function () {
        var frame = document.getElementById('previewDeviceFrame');
        var modeButtons = document.querySelectorAll('[data-preview-mode]');
        var storageKey = 'resultCardPreviewMode';
        var allowedModes = ['desktop', 'tab', 'mobile'];
        if (!frame || !modeButtons.length) {
            return;
        }

        function applyMode(mode) {
            var safeMode = allowedModes.indexOf(mode) !== -1 ? mode : 'desktop';
            frame.setAttribute('data-mode', safeMode);
            modeButtons.forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-preview-mode') === safeMode);
            });
            try {
                window.localStorage.setItem(storageKey, safeMode);
            } catch (e) {
                // Ignore storage errors in restricted environments.
            }
        }

        modeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                applyMode(button.getAttribute('data-preview-mode') || 'desktop');
            });
        });

        try {
            var savedMode = window.localStorage.getItem(storageKey);
            if (savedMode) {
                applyMode(savedMode);
            }
        } catch (e) {
            // Ignore storage read errors in restricted environments.
        }
    })();
</script>
SCRIPT;
admin_render_page_end($extraScript);
