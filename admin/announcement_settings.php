<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/admin_layout.php';

$context = admin_get_base_context($pdo);
$message = admin_take_flash_message();

if (isset($_POST['time'])) {
    $message = admin_update_announcement_time(
        $pdo,
        $context['hasSettings'],
        $_POST['time'],
        $_POST['announcement_timezone'] ?? 'Asia/Jakarta'
    );
    admin_redirect_with_message('announcement_settings.php', $message);
}

$settings = admin_load_settings($pdo);
$logo = $settings['logo'];
$announcement_time = $settings['announcement_time'];
$announcement_timezone = $settings['announcement_timezone'] ?? 'Asia/Jakarta';

admin_render_page_start('Waktu Pengumuman', 'announcement', $logo, $message);
?>
<style>
    .announcement-time-form {
        max-width: 100%;
    }
    .announcement-time-form .form-group {
        display: flex;
        flex-direction: column;
        margin-bottom: 0;
        flex: 1;
        min-width: 0;
    }
    .announcement-time-form .form-group label {
        display: block;
        font-weight: 500;
        margin-bottom: 0.25rem;
        color: #333;
        font-size: 0.875rem;
    }
    .announcement-time-form .form-group input,
    .announcement-time-form .form-group select {
        width: 100%;
        padding: 0.5rem 0.75rem;
        font-size: 0.9375rem;
        border-radius: 4px;
        border: 1px solid #ced4da;
        line-height: 1.4;
    }
    .announcement-time-form {
        display: flex;
        gap: 0.75rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .announcement-time-form .form-group:nth-child(1) {
        flex: 1.2;
        min-width: 220px;
    }
    .announcement-time-form .form-group:nth-child(2) {
        flex: 1;
        min-width: 180px;
    }
    .announcement-time-form .btn {
        padding: 0.5rem 1.25rem;
        font-size: 0.9375rem;
        font-weight: 500;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        flex-shrink: 0;
    }
    @media (max-width: 640px) {
        .announcement-time-form {
            flex-direction: column;
            gap: 0.5rem;
        }
        .announcement-time-form .form-group {
            min-width: 100% !important;
        }
        .announcement-time-form .btn {
            width: 100%;
        }
    }
</style>
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Pengaturan Waktu Pengumuman</h3>
    </div>
    <div class="card-body">
        <form method="post" class="announcement-time-form">
            <div class="form-group">
                <label for="time">Waktu Pengumuman:</label>
                <input type="datetime-local" id="time" name="time" class="form-control" value="<?php echo htmlspecialchars($announcement_time); ?>">
            </div>
            <div class="form-group">
                <label for="announcement_timezone">Zona Waktu:</label>
                <select id="announcement_timezone" name="announcement_timezone" class="form-control">
                    <option value="Asia/Jakarta" <?php echo $announcement_timezone === 'Asia/Jakarta' ? 'selected' : ''; ?>>WIB (Asia/Jakarta)</option>
                    <option value="Asia/Makassar" <?php echo $announcement_timezone === 'Asia/Makassar' ? 'selected' : ''; ?>>WITA (Asia/Makassar)</option>
                    <option value="Asia/Jayapura" <?php echo $announcement_timezone === 'Asia/Jayapura' ? 'selected' : ''; ?>>WIT (Asia/Jayapura)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
<?php
admin_render_page_end();
