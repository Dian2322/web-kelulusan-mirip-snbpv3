<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/admin_layout.php';

$context = admin_get_base_context($pdo);
$message = admin_take_flash_message('admin_result_info_message');
$settings = admin_load_settings($pdo);

if (isset($_POST['action']) && $_POST['action'] === 'add_result_info_item') {
    $message = admin_add_result_info_item($pdo, $context['hasSettings'], $settings, $_POST);
    admin_redirect_with_message('result_info_settings.php', $message, 'admin_result_info_message');
}
if (isset($_POST['action']) && $_POST['action'] === 'delete_result_info_item') {
    $message = admin_delete_result_info_item($pdo, $context['hasSettings'], $settings, $_POST['item_index'] ?? -1);
    admin_redirect_with_message('result_info_settings.php', $message, 'admin_result_info_message');
}
if (isset($_POST['action']) && $_POST['action'] === 'reorder_result_info_items') {
    $order = isset($_POST['item_order']) ? (array)$_POST['item_order'] : [];
    $message = admin_reorder_result_info_items($pdo, $context['hasSettings'], $settings, $order);
    admin_redirect_with_message('result_info_settings.php', $message, 'admin_result_info_message');
}

$settings = admin_load_settings($pdo);
$logo = $settings['logo'];
$resultInfoOpacity = $settings['result_info_note_opacity'] ?? '1';
$resultInfoItems = admin_load_result_info_items($settings);

admin_render_page_start('Informasi Tambahan Hasil', 'result-info', $logo, $message);
$summernoteScript = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.css">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.js"></script>
<script>
$(function () {
    var imagePicker = $('<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">');
    var previewTextDefault = 'Teks sample informasi tambahan hasil pengumuman akan tampil seperti ini.';
    $('body').append(imagePicker);
    
    function patchImagePopoverButtons() {
        var map = {
            floatLeft: 'fa-solid fa-align-left',
            floatRight: 'fa-solid fa-align-right',
            floatNone: 'fa-solid fa-align-center',
            removeMedia: 'fa-solid fa-trash'
        };
        Object.keys(map).forEach(function (eventName) {
            $('.note-popover .popover-content .note-btn[data-event="' + eventName + '"]').each(function() {
                var $btn = $(this);
                var iconClass = map[eventName];
                $btn.attr('title', $btn.attr('title') || eventName);
                $btn.attr('aria-label', $btn.attr('aria-label') || eventName);
                $btn.find('i, span').remove();
                $btn.empty().append('<i class="' + iconClass + '" aria-hidden="true"></i>');
                $btn.removeClass('note-btn-text-fallback');
            });
        });
    }

    var selectedEditorImage = null;
    var $editorImageWidth = $('#editor_image_width');
    var $editorImageHeight = $('#editor_image_height');
    var $editorImageApply = $('#editor_image_apply');
    var $editorImageReset = $('#editor_image_reset');

    function syncImageSizeControls(img) {
        if (!img || !img.tagName || img.tagName.toLowerCase() !== 'img') {
            selectedEditorImage = null;
            $editorImageWidth.val('').prop('disabled', true);
            $editorImageHeight.val('').prop('disabled', true);
            $editorImageApply.prop('disabled', true);
            $editorImageReset.prop('disabled', true);
            return;
        }

        selectedEditorImage = img;
        var width = img.style.width || img.getAttribute('width') || '';
        var height = img.style.height || img.getAttribute('height') || '';
        $editorImageWidth.val(width || '');
        $editorImageHeight.val(height || '');
        $editorImageWidth.prop('disabled', false);
        $editorImageHeight.prop('disabled', false);
        $editorImageApply.prop('disabled', false);
        $editorImageReset.prop('disabled', false);
    }

    function normalizeImageSizeValue(value) {
        if (!value) {
            return '';
        }

        var trimmed = value.trim();
        if (/^\d+$/.test(trimmed)) {
            return trimmed + 'px';
        }

        return trimmed;
    }

    function applyImageSize() {
        if (!selectedEditorImage) {
            return;
        }

        var widthValue = normalizeImageSizeValue($editorImageWidth.val());
        var heightValue = normalizeImageSizeValue($editorImageHeight.val());

        if (widthValue === '') {
            selectedEditorImage.style.width = '';
            selectedEditorImage.removeAttribute('width');
        } else {
            selectedEditorImage.style.width = widthValue;
            selectedEditorImage.removeAttribute('width');
        }

        if (heightValue === '') {
            selectedEditorImage.style.height = '';
            selectedEditorImage.removeAttribute('height');
        } else {
            selectedEditorImage.style.height = heightValue;
            selectedEditorImage.removeAttribute('height');
        }

        if (widthValue === '' && heightValue === '') {
            selectedEditorImage.removeAttribute('style');
        }
    }

    function resetImageSize() {
        if (!selectedEditorImage) {
            return;
        }

        selectedEditorImage.style.width = '';
        selectedEditorImage.style.height = '';
        selectedEditorImage.removeAttribute('width');
        selectedEditorImage.removeAttribute('height');
        selectedEditorImage.removeAttribute('style');
        syncImageSizeControls(selectedEditorImage);
    }

    $(document).on('click', '.note-editable img', function(event) {
        event.stopPropagation();
        syncImageSizeControls(this);
    });

    $(document).on('click', function(event) {
        if (!$(event.target).closest('.note-editable img, .result-info-image-controls').length) {
            syncImageSizeControls(null);
        }
    });

    $editorImageApply.on('click', function() {
        applyImageSize();
    });

    $editorImageReset.on('click', function() {
        resetImageSize();
    });

    function uploadResultInfoImage(file) {
        var formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', window.APP_CSRF_TOKEN || '');

        return $.ajax({
            url: 'upload_result_info_image.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        });
    }

    function insertUploadedImages(editor, files) {
        Array.from(files).forEach(function(file) {
            uploadResultInfoImage(file)
                .done(function(response) {
                    if (response && response.url) {
                        editor.summernote('insertImage', response.url, response.filename || 'gambar');
                        return;
                    }
                    alert((response && response.error) ? response.error : 'Upload gambar gagal.');
                })
                .fail(function(xhr) {
                    var message = 'Upload gambar gagal.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        message = xhr.responseJSON.error;
                    }
                    alert(message);
                });
        });
    }

    function updateEditorPreview(contents) {
        var $previewText = $('#resultInfoEditorPreviewText');
        var trimmedText = $('<div>').html(contents || '').text().trim();
        if (trimmedText === '') {
            $previewText.text(previewTextDefault);
            return;
        }
        $previewText.html(contents);
    }

    imagePicker.on('change', function() {
        var input = this;
        if (!input.files || !input.files.length) {
            return;
        }

        var editor = $('#result_info_note');
        insertUploadedImages(editor, input.files);
        input.value = '';
    });

    $('#result_info_note').summernote({
        height: 280,
        minHeight: 200,
        maxHeight: 400,
        placeholder: 'Contoh: Silakan hubungi panitia untuk informasi daftar ulang.',
        dialogsInBody: true,
        disableDragAndDrop: true,
        popover: {
            image: [
                ['imagesize', ['imageSize100', 'imageSize50', 'imageSize25']],
                ['float', ['floatLeft', 'floatRight', 'floatNone']],
                ['remove', ['removeMedia']]
            ]
        },
        fontNames: [
            'Arial',
            'Helvetica',
            'Times New Roman',
            'Courier New',
            'Verdana',
            'Tahoma',
            'Georgia',
            'Trebuchet MS'
        ],
        icons: {
            style: 'fas fa-wand-magic-sparkles',
            bold: 'fas fa-bold',
            italic: 'fas fa-italic',
            underline: 'fas fa-underline',
            clear: 'fas fa-eraser',
            strikethrough: 'fas fa-strikethrough',
            superscript: 'fas fa-superscript',
            subscript: 'fas fa-subscript',
            fontname: 'fas fa-font',
            fontsize: 'fas fa-text-height',
            color: 'fas fa-palette',
            ul: 'fas fa-list-ul',
            ol: 'fas fa-list-ol',
            paragraph: 'fas fa-paragraph',
            table: 'fas fa-table',
            link: 'fas fa-link',
            picture: 'fas fa-image',
            video: 'fas fa-video',
            hr: 'fas fa-minus',
            codeview: 'fas fa-code',
            fullscreen: 'fas fa-expand',
            textHeight: 'fas fa-text-height',
            font: 'fas fa-font',
            alignLeft: 'fas fa-align-left',
            alignCenter: 'fas fa-align-center',
            alignRight: 'fas fa-align-right',
            alignJustify: 'fas fa-align-justify',
            undo: 'fas fa-rotate-left',
            redo: 'fas fa-rotate-right',
            help: 'fas fa-circle-question',
            resizeFull: 'fas fa-expand',
            resizeHalf: 'fas fa-up-right-and-down-left-from-center',
            resizeQuarter: 'fas fa-down-left-and-up-right-to-center',
            resizeNone: 'fas fa-image',
            floatLeft: 'fas fa-align-left',
            floatRight: 'fas fa-align-right',
            floatNone: 'fas fa-align-center',
            removeMedia: 'fas fa-trash'
        },
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'underline', 'clear', 'fontsize']],
            ['fontname', ['fontname']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onInit: function() {
                patchImagePopoverButtons();
                updateEditorPreview($('#result_info_note').summernote('code'));
            },
            onChange: function(contents) {
                updateEditorPreview(contents);
            },
            onImageUpload: function(files) {
                insertUploadedImages($(this), files);
            }
        }
    });
    
    // Popover image buttons are created dynamically, patch them on editor interactions.
    $(document).on('click keyup mouseup', '.note-editor, .note-popover', function() {
        patchImagePopoverButtons();
    });
    var observer = new MutationObserver(function() {
        patchImagePopoverButtons();
    });
    observer.observe(document.body, { childList: true, subtree: true });

    $('#result_info_note_opacity').on('change', function() {
        var opacity = $(this).val();
        $('#resultInfoEditorPreviewText').css('opacity', opacity);
    });
});
</script>
HTML;?>
<style>
    .result-info-settings-scroll {
        max-height: calc(100vh - 150px);
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        padding-bottom: calc(var(--admin-footer-space) + 16px);
    }
    .note-editor.note-frame {
        background: #fff;
        border: 1px solid #dee2e6;
    }
    .note-editor .note-editing-area .note-editable {
        min-height: 180px;
        max-height: 320px;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 0.95rem;
        line-height: 1.6;
        background: #000;
        color: #f5f8ff;
    }
    .note-toolbar {
        background-color: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
    }
    .note-btn {
        color: #495057;
    }
    .note-btn:hover {
        background-color: #e9ecef;
        color: #1b3f72;
    }
    .note-toolbar .note-btn i,
    .note-popover .popover-content .note-btn i {
        font-family: "Font Awesome 6 Free" !important;
        font-weight: 900 !important;
        font-style: normal !important;
        speak: none;
        display: inline-block !important;
        line-height: 1;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    .note-popover .popover-content .note-btn[data-event="imageSize100"] i,
    .note-popover .popover-content .note-btn[data-event="imageSize50"] i,
    .note-popover .popover-content .note-btn[data-event="imageSize25"] i,
    .note-popover .popover-content .note-btn[data-event="floatLeft"] i,
    .note-popover .popover-content .note-btn[data-event="floatRight"] i,
    .note-popover .popover-content .note-btn[data-event="floatNone"] i,
    .note-popover .popover-content .note-btn[data-event="removeMedia"] i {
        font-family: "Font Awesome 6 Free" !important;
        font-weight: 900 !important;
        font-style: normal !important;
        speak: none;
        display: inline-block !important;
        line-height: 1;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    .result-info-style-row {
        display: flex;
        gap: 16px;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .result-info-style-opacity {
        flex: 0 0 180px;
    }
    .result-info-style-action {
        flex: 0 0 auto;
    }
    .result-info-image-controls .form-control {
        min-width: 0;
    }
    .result-info-preview-row {
        margin-top: 24px;
    }
    .result-info-preview {
        padding: 12px 14px;
        border-radius: 10px;
        background: #000000;
        border: 1px solid rgba(255, 255, 255, 0.14);
        display: flex;
        align-items: flex-start;
        gap: 10px;
        max-width: 520px;
    }
    .result-info-preview-text {
        flex: 1 1 auto;
        min-width: 0;
        font-size: 0.92rem;
        line-height: 1.55;
        white-space: normal;
        overflow: visible;
        text-overflow: initial;
        color: #f5f8ff;
    }
    .result-info-preview-text::after {
        content: "";
        display: block;
        clear: both;
    }
    .result-info-preview-text .float-left,
    .result-info-preview-text .note-float-left {
        float: left;
        margin: 0.2rem 0.85rem 0.55rem 0;
    }
    .result-info-preview-text .float-right,
    .result-info-preview-text .note-float-right {
        float: right;
        margin: 0.2rem 0 0.55rem 0.85rem;
    }
    .result-info-preview-text .text-left {
        text-align: left;
    }
    .result-info-preview-text .text-center {
        text-align: center;
    }
    .result-info-preview-text .text-right {
        text-align: right;
    }
    .result-info-preview-text .text-justify {
        text-align: justify;
    }
    .result-info-item-list {
        display: grid;
        gap: 14px;
        margin-top: 22px;
    }
    .result-info-item-card {
        border: 1px solid rgba(27, 63, 114, 0.12);
        border-radius: 12px;
        padding: 14px;
        background: #fff;
        transition: all 0.2s ease;
    }
    .result-info-item-card:hover {
        border-color: rgba(27, 63, 114, 0.24);
        box-shadow: 0 2px 8px rgba(27, 63, 114, 0.1);
    }
    .result-info-item-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .result-info-item-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1b3f72;
        margin: 0;
    }
    .result-info-item-actions {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .result-info-item-actions .btn-sm {
        padding: 0.375rem 0.5rem;
        font-size: 0.8rem;
    }
    .result-info-item-actions .btn-sm i {
        margin-right: 0.25rem;
    }
    .result-info-reorder-section {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 16px;
        margin-top: 20px;
    }
    .result-info-reorder-title {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1b3f72;
        margin-bottom: 12px;
    }
    .result-info-reorder-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .result-info-reorder-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        background: white;
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }
    .result-info-reorder-item-drag {
        cursor: grab;
        color: #6c757d;
        font-size: 1.2rem;
        flex: 0 0 auto;
    }
    .result-info-reorder-item-drag:active {
        cursor: grabbing;
    }
    .result-info-reorder-item-text {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .result-info-reorder-item-text small {
        display: block;
        font-size: 0.8rem;
        color: #6c757d;
        margin-top: 2px;
    }
    .result-info-reorder-hidden-input {
        display: none;
    }
    .sortable-placeholder {
        opacity: 0.5;
        background-color: #e7f3ff;
        border: 2px dashed #2b6cb0;
    }
    @media (max-width: 768px) {
        .result-info-settings-scroll {
            max-height: calc(100vh - 170px);
        }
    }
    @media (max-width: 480px) {
        .result-info-settings-scroll {
            max-height: calc(100vh - 182px);
        }
        .result-info-style-row,
        .result-info-item-head {
            flex-direction: column;
            align-items: stretch;
        }
        .result-info-style-action {
            flex: 1 1 100%;
        }
        .result-info-item-actions {
            width: 100%;
        }
        .result-info-item-actions .btn-sm {
            flex: 1;
        }
    }
</style>
<div class="result-info-settings-scroll">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Informasi Tambahan Hasil Pengumuman</h3>
        </div>
        <div class="card-body">
            <?php 
            // Check if there are broken image paths that need fixing
            $hasBrokenPaths = false;
            foreach ($resultInfoItems as $item) {
                if (strpos($item['text'] ?? '', '/web_kelulusan/') !== false) {
                    $hasBrokenPaths = true;
                    break;
                }
            }
            if ($hasBrokenPaths) {
                echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">';
                echo '<i class="fas fa-exclamation-triangle"></i> <strong>Perhatian!</strong> Ditemukan gambar dengan path yang tidak valid.';
                echo '<br><small>Silakan klik tombol di bawah untuk memperbaiki path gambar secara otomatis.</small>';
                echo '<br><br><a href="fix_result_info_images.php" class="btn btn-warning btn-sm"><i class="fas fa-tools"></i> Perbaiki Path Gambar</a>';
                echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                echo '</div>';
            }
            ?>
            <form method="post">
                <input type="hidden" name="action" value="add_result_info_item">
                <div class="form-group">
                    <label for="result_info_note">Teks informasi di bawah kartu hasil</label>
                    <textarea id="result_info_note" name="result_info_note" class="form-control" rows="4"></textarea>
                </div>
                <div class="form-group result-info-image-controls">
                    <label>Ubah ukuran gambar</label>
                    <div class="d-flex flex-wrap gap-2 align-items-end">
                        <div class="flex-grow-1" style="min-width: 180px; max-width: 240px;">
                            <label class="form-label" for="editor_image_width">Lebar</label>
                            <input type="text" id="editor_image_width" class="form-control" placeholder="Misal 50% / 200px / 150" disabled>
                        </div>
                        <div class="flex-grow-1" style="min-width: 180px; max-width: 240px;">
                            <label class="form-label" for="editor_image_height">Tinggi</label>
                            <input type="text" id="editor_image_height" class="form-control" placeholder="Misal auto / 150px / 120" disabled>
                        </div>
                        <button type="button" id="editor_image_apply" class="btn btn-secondary" disabled>Terapkan</button>
                        <button type="button" id="editor_image_reset" class="btn btn-outline-secondary" disabled>Reset</button>
                    </div>
                    <small class="form-text text-muted">Klik gambar di editor untuk memilih. Masukkan angka saja untuk px, atau gunakan % / px / auto.</small>
                </div>
                <div class="result-info-style-row">
                    <div class="form-group result-info-style-opacity">
                        <label for="result_info_note_opacity">Opacity teks (0 - 1)</label>
                        <input type="number" id="result_info_note_opacity" name="result_info_note_opacity" class="form-control" min="0" max="1" step="0.05" value="<?php echo htmlspecialchars($resultInfoOpacity); ?>">
                    </div>
                    <div class="form-group result-info-style-action">
                        <button type="submit" class="btn btn-primary">Tambah Informasi</button>
                    </div>
                </div>
                <div class="result-info-preview-row">
                    <div class="result-info-preview">
                        <div id="resultInfoEditorPreviewText" class="result-info-preview-text" style="opacity: <?php echo htmlspecialchars($resultInfoOpacity); ?>;">
                            Teks sample informasi tambahan hasil pengumuman akan tampil seperti ini.
                        </div>
                    </div>
                </div>
            </form>

            <?php if (!empty($resultInfoItems)): ?>
                <div class="result-info-item-list">
                    <?php foreach ($resultInfoItems as $index => $item): ?>
                        <div class="result-info-item-card">
                            <div class="result-info-item-head">
                                <h5 class="result-info-item-title">Informasi <?php echo (int)($index + 1); ?></h5>
                                <div class="result-info-item-actions">
                                    <?php if ($index > 0): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="reorder_result_info_items">
                                            <?php foreach ($resultInfoItems as $i => $itm): ?>
                                                <input type="hidden" name="item_order[]" value="<?php echo $i === $index - 1 ? $index : ($i === $index ? $index - 1 : $i); ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-info btn-sm" title="Pindah ke atas">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled title="Sudah di posisi teratas">
                                            <i class="fas fa-arrow-up"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($index < count($resultInfoItems) - 1): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="reorder_result_info_items">
                                            <?php foreach ($resultInfoItems as $i => $itm): ?>
                                                <input type="hidden" name="item_order[]" value="<?php echo $i === $index + 1 ? $index : ($i === $index ? $index + 1 : $i); ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-info btn-sm" title="Pindah ke bawah">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled title="Sudah di posisi terbawah">
                                            <i class="fas fa-arrow-down"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_result_info_item">
                                        <input type="hidden" name="item_index" value="<?php echo (int)$index; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus item">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="result-info-preview" style="margin-top:0;">
                                <div class="result-info-preview-text" style="opacity: <?php echo htmlspecialchars($item['opacity'] ?? '1'); ?>; white-space: normal; overflow: visible; text-overflow: initial;">
                                    <?php echo $item['text']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php
admin_render_page_end($summernoteScript);
