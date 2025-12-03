<?php
$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 4);
$componentsPath = $rootDir . '/engine/interface/admin-ui/components/';
$cacheInfo = $cacheInfo ?? [];
$items = $cacheInfo['items'] ?? [];
$stats = $cacheInfo['stats'] ?? [
    'total_size_mb' => 0,
    'total_size_kb' => 0,
    'oldest_item' => null,
    'newest_item' => null,
    'expired_count' => 0,
    'active_count' => 0,
    'unknown_count' => 0,
];
$totalFiles = isset($cacheInfo['total_files']) ? (int)$cacheInfo['total_files'] : 0;
$totalSize = isset($cacheInfo['total_size']) ? (int)$cacheInfo['total_size'] : 0;

/**
 * Форматування HTML для індикатора активності
 * 
 * @param string $level Рівень активності: 'high', 'medium', 'low'
 * @param float $score Оцінка активності (0-100)
 * @param int $accessCount Кількість звернень
 * @param int|null $lastAccess Timestamp останнього звернення
 * @return string HTML код
 */
function formatActivityHtml(string $level, float $score, int $accessCount = 0, ?int $lastAccess = null): string
{
    $colors = [
        'high' => ['bg' => '#dc3545', 'label' => 'Часто', 'icon' => '🔴'],
        'medium' => ['bg' => '#ffc107', 'label' => 'Середньо', 'icon' => '🟡'],
        'low' => ['bg' => '#28a745', 'label' => 'Рідко', 'icon' => '🟢']
    ];
    
    $color = $colors[$level] ?? $colors['low'];
    $roundedScore = round($score, 1);
    
    // Формуємо інформативний текст
    $infoParts = [];
    
    if ($accessCount > 0) {
        $infoParts[] = $accessCount . ' ' . formatAccessCount($accessCount);
    } else {
        $infoParts[] = 'Немає статистики';
    }
    
    if ($lastAccess !== null) {
        $timeAgo = formatTimeAgo(time() - $lastAccess);
        $infoParts[] = 'останнє: ' . $timeAgo;
    }
    
    $infoText = implode(' | ', $infoParts);
    $tooltip = 'Активність: ' . htmlspecialchars($color['label']) . ' (' . $roundedScore . '%)';
    if (!empty($infoText)) {
        $tooltip .= ' | ' . htmlspecialchars($infoText);
    }
    
    // Перший рядок: Точка + Статус
    $statusRow = '<div class="cache-activity-status-row">';
    $statusRow .= '<span class="cache-activity-indicator" style="background-color: ' . htmlspecialchars($color['bg']) . ';" ';
    $statusRow .= 'title="' . htmlspecialchars($tooltip) . '"></span>';
    $statusRow .= '<span class="cache-activity-label">' . htmlspecialchars($color['label']) . '</span>';
    $statusRow .= '</div>';
    
    // Другий рядок: Інформація
    $infoRow = '';
    if (!empty($infoText)) {
        $infoRow = '<div class="cache-activity-info-row">';
        $infoRow .= '<span class="cache-activity-info text-muted">' . htmlspecialchars($infoText) . '</span>';
        $infoRow .= '</div>';
    }
    
    $html = '<div class="cache-activity-wrapper">' . $statusRow . $infoRow . '</div>';
    
    return $html;
}

/**
 * Форматування кількості звернень
 */
function formatAccessCount(int $count): string
{
    if ($count === 1) {
        return 'звернення';
    } elseif ($count >= 2 && $count <= 4) {
        return 'звернення';
    }
    return 'звернень';
}

/**
 * Форматування часу тому
 */
function formatTimeAgo(int $seconds): string
{
    if ($seconds < 60) {
        return 'щойно';
    } elseif ($seconds < 3600) {
        $minutes = (int)($seconds / 60);
        return $minutes . ' ' . ($minutes === 1 ? 'хвилину' : ($minutes < 5 ? 'хвилини' : 'хвилин')) . ' тому';
    } elseif ($seconds < 86400) {
        $hours = (int)($seconds / 3600);
        return $hours . ' ' . ($hours === 1 ? 'годину' : ($hours < 5 ? 'години' : 'годин')) . ' тому';
    } else {
        $days = (int)($seconds / 86400);
        return $days . ' ' . ($days === 1 ? 'день' : ($days < 5 ? 'дні' : 'днів')) . ' тому';
    }
}

// Показуємо кастомне уведомлення замість стандартного alert
if (!empty($message)) {
    $type = $messageType ?? 'info';
    $messageJson = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $typeJson = json_encode($type, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    // Використовуємо JavaScript для кастомного уведомлення, яке випливає під шапкою
    echo '<script>';
    echo '(function() {';
    echo '    function showCustomNotification() {';
    echo '        if (typeof window.showNotification !== "undefined") {';
    echo '            window.showNotification(' . $messageJson . ', ' . $typeJson . ');';
    echo '        } else if (typeof window.Notifications !== "undefined" && typeof window.Notifications.show === "function") {';
    echo '            window.Notifications.show(' . $messageJson . ', ' . $typeJson . ');';
    echo '        } else {';
    echo '            setTimeout(showCustomNotification, 100);';
    echo '        }';
    echo '    }';
    echo '    if (document.readyState === "loading") {';
    echo '        document.addEventListener("DOMContentLoaded", showCustomNotification);';
    echo '    } else {';
    echo '        setTimeout(showCustomNotification, 100);';
    echo '    }';
    echo '})();';
    echo '</script>';
}
?>
<div class="cache-view-page">
    <div class="cache-stats-section">
        <?php
        // Забезпечуємо, що всі значення ініціалізовані навіть якщо кеш порожній
        // Використовуємо значення з початку файлу, якщо вони встановлені
        $totalFiles = isset($totalFiles) ? (int)$totalFiles : 0;
        $totalSizeMb = isset($stats['total_size_mb']) ? (float)$stats['total_size_mb'] : 0.0;
        $totalSizeKb = isset($stats['total_size_kb']) ? (float)$stats['total_size_kb'] : 0.0;
        $expiredCount = isset($stats['expired_count']) ? (int)$stats['expired_count'] : 0;
        
        // Форматуємо значення для відображення (завжди рядок, навіть для 0)
        $totalFilesFormatted = $totalFiles > 0 ? number_format($totalFiles, 0, ',', ' ') : '0';
        
        $cards = [
            [
                'title' => 'Всього файлів',
                'value' => $totalFilesFormatted,
                'icon' => 'file',
                'color' => 'primary'
            ],
            [
                'title' => 'Загальний розмір',
                'value' => $totalSizeMb >= 1 
                    ? number_format($totalSizeMb, 2, ',', ' ') . ' MB'
                    : number_format($totalSizeKb, 2, ',', ' ') . ' KB',
                'icon' => 'hdd',
                'color' => 'info'
            ],
            [
                'title' => 'Застарілі',
                'value' => $expiredCount > 0
                    ? '<span class="text-danger">' . $expiredCount . '</span>'
                    : '<span class="text-success">0</span>',
                'icon' => 'exclamation-circle',
                'color' => $expiredCount > 0 ? 'danger' : 'success',
                'valueClass' => 'h5'
            ]
        ];
        include $componentsPath . 'stats-cards.php';
        ?>
    </div>

    <div class="cache-elements-section" style="margin-top: 24px;">
        <div class="card border-0">
            <div class="card-body p-0">
                <?php
                // Підготовка заголовків з можливістю сортування
                $headers = [
                    ['text' => 'Ключ', 'icon' => 'key', 'width' => 'auto', 'sortable' => true, 'sortKey' => 'key'],
                    ['text' => 'Джерело', 'icon' => 'tag', 'width' => '140px', 'sortable' => true, 'sortKey' => 'source'],
                    ['text' => 'Активність', 'icon' => 'chart-line', 'width' => '200px', 'sortable' => true, 'sortKey' => 'activity'],
                    ['text' => 'Статус', 'icon' => 'info-circle', 'width' => '120px', 'sortable' => true, 'sortKey' => 'status'],
                    ['text' => 'Розмір', 'icon' => 'hdd', 'width' => '100px', 'sortable' => true, 'sortKey' => 'size'],
                    ['text' => 'Оновлено', 'icon' => 'clock', 'width' => '150px', 'sortable' => true, 'sortKey' => 'modified'],
                    ['text' => 'Дії', 'icon' => 'cog', 'class' => 'text-end', 'width' => '120px', 'sortable' => false]
                ];
                
                // Підготовка рядків
                $rows = [];
                foreach ($items as $item) {
                    // Формуємо HTML для джерела
                    $sourceType = $item['source'] ?? 'system';
                    $sourceLabel = $item['source_label'] ?? 'Системний';
                    $sourceIcon = $item['source_icon'] ?? 'cog';
                    $sourceColor = $item['source_color'] ?? 'primary';
                    $sourceDetails = $item['source_details'] ?? '';
                    
                    $sourceHtml = '<div class="d-flex align-items-center gap-2">';
                    $sourceHtml .= '<i class="fas fa-' . htmlspecialchars($sourceIcon) . ' text-' . htmlspecialchars($sourceColor) . '" style="font-size: 0.875rem;"></i>';
                    $sourceHtml .= '<div class="d-flex flex-column">';
                    $sourceHtml .= '<span class="fw-medium" style="font-size: 0.8125rem; line-height: 1.2;">' . htmlspecialchars($sourceLabel) . '</span>';
                    // Для системного кешу не показуємо details, якщо це просто "Системний кеш"
                    if (!empty($sourceDetails) && !($sourceType === 'system' && $sourceDetails === 'Системний кеш')) {
                        $sourceHtml .= '<small class="text-muted" style="font-size: 0.75rem; line-height: 1;">' . htmlspecialchars($sourceDetails) . '</small>';
                    }
                    $sourceHtml .= '</div>';
                    $sourceHtml .= '</div>';
                    
                    // Формуємо HTML для статусу
                    $isExpired = $item['is_expired'] ?? false;
                    $expiryStatus = $item['expiry_status'] ?? 'unknown';
                    $expiresIn = $item['expires_in'] ?? null;
                    
                    if ($expiryStatus === 'active') {
                        $statusHtml = '<div class="d-flex align-items-center gap-1 flex-wrap">';
                        $statusHtml .= '<span class="badge bg-success" style="font-size: 0.75rem; font-weight: 500;">
                                        <i class="fas fa-check-circle me-1"></i>Активний
                                      </span>';
                        if ($expiresIn) {
                            $statusHtml .= '<small class="text-muted" style="font-size: 0.75rem; white-space: nowrap;">' . htmlspecialchars($expiresIn) . '</small>';
                        }
                        $statusHtml .= '</div>';
                    } elseif ($expiryStatus === 'expired') {
                        $statusHtml = '<div class="d-flex align-items-center gap-1 flex-wrap">';
                        $statusHtml .= '<span class="badge bg-danger" style="font-size: 0.75rem; font-weight: 500;">
                                        <i class="fas fa-exclamation-circle me-1"></i>Застарілий
                                      </span>';
                        if ($expiresIn) {
                            $statusHtml .= '<small class="text-muted" style="font-size: 0.75rem; white-space: nowrap;">' . htmlspecialchars($expiresIn) . '</small>';
                        }
                        $statusHtml .= '</div>';
                    } else {
                        $statusHtml = '<span class="badge bg-secondary" style="font-size: 0.75rem; font-weight: 500;">
                                        <i class="fas fa-question-circle me-1"></i>Невідомо
                                      </span>';
                    }
                    
                    // Визначаємо значення для сортування статусу
                    $statusSortValue = 'unknown';
                    if ($expiryStatus === 'active') {
                        $statusSortValue = 'active';
                    } elseif ($expiryStatus === 'expired') {
                        $statusSortValue = 'expired';
                    }
                    
                    // Формуємо HTML для активності (heatmap)
                    $activityLevel = $item['activity_level'] ?? 'low';
                    $activityScore = $item['activity_score'] ?? 0.0;
                    $activityAccessCount = $item['activity_access_count'] ?? 0;
                    $activityLastAccess = $item['activity_last_access'] ?? null;
                    $activityHtml = formatActivityHtml($activityLevel, $activityScore, $activityAccessCount, $activityLastAccess);
                    
                    $rows[] = [
                        ['content' => $item['key'], 'type' => 'key', 'icon' => 'key', 'sort-value' => $item['key']],
                        ['content' => $sourceHtml, 'type' => 'html', 'sort-value' => $sourceLabel . ($sourceDetails ? ' ' . $sourceDetails : '')],
                        ['content' => $activityHtml, 'type' => 'html', 'sort-value' => $activityLevel . '-' . $activityScore],
                        ['content' => $statusHtml, 'type' => 'html', 'sort-value' => $statusSortValue],
                        ['content' => $item['size'], 'type' => 'size', 'sort-value' => $item['size']],
                        ['content' => $item['modified'], 'type' => 'date', 'sort-value' => $item['modified']],
                        [
                            'content' => '<div class="d-flex gap-1 justify-content-end">
                                         <button type="button" class="btn btn-sm btn-info view-cache-btn" 
                                         data-cache-key="' . htmlspecialchars($item['key']) . '"
                                         title="Переглянути">
                                         <i class="fas fa-eye"></i>
                                         </button>
                                         <button type="button" class="btn btn-sm btn-danger" 
                                         data-bs-toggle="modal" 
                                         data-bs-target="#clearCacheItemModal"
                                         data-cache-key="' . htmlspecialchars($item['key']) . '"
                                         title="Видалити">
                                         <i class="fas fa-trash"></i>
                                         </button>
                                         </div>',
                            'type' => 'html',
                            'class' => 'text-end'
                        ]
                    ];
                }
                
                // Конфігурація для мобільних карток
                // Показуємо всі колонки окрім останньої (дії) - включаємо джерело, активність, статус
                $mobileConfig = [
                    'keyColumn' => 0,
                    'showColumns' => [0, 1, 2, 3, 4, 5], // Ключ, Джерело, Активність, Статус, Розмір, Оновлено
                    'deleteButton' => [
                        'modal' => 'clearCacheItemModal',
                        'dataAttribute' => 'data-cache-key'
                    ],
                    'customActions' => true // Використовуємо кастомні дії
                ];
                
                // Повідомлення для порожнього стану
                $emptyMessage = 'Кеш порожній';
                $emptyIcon = 'database';
                
                include $componentsPath . 'data-table.php';
                ?>
            </div>
        </div>
    </div>

    <div class="cache-info-section">
        <?php
        $title = 'Про кеш системи';
        $titleIcon = 'info-circle';
        $sections = [
            [
                'title' => 'Що таке кеш:',
                'icon' => 'question-circle',
                'iconColor' => 'primary',
                'items' => [
                    'Зберігає результати обчислень та запитів до БД',
                    'Прискорює роботу системи шляхом зменшення навантаження',
                    'Автоматично оновлюється при зміні даних',
                    'Може бути очищений вручну або автоматично'
                ]
            ],
            [
                'title' => 'Коли очищати:',
                'icon' => 'clock',
                'iconColor' => 'info',
                'items' => [
                    'Після оновлення системи або плагінів',
                    'При проблемах з відображенням даних',
                    'Для звільнення місця на диску',
                    'Перед налаштуванням продуктивності'
                ]
            ]
        ];
        include $componentsPath . 'info-block.php';
        ?>
    </div>
</div>

<?php
// Модальне вікно для очищення всього кешу
$id = 'clearAllCacheModal';
$title = 'Очищення кешу';
$titleIcon = '<i class="fas fa-exclamation-triangle text-warning me-2"></i>';
$content = '<div class="cache-modal-content"><p class="mb-3">Ви впевнені, що хочете очистити весь кеш системи?</p><div class="alert alert-warning mb-0 py-2"><i class="fas fa-info-circle me-2"></i><small>Ця дія видалить всі файли кешу та не може бути скасована.</small></div></div>';
$footer = '<form method="POST" action="' . UrlHelper::admin('cache-view') . '" id="clearAllCacheForm" class="w-100" data-no-smooth-nav="true">' . SecurityHelper::csrfField() . '<input type="hidden" name="action" value="clear_all"><div class="d-flex flex-row gap-2 w-100"><button type="button" class="btn btn-secondary flex-fill" data-bs-dismiss="modal">Скасувати</button><button type="submit" class="btn btn-danger flex-fill"><i class="fas fa-trash me-2"></i>Очистити</button></div></form>';
$size = '';
$centered = true;
include $componentsPath . 'modal.php';

// Модальне вікно для перегляду вмісту кеш файлу
$id = 'viewCacheContentModal';
$title = 'Переглянути вміст кешу';
$titleIcon = '<i class="fas fa-code text-info me-2"></i>';
$content = '<div id="cacheContentLoading" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Завантаження...</span>
                </div>
                <p class="mt-2 text-muted">Завантаження вмісту...</p>
            </div>
            <div id="cacheContentError" class="alert alert-danger d-none"></div>
            <div id="cacheContentDisplay" class="d-none">
                <div class="mb-3">
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Ключ:</small>
                            <code id="cacheContentKey" class="d-block p-2 bg-light rounded"></code>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Розмір файлу:</small>
                            <span id="cacheContentSize" class="d-block p-2 bg-light rounded"></span>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Шлях:</small>
                            <code id="cacheContentPath" class="d-block p-2 bg-light rounded small"></code>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Оновлено:</small>
                            <span id="cacheContentModified" class="d-block p-2 bg-light rounded"></span>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <ul class="nav nav-tabs" id="cacheContentTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="json-tab" data-bs-toggle="tab" data-bs-target="#json-content" type="button" role="tab">
                                <i class="fas fa-code me-1"></i>JSON
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="php-tab" data-bs-toggle="tab" data-bs-target="#php-content" type="button" role="tab">
                                <i class="fas fa-code me-1"></i>PHP Array
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="raw-tab" data-bs-toggle="tab" data-bs-target="#raw-content" type="button" role="tab">
                                <i class="fas fa-file-alt me-1"></i>Raw
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-3" style="max-height: 500px; overflow-y: auto; background: #f8f9fa;">
                        <div class="tab-pane fade show active" id="json-content" role="tabpanel">
                            <pre id="cacheContentJson" class="mb-0" style="background: transparent; border: none; padding: 0; font-size: 0.875rem; white-space: pre-wrap; word-wrap: break-word;"><code class="language-json"></code></pre>
                        </div>
                        <div class="tab-pane fade" id="php-content" role="tabpanel">
                            <pre id="cacheContentPhp" class="mb-0" style="background: transparent; border: none; padding: 0; font-size: 0.875rem; white-space: pre-wrap; word-wrap: break-word;"><code class="language-php"></code></pre>
                        </div>
                        <div class="tab-pane fade" id="raw-content" role="tabpanel">
                            <pre id="cacheContentRaw" class="mb-0" style="background: transparent; border: none; padding: 0; font-size: 0.875rem; white-space: pre-wrap; word-wrap: break-word;"><code></code></pre>
                        </div>
                    </div>
                </div>
            </div>';
$footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>';
$size = 'xl';
$centered = true;
include $componentsPath . 'modal.php';

// Модальне вікно для видалення окремого елемента кешу
$id = 'clearCacheItemModal';
$title = 'Видалення елемента кешу';
$titleIcon = '<i class="fas fa-trash text-danger me-2"></i>';
$content = '<div class="cache-modal-content"><p class="mb-3">Ви впевнені, що хочете видалити цей елемент кешу?</p><div class="cache-item-preview"><small class="text-muted d-block mb-1">Ключ:</small><code class="cache-modal-key" id="cacheItemKey"></code></div></div>';
$footer = '<form method="POST" action="' . UrlHelper::admin('cache-view') . '" id="clearCacheItemForm" class="w-100" data-no-smooth-nav="true">' . SecurityHelper::csrfField() . '<input type="hidden" name="action" value="clear_item"><input type="hidden" name="key" id="cacheItemKeyInput" value=""><div class="d-flex flex-row gap-2 w-100"><button type="button" class="btn btn-secondary flex-fill" data-bs-dismiss="modal">Скасувати</button><button type="submit" class="btn btn-danger flex-fill"><i class="fas fa-trash me-2"></i>Видалити</button></div></form>';
$size = '';
$centered = true;
include $componentsPath . 'modal.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обробка модального вікна видалення
    const deleteModal = document.getElementById('clearCacheItemModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(e) {
            const key = e.relatedTarget?.getAttribute('data-cache-key') || '';
            const keyInput = document.getElementById('cacheItemKeyInput');
            const keyDisplay = document.getElementById('cacheItemKey');
            if (keyInput) keyInput.value = key;
            if (keyDisplay) keyDisplay.textContent = key;
        });
    }

    // Обробка кнопок перегляду вмісту кешу
    const viewButtons = document.querySelectorAll('.view-cache-btn');
    const viewModalElement = document.getElementById('viewCacheContentModal');
    if (!viewModalElement) return;
    
    const viewModal = new bootstrap.Modal(viewModalElement);
    
    // Функція форматування розміру файлу
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            const cacheKey = this.getAttribute('data-cache-key');
            if (!cacheKey) return;
            
            // Скидаємо стан модального вікна
            document.getElementById('cacheContentLoading').classList.remove('d-none');
            document.getElementById('cacheContentError').classList.add('d-none');
            document.getElementById('cacheContentDisplay').classList.add('d-none');
            
            // Показуємо модальне вікно
            viewModal.show();
            
            // Завантажуємо вміст через AJAX
            fetch('<?= UrlHelper::admin('cache-view') ?>?action=view_cache_content&key=' + encodeURIComponent(cacheKey), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('cacheContentLoading').classList.add('d-none');
                
                if (data.success && data.data) {
                    const content = data.data;
                    
                    // Заповнюємо основну інформацію
                    document.getElementById('cacheContentKey').textContent = content.key || '';
                    document.getElementById('cacheContentSize').textContent = formatFileSize(content.file_size || 0);
                    document.getElementById('cacheContentPath').textContent = content.file_path || '';
                    document.getElementById('cacheContentModified').textContent = content.modified || '';
                    
                    // Заповнюємо вміст залежно від типу
                    if (content.is_serialized && content.data_json) {
                        // JSON вміст
                        document.getElementById('cacheContentJson').querySelector('code').textContent = content.data_json;
                        
                        // PHP Array вміст
                        document.getElementById('cacheContentPhp').querySelector('code').textContent = content.data_var_export || 'N/A';
                        
                        // Raw вміст (серіалізований) - показуємо JSON
                        document.getElementById('cacheContentRaw').querySelector('code').textContent = content.data_json || JSON.stringify(content.data, null, 2);
                        
                        // Показуємо всі таби
                        document.getElementById('json-tab').style.display = '';
                        document.getElementById('php-tab').style.display = '';
                        document.getElementById('raw-tab').style.display = '';
                        
                        // Активація JSON таба
                        document.getElementById('json-content').classList.add('show', 'active');
                        document.getElementById('php-content').classList.remove('show', 'active');
                        document.getElementById('raw-content').classList.remove('show', 'active');
                        document.getElementById('json-tab').classList.add('active');
                        document.getElementById('php-tab').classList.remove('active');
                        document.getElementById('raw-tab').classList.remove('active');
                    } else if (content.raw_content) {
                        // Raw текстовий вміст
                        document.getElementById('cacheContentJson').querySelector('code').textContent = 'N/A (не серіалізований вміст)';
                        document.getElementById('cacheContentPhp').querySelector('code').textContent = 'N/A (не серіалізований вміст)';
                        document.getElementById('cacheContentRaw').querySelector('code').textContent = content.raw_content;
                        
                        // Ховаємо JSON та PHP таби
                        document.getElementById('json-tab').style.display = 'none';
                        document.getElementById('php-tab').style.display = 'none';
                        document.getElementById('raw-tab').style.display = '';
                        
                        // Активація Raw таба
                        document.getElementById('json-content').classList.remove('show', 'active');
                        document.getElementById('php-content').classList.remove('show', 'active');
                        document.getElementById('raw-content').classList.add('show', 'active');
                        document.getElementById('json-tab').classList.remove('active');
                        document.getElementById('php-tab').classList.remove('active');
                        document.getElementById('raw-tab').classList.add('active');
                    }
                    
                    document.getElementById('cacheContentDisplay').classList.remove('d-none');
                } else {
                    document.getElementById('cacheContentError').textContent = data.error || 'Помилка завантаження вмісту';
                    document.getElementById('cacheContentError').classList.remove('d-none');
                }
            })
            .catch(error => {
                document.getElementById('cacheContentLoading').classList.add('d-none');
                document.getElementById('cacheContentError').textContent = 'Помилка завантаження: ' + error.message;
                document.getElementById('cacheContentError').classList.remove('d-none');
            });
        });
    });
});
</script>
