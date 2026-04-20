<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/admin_layout.php';

$context = admin_get_base_context($pdo);
$message = admin_take_flash_message();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = admin_update_student_photo($pdo, $context['photoCol'], $_POST, $_FILES);
    admin_redirect_with_message('student_photos.php', $message);
}

$settings = admin_load_settings($pdo);
$students = admin_load_students($pdo, $context['regCol'], $context['dobCol'], $context['photoCol']);
$idLabel = $context['regCol'] === 'registration_number' ? 'No. Pendaftaran' : 'NISN';
$photoCol = $context['photoCol'];
$regCol = $context['regCol'];

admin_render_page_start('Upload Foto Siswa', 'student-photo', $settings['logo'], $message);
?>
<style>
    .photo-upload-actions {
        margin-bottom: 15px;
        display: flex;
        justify-content: flex-end;
    }



    .photo-grid-wrapper {
        background: #f8f9fa;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 40px;
    }

    .photo-grid-inner {
        flex: 1;
        overflow-x: auto;
        overflow-y: auto;
        max-height: calc(100vh - 320px - var(--admin-footer-space) - var(--admin-footer-gap));
        -webkit-overflow-scrolling: touch;
        scroll-behavior: smooth;
    }

    .photo-grid-inner::-webkit-scrollbar {
        width: 8px;
        height: 6px;
    }

    .photo-grid-inner::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }

    .photo-grid-inner::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 3px;
    }

    .photo-grid-inner::-webkit-scrollbar-thumb:hover {
        background: #a0aec0;
    }

    .photo-grid-inner::-webkit-scrollbar-corner {
        background: #f1f1f1;
    }

    .photo-grid-container {
        display: grid;
        grid-template-columns: repeat(10, minmax(140px, 1fr));
        gap: 12px;
        padding: 10px;
        background: white;
        min-width: min-content;
    }

    .photo-card {
        background: white;
        border: 1px solid #dbe3ef;
        border-radius: 6px;
        padding: 8px;
        text-align: center;
        display: flex;
        flex-direction: column;
        transition: box-shadow 0.2s ease;
        min-width: 140px;
    }

    .photo-card:hover {
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    .photo-frame {
        width: 100%;
        aspect-ratio: 3 / 4;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 6px;
        overflow: hidden;
    }

    .photo-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .photo-frame.no-photo {
        background: #f1f3f7;
        color: #999;
        font-size: 10px;
    }

    .student-name {
        font-weight: 600;
        font-size: 11px;
        color: #333;
        margin-bottom: 6px;
        white-space: normal;
        word-wrap: break-word;
        min-height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1.2;
    }

    .photo-input-group {
        margin-bottom: 6px;
    }

    .photo-input-group small {
        font-size: 9px !important;
    }

    .photo-input-group input[type="file"] {
        font-size: 10px;
        padding: 2px 4px !important;
        height: 26px;
    }

    .photo-card-actions {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .photo-card-actions .btn {
        flex: 1;
        min-width: 50px;
        font-size: 10px;
        padding: 3px 4px;
        height: 26px;
    }

</style>
<div class="card">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <div class="photo-upload-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Upload Foto Terpilih
                </button>
            </div>
            <div class="photo-grid-wrapper">
                <div class="photo-grid-inner" id="photoGridInner">
                    <div class="photo-grid-container" id="photoGridContainer">
                <?php foreach ($students as $student): ?>
                    <div class="photo-card">
                        <div class="photo-frame <?php echo ($photoCol === null || empty($student[$photoCol])) ? 'no-photo' : ''; ?>">
                            <?php if ($photoCol !== null && !empty($student[$photoCol])): ?>
                                <img src="../assets/students/<?php echo htmlspecialchars($student[$photoCol]); ?>" alt="Foto <?php echo htmlspecialchars($student['name']); ?>">
                            <?php else: ?>
                                <span>Belum ada foto</span>
                            <?php endif; ?>
                        </div>
                        <div class="student-name">
                            <?php echo htmlspecialchars($student['name']); ?>
                        </div>
                        <div class="photo-input-group">
                            <small class="form-text text-muted d-block mb-2">Pilih file</small>
                            <input type="file" name="student_photos[<?php echo (int)$student['id']; ?>]" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        <div class="photo-card-actions">
                            <?php if ($photoCol !== null && !empty($student[$photoCol])): ?>
                                <button
                                    type="submit"
                                    name="delete_photo_id"
                                    value="<?php echo (int)$student['id']; ?>"
                                    class="btn btn-danger btn-sm"
                                    onclick="return confirm('Hapus foto siswa ini?');"
                                    title="Hapus Foto"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
admin_render_page_end();
