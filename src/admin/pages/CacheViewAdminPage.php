<?php

/**
 * Сторінка перегляду та управління кешем
 */

declare(strict_types=1);

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 5);
$engineDir = $rootDir . '/engine';

require_once $engineDir . '/interface/admin-ui/includes/AdminPage.php';
require_once dirname(__DIR__, 2) . '/Services/CacheStatsTracker.php';

class CacheViewAdminPage extends AdminPage
{
    private string $pluginDir;

    public function __construct()
    {
        parent::__construct();

        $this->pluginDir = dirname(__DIR__, 3);

        // Перевірка прав доступу
        if (! function_exists('current_user_can') || ! current_user_can('admin.cache.view')) {
            Response::redirectStatic(UrlHelper::admin('dashboard'));
            exit;
        }

        $this->pageTitle = 'Управління кешем - Flowaxy CMS';
        $this->templateName = 'cache-view';

        // Отримуємо інформацію про кеш для визначення стану кнопки
        $cacheInfo = $this->getCacheInfo();
        $totalFiles = $cacheInfo['total_files'] ?? 0;

        // Формуємо кнопку очищення кешу (неактивна, якщо кеш порожній)
        $clearCacheButton = $this->createClearCacheButton($totalFiles > 0);

        $this->setPageHeader(
            'Управління кешем',
            'Перегляд та очищення кешу системи',
            'fas fa-database',
            $clearCacheButton
        );

        $this->setBreadcrumbs([
            ['title' => 'Головна', 'url' => UrlHelper::admin('dashboard')],
            ['title' => 'Кеш'],
        ]);

        // Підключаємо CSS
        $this->additionalCSS[] = $this->pluginAsset('styles/cache-view.css');
    }

    protected function getTemplatePath()
    {
        return $this->pluginDir . '/templates/';
    }

    private function pluginAsset(string $path): string
    {
        $relativePath = 'plugins/cache-view/assets/' . ltrim($path, '/');
        $absolutePath = $this->pluginDir . '/assets/' . ltrim($path, '/');
        $version = file_exists($absolutePath) ? substr(md5_file($absolutePath), 0, 8) : substr((string)time(), -8);
        return UrlHelper::base($relativePath) . '?v=' . $version;
    }

    /**
     * Створення кнопки очищення кешу
     *
     * @param bool $enabled Чи активна кнопка (true якщо є файли кешу)
     * @return string HTML кнопки
     */
    private function createClearCacheButton(bool $enabled = true): string
    {
        $disabled = $enabled ? '' : 'disabled';
        $disabledClass = !$enabled ? ' opacity-50' : '';
        $disabledAttr = !$enabled ? ' tabindex="-1" aria-disabled="true"' : '';
        
        return '<button type="button" class="btn btn-danger' . $disabledClass . '" ' . 
               'data-bs-toggle="modal" data-bs-target="#clearAllCacheModal" ' . 
               $disabled . $disabledAttr . '>' .
               '<i class="fas fa-trash"></i><span class="btn-text">Очистити весь кеш</span>' .
               '</button>';
    }

    public function handle(): void
    {
        // Обробка AJAX запиту для перегляду вмісту кеш файлу
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'view_cache_content') {
            header('Content-Type: application/json');
            echo json_encode($this->getCacheContent());
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            match ($_POST['action']) {
                'clear_all' => $this->clearAllCache(),
                'clear_item' => $this->clearCacheItem(),
                default => null,
            };
            // Якщо досягнуто цього місця - обробка не вдалася, рендеримо сторінку з повідомленням
            // Успішні операції виконують redirect() та exit()
        }
        $this->render(['cacheInfo' => $this->getCacheInfo()]);
    }

    private function getCacheInfo(): array
    {
        $info = ['total_size' => 0, 'total_files' => 0, 'items' => [], 'stats' => []];
        $cacheDir = defined('CACHE_DIR') ? CACHE_DIR : (defined('ROOT_DIR') ? ROOT_DIR . '/storage/cache' : dirname(__DIR__, 5) . '/storage/cache');
        
        // Отримуємо налаштування кешу з БД
        $cacheSettings = $this->getCacheSettings();
        $cacheEnabled = ($cacheSettings['cache_enabled'] ?? '1') === '1';
        $cacheDefaultTtl = (int)($cacheSettings['cache_default_ttl'] ?? '3600');
        $currentTime = time();
        
        try {
            if (!is_dir($cacheDir)) return $info;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && pathinfo($file->getFilename(), PATHINFO_EXTENSION) === 'cache') {
                    $fileSize = $file->getSize();
                    $info['total_size'] += $fileSize;
                    $info['total_files']++;
                    $relativePath = str_replace($cacheDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $key = basename($relativePath, '.cache');
                    
                    // Визначаємо джерело кешу
                    $source = $this->detectCacheSource($file->getPathname(), $key);
                    
                    // Перевіряємо, чи кеш застарілий
                    $expiryInfo = $this->checkCacheExpiry($file->getPathname(), $currentTime, $cacheDefaultTtl);
                    
                    // Отримуємо статистику активності
                    $activityStats = $this->getActivityStats($key);
                    
                    $info['items'][] = [
                        'key' => $key,
                        'path' => $relativePath,
                        'size' => $fileSize,
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                        'modified_timestamp' => $file->getMTime(),
                        'source' => $source['type'],
                        'source_label' => $source['label'],
                        'source_icon' => $source['icon'],
                        'source_color' => $source['color'],
                        'source_details' => $source['details'],
                        'is_expired' => $expiryInfo['is_expired'],
                        'expires_at' => $expiryInfo['expires_at'],
                        'expires_in' => $expiryInfo['expires_in'],
                        'expiry_status' => $expiryInfo['status'], // 'active' - активний, 'expired' - застарілий, 'unknown' - невідомо
                        'activity_level' => $activityStats['level'],
                        'activity_score' => $activityStats['score'],
                        'activity_access_count' => $activityStats['access_count'] ?? 0,
                        'activity_last_access' => $activityStats['last_access'] ?? null,
                    ];
                }
            }

            usort($info['items'], fn($a, $b) => $b['modified_timestamp'] - $a['modified_timestamp']);

            // Підрахунок статистики за статусом
            $expiredCount = 0;
            $activeCount = 0;
            $unknownCount = 0;
            foreach ($info['items'] as $item) {
                $status = $item['expiry_status'] ?? 'unknown';
                if ($status === 'expired') {
                    $expiredCount++;
                } elseif ($status === 'active') {
                    $activeCount++;
                } else {
                    $unknownCount++;
                }
            }

            // Ініціалізуємо статистику (навіть якщо кеш порожній)
            $totalSize = $info['total_size'] ?? 0;
            $info['stats'] = [
                'total_size_mb' => $totalSize > 0 ? round($totalSize / 1024 / 1024, 2) : 0,
                'total_size_kb' => $totalSize > 0 ? round($totalSize / 1024, 2) : 0,
                'oldest_item' => !empty($info['items']) ? end($info['items'])['modified'] : null,
                'newest_item' => !empty($info['items']) ? $info['items'][0]['modified'] : null,
                'expired_count' => $expiredCount,
                'active_count' => $activeCount,
                'unknown_count' => $unknownCount,
            ];
        } catch (Exception $e) {
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage getCacheInfo error: ' . $e->getMessage());
            }
        }

        return $info;
    }

    /**
     * Визначення джерела кешу (плагін, тема або системний)
     * Аналізує вміст файлу кешу для визначення типу
     *
     * @param string $filePath Повний шлях до файлу кешу
     * @param string $key Ключ кешу
     * @return array Інформація про джерело: ['type' => '...', 'label' => '...', 'icon' => '...', 'color' => '...', 'details' => '...']
     */
    private function detectCacheSource(string $filePath, string $key): array
    {
        // Спочатку перевіряємо за ключем (якщо ключ має префікс)
        if (strpos($key, 'plugin_data_') === 0) {
            $slug = substr($key, 12);
            return [
                'type' => 'plugin',
                'label' => 'Плагін',
                'icon' => 'puzzle-piece',
                'color' => 'info',
                'details' => $this->getPluginName($slug, $filePath) ?: $slug,
            ];
        }
        
        if (strpos($key, 'plugin_settings_') === 0) {
            $slug = substr($key, 16);
            return [
                'type' => 'plugin',
                'label' => 'Плагін',
                'icon' => 'puzzle-piece',
                'color' => 'info',
                'details' => $this->getPluginName($slug, $filePath) ?: $slug,
            ];
        }
        
        if (strpos($key, 'theme_') === 0 || strpos($key, 'active_theme') === 0 || strpos($key, 'all_themes') === 0) {
            $themeSlug = $this->getThemeSlugFromKey($key);
            return [
                'type' => 'theme',
                'label' => 'Тема',
                'icon' => 'palette',
                'color' => 'warning',
                'details' => $this->getThemeName($themeSlug, $filePath) ?: ($themeSlug ?: 'Загальний'),
            ];
        }
        
        // Якщо ключ - це хеш, аналізуємо вміст файлу
        try {
            if (file_exists($filePath) && is_readable($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if ($data !== false && is_array($data)) {
                        // Перевіряємо структуру даних
                        if (isset($data['data']) && is_array($data['data'])) {
                            $innerData = $data['data'];
                            
                            // Перевіряємо, чи це масив (all_themes, список плагінів)
                            if (isset($innerData[0]) && is_array($innerData[0])) {
                                $firstItem = $innerData[0];
                                
                                // Перевіряємо, чи це теми
                                if (isset($firstItem['slug']) && isset($firstItem['name']) && (isset($firstItem['is_default']) || isset($firstItem['supports_customization']))) {
                                    if (count($innerData) === 1) {
                                        // Одна тема
                                        return [
                                            'type' => 'theme',
                                            'label' => 'Тема',
                                            'icon' => 'palette',
                                            'color' => 'warning',
                                            'details' => $firstItem['name'] ?? $firstItem['slug'],
                                        ];
                                    } else {
                                        // Кілька тем
                                        return [
                                            'type' => 'theme',
                                            'label' => 'Тема',
                                            'icon' => 'palette',
                                            'color' => 'warning',
                                            'details' => 'Список тем (' . count($innerData) . ')',
                                        ];
                                    }
                                }
                                
                                // Може бути список плагінів (хоча зазвичай один)
                                if (isset($firstItem['slug']) && isset($firstItem['name']) && (isset($firstItem['installed_at']) || isset($firstItem['id']))) {
                                    if (count($innerData) === 1) {
                                        return [
                                            'type' => 'plugin',
                                            'label' => 'Плагін',
                                            'icon' => 'puzzle-piece',
                                            'color' => 'info',
                                            'details' => $firstItem['name'] ?? $firstItem['slug'],
                                        ];
                                    }
                                }
                            }
                            
                            // Перевіряємо, чи це один об'єкт плагіна
                            if (isset($innerData['slug']) && isset($innerData['name'])) {
                                // Плагіни мають поля: id, installed_at (або обидва)
                                // Теми мають: is_default або supports_customization
                                if (isset($innerData['installed_at']) || (isset($innerData['id']) && !isset($innerData['is_default']) && !isset($innerData['supports_customization']))) {
                                    $slug = $innerData['slug'];
                                    return [
                                        'type' => 'plugin',
                                        'label' => 'Плагін',
                                        'icon' => 'puzzle-piece',
                                        'color' => 'info',
                                        'details' => $innerData['name'] ?? $slug,
                                    ];
                                }
                                
                                // Перевіряємо, чи це тема
                                if (isset($innerData['is_default']) || isset($innerData['supports_customization'])) {
                                    return [
                                        'type' => 'theme',
                                        'label' => 'Тема',
                                        'icon' => 'palette',
                                        'color' => 'warning',
                                        'details' => $innerData['name'] ?? $innerData['slug'],
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Ігноруємо помилки парсингу
        }
        
        // Системний кеш (site_settings, admin_menu_items, тощо)
        return [
            'type' => 'system',
            'label' => 'Системний',
            'icon' => 'cog',
            'color' => 'primary',
            'details' => $this->getSystemCacheLabel($key),
        ];
    }

    /**
     * Отримання назви плагіна з кешу або по slug
     */
    private function getPluginName(string $slug, string $filePath): ?string
    {
        try {
            // Спробуємо прочитати дані з файлу кешу
            if (file_exists($filePath) && is_readable($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if ($data !== false && is_array($data)) {
                        // Шукаємо дані плагіна
                        if (isset($data['data']) && is_array($data['data'])) {
                            if (isset($data['data']['name'])) {
                                return $data['data']['name'];
                            }
                            if (isset($data['data']['slug']) && $data['data']['slug'] === $slug) {
                                return $data['data']['name'] ?? $slug;
                            }
                        }
                        // Якщо це масив з елементами
                        if (isset($data[0]) && is_array($data[0])) {
                            foreach ($data as $item) {
                                if (is_array($item) && isset($item['slug']) && $item['slug'] === $slug) {
                                    return $item['name'] ?? $slug;
                                }
                            }
                        }
                    }
                }
            }
            
            // Якщо не вдалося отримати з кешу, спробуємо через PluginManager
            if (class_exists('PluginManager') && function_exists('pluginManager')) {
                try {
                    $pluginManager = pluginManager();
                    $plugin = $pluginManager->getPlugin($slug);
                    if ($plugin && method_exists($plugin, 'getName')) {
                        return $plugin->getName();
                    }
                } catch (Exception $e) {
                    // Ігноруємо помилки
                }
            }
        } catch (Exception $e) {
            // Ігноруємо помилки парсингу
        }
        
        return null;
    }

    /**
     * Отримання slug теми з ключа кешу
     */
    private function getThemeSlugFromKey(string $key): ?string
    {
        // theme_settings_{slug}
        if (preg_match('/^theme_settings_(.+)$/', $key, $matches)) {
            return $matches[1];
        }
        
        // theme_config_{slug}
        if (preg_match('/^theme_config_(.+)$/', $key, $matches)) {
            return $matches[1];
        }
        
        // theme_{slug}
        if (preg_match('/^theme_(.+)$/', $key, $matches)) {
            return $matches[1];
        }
        
        // active_theme_check_{md5} - не можемо визначити slug
        // active_theme, active_theme_slug - загальні ключі
        // all_themes_* - загальні ключі
        
        return null;
    }

    /**
     * Отримання назви теми з кешу або по slug
     */
    private function getThemeName(?string $slug, string $filePath): ?string
    {
        if (empty($slug)) {
            return null;
        }
        
        try {
            // Спробуємо прочитати дані з файлу кешу
            if (file_exists($filePath) && is_readable($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if ($data !== false && is_array($data)) {
                        // Шукаємо дані теми
                        if (isset($data['data']) && is_array($data['data'])) {
                            // Якщо це один об'єкт теми
                            if (isset($data['data']['name'])) {
                                return $data['data']['name'];
                            }
                            // Якщо це масив тем
                            if (isset($data['data'][0]) && is_array($data['data'][0])) {
                                foreach ($data['data'] as $theme) {
                                    if (is_array($theme) && isset($theme['slug']) && $theme['slug'] === $slug) {
                                        return $theme['name'] ?? $slug;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Якщо не вдалося отримати з кешу, спробуємо через ThemeManager
            if (class_exists('ThemeManager') && function_exists('themeManager')) {
                try {
                    $themeManager = themeManager();
                    if (method_exists($themeManager, 'getTheme')) {
                        $theme = $themeManager->getTheme($slug);
                        if ($theme && isset($theme['name'])) {
                            return $theme['name'];
                        }
                    }
                } catch (Exception $e) {
                    // Ігноруємо помилки
                }
            }
        } catch (Exception $e) {
            // Ігноруємо помилки парсингу
        }
        
        return null;
    }

    /**
     * Отримання читабельної назви для системного кешу
     */
    private function getSystemCacheLabel(string $key): string
    {
        $labels = [
            'site_settings' => 'Налаштування сайту',
            'active_plugins_list' => 'Список активних плагінів',
            'active_plugins_hash' => 'Хеш плагінів',
            'admin_menu_items' => 'Меню адмінки',
        ];
        
        // Перевіряємо точний збіг
        if (isset($labels[$key])) {
            return $labels[$key];
        }
        
        // Перевіряємо по префіксу
        if (strpos($key, 'admin_menu_items_') === 0) {
            return 'Меню адмінки';
        }
        
        return 'Системний кеш';
    }

    private function clearAllCache(): void
    {
        if (!$this->verifyCsrf() || !$this->hasCacheAccess()) {
            return;
        }

        try {
            $cacheDir = $this->getCacheDir();
            if (is_dir($cacheDir)) {
                $this->deleteDirectory($cacheDir);
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
            }

            if (class_exists('Cache') && ($cache = cache()) && method_exists($cache, 'clear')) {
                $cache->clear();
            }

            $this->setMessage('Кеш успішно очищено', 'success');
            $this->redirect('cache-view');
            exit;
        } catch (Exception $e) {
            $this->setMessage('Помилка при очищенні кешу: ' . $e->getMessage(), 'danger');
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage clearAllCache error: ' . $e->getMessage());
            }
        }
    }

    private function clearCacheItem(): void
    {
        if (!$this->verifyCsrf() || !$this->hasCacheAccess()) {
            return;
        }

        $key = SecurityHelper::sanitizeInput($_POST['key'] ?? '');
        if (empty($key)) {
            $this->setMessage('Не вказано ключ кешу', 'danger');
            return;
        }

        try {
            $cacheFile = $this->getCacheDir() . '/' . $key . '.cache';
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }

            if (class_exists('Cache') && ($cache = cache()) && method_exists($cache, 'delete')) {
                $cache->delete($key);
            }

            $this->setMessage('Елемент кешу успішно видалено', 'success');
            $this->redirect('cache-view');
            exit;
        } catch (Exception $e) {
            $this->setMessage('Помилка при видаленні елемента кешу: ' . $e->getMessage(), 'danger');
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage clearCacheItem error: ' . $e->getMessage());
            }
        }
    }

    private function hasCacheAccess(): bool
    {
        $session = sessionManager();
        $userId = (int)($session->get('admin_user_id') ?? 0);
        $hasAccess = ($userId === 1) || (function_exists('current_user_can') && current_user_can('admin.cache.clear'));
        if (!$hasAccess) $this->setMessage('У вас немає прав на очищення кешу', 'danger');
        return $hasAccess;
    }

    private function getCacheDir(): string
    {
        return defined('CACHE_DIR') ? CACHE_DIR : (defined('ROOT_DIR') ? ROOT_DIR . '/storage/cache' : dirname(__DIR__, 5) . '/storage/cache');
    }

    /**
     * Отримання вмісту кеш файлу для перегляду
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    private function getCacheContent(): array
    {
        if (!$this->hasCacheAccess()) {
            return ['success' => false, 'error' => 'Немає прав доступу'];
        }

        $key = SecurityHelper::sanitizeInput($_GET['key'] ?? '');
        if (empty($key)) {
            return ['success' => false, 'error' => 'Не вказано ключ кешу'];
        }

        try {
            $cacheFile = $this->getCacheDir() . '/' . $key . '.cache';
            
            // Захист від path traversal
            $realCacheDir = realpath($this->getCacheDir());
            $realFile = realpath($cacheFile);
            
            if (!$realFile || strpos($realFile, $realCacheDir) !== 0) {
                return ['success' => false, 'error' => 'Недійсний шлях до файлу'];
            }

            if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
                return ['success' => false, 'error' => 'Файл не знайдено або недоступний для читання'];
            }

            $content = file_get_contents($cacheFile);
            if ($content === false) {
                return ['success' => false, 'error' => 'Помилка читання файлу'];
            }

            // Спроба десеріалізації
            $data = @unserialize($content);
            $isSerialized = ($data !== false);
            
            // Якщо unserialize повернув false, перевіряємо чи це дійсно серіалізований false
            if (!$isSerialized && $content === serialize(false)) {
                $data = false;
                $isSerialized = true;
            }
            
            // Форматуємо дані для відображення
            $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 5);
            $filePath = str_replace($rootDir . '/', '', $cacheFile);
            
            $formattedData = [
                'key' => $key,
                'file_size' => filesize($cacheFile),
                'file_path' => $filePath,
                'modified' => date('Y-m-d H:i:s', filemtime($cacheFile)),
                'is_serialized' => $isSerialized,
            ];

            if ($isSerialized) {
                $formattedData['data'] = $data;
                
                // JSON форматування
                $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($jsonData === false) {
                    $jsonData = 'Помилка форматування JSON';
                }
                $formattedData['data_json'] = $jsonData;
                
                // PHP var_export форматування
                ob_start();
                var_export($data);
                $formattedData['data_var_export'] = ob_get_clean();
                
                // Raw як JSON для серіалізованих даних
                $formattedData['raw_content'] = $jsonData;
            } else {
                // Якщо не серіалізовано, показуємо як текст (обмежуємо до 10KB для безпеки)
                $maxRawLength = 10240;
                if (strlen($content) > $maxRawLength) {
                    $formattedData['raw_content'] = substr($content, 0, $maxRawLength) . "\n\n... (файл обрізано, розмір: " . $this->formatFileSize(strlen($content)) . ")";
                } else {
                    $formattedData['raw_content'] = $content;
                }
            }

            return ['success' => true, 'data' => $formattedData];
        } catch (Exception $e) {
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage getCacheContent error: ' . $e->getMessage());
            }
            return ['success' => false, 'error' => 'Помилка: ' . $e->getMessage()];
        }
    }

    /**
     * Отримання статистики активності для ключа кешу
     *
     * @param string $key Ключ кешу
     * @return array{level: string, score: float, access_count: int, last_access: int|null}
     */
    private function getActivityStats(string $key): array
    {
        try {
            $cacheDir = $this->getCacheDir();
            $tracker = new CacheStatsTracker($cacheDir);
            $stats = $tracker->getStats($key);
            
            if ($stats === null) {
                // Якщо статистики немає, використовуємо час модифікації файлу як індикатор
                $cacheFile = $cacheDir . '/' . $key . '.cache';
                if (file_exists($cacheFile)) {
                    $modifiedTime = filemtime($cacheFile);
                    $daysSinceModified = (time() - $modifiedTime) / 86400;
                    
                    // Якщо файл змінений за останні 24 години - висока активність
                    // Якщо 1-7 днів - середня
                    // Більше 7 днів - низька
                    if ($daysSinceModified < 1) {
                        return [
                            'level' => 'high', 
                            'score' => 80.0,
                            'access_count' => 0,
                            'last_access' => $modifiedTime
                        ];
                    } elseif ($daysSinceModified < 7) {
                        return [
                            'level' => 'medium', 
                            'score' => 40.0,
                            'access_count' => 0,
                            'last_access' => $modifiedTime
                        ];
                    }
                }
                
                return [
                    'level' => 'low', 
                    'score' => 10.0,
                    'access_count' => 0,
                    'last_access' => null
                ];
            }
            
            return [
                'level' => $stats['level'],
                'score' => (float)$stats['score'],
                'access_count' => (int)($stats['access_count'] ?? 0),
                'last_access' => isset($stats['last_access']) ? (int)$stats['last_access'] : null
            ];
        } catch (Exception $e) {
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage getActivityStats error: ' . $e->getMessage());
            }
            return [
                'level' => 'low', 
                'score' => 0.0,
                'access_count' => 0,
                'last_access' => null
            ];
        }
    }
    
    /**
     * Форматування розміру файлу для відображення
     *
     * @param int $bytes Розмір в байтах
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * Отримання налаштувань кешу з БД
     *
     * @return array<string, string>
     */
    private function getCacheSettings(): array
    {
        $defaultSettings = [
            'cache_enabled' => '1',
            'cache_default_ttl' => '3600',
            'cache_auto_cleanup' => '1',
        ];

        if (class_exists('SettingsManager') && function_exists('settingsManager')) {
            try {
                $settingsManager = settingsManager();
                $allSettings = $settingsManager->all();
                
                return [
                    'cache_enabled' => $allSettings['cache_enabled'] ?? $defaultSettings['cache_enabled'],
                    'cache_default_ttl' => $allSettings['cache_default_ttl'] ?? $defaultSettings['cache_default_ttl'],
                    'cache_auto_cleanup' => $allSettings['cache_auto_cleanup'] ?? $defaultSettings['cache_auto_cleanup'],
                ];
            } catch (Exception $e) {
                if (function_exists('logger')) {
                    logger()->logError('CacheViewAdminPage getCacheSettings error: ' . $e->getMessage());
                }
            }
        }

        return $defaultSettings;
    }

    /**
     * Перевірка, чи кеш застарілий
     * Аналізує файл кешу та перевіряє поле expires
     *
     * @param string $filePath Повний шлях до файлу кешу
     * @param int $currentTime Поточний час (timestamp)
     * @param int $defaultTtl Час життя кешу за замовчуванням (секунди)
     * @return array{is_expired: bool, expires_at: string|null, expires_in: string|null, status: string}
     */
    private function checkCacheExpiry(string $filePath, int $currentTime, int $defaultTtl): array
    {
        $result = [
            'is_expired' => false,
            'expires_at' => null,
            'expires_in' => null,
            'status' => 'unknown',
        ];

        try {
            if (!is_readable($filePath)) {
                return $result;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                return $result;
            }

            $data = @unserialize($content);
            if ($data === false || !is_array($data)) {
                return $result;
            }

            // Перевіряємо поле expires
            if (isset($data['expires']) && is_int($data['expires'])) {
                $expiresTimestamp = $data['expires'];
                $result['expires_at'] = date('Y-m-d H:i:s', $expiresTimestamp);
                
                if ($expiresTimestamp < $currentTime) {
                    // Кеш застарілий
                    $result['is_expired'] = true;
                    $result['status'] = 'expired';
                    $secondsAgo = $currentTime - $expiresTimestamp;
                    $result['expires_in'] = $this->formatTimeAgo($secondsAgo);
                } else {
                    // Кеш активний
                    $result['is_expired'] = false;
                    $result['status'] = 'active';
                    $secondsLeft = $expiresTimestamp - $currentTime;
                    $result['expires_in'] = $this->formatTimeLeft($secondsLeft);
                }
            } else {
                // Якщо expires відсутнє, перевіряємо created + default_ttl
                if (isset($data['created']) && is_int($data['created'])) {
                    $createdTimestamp = $data['created'];
                    $expiresTimestamp = $createdTimestamp + $defaultTtl;
                    $result['expires_at'] = date('Y-m-d H:i:s', $expiresTimestamp);
                    
                    if ($expiresTimestamp < $currentTime) {
                        $result['is_expired'] = true;
                        $result['status'] = 'expired';
                        $secondsAgo = $currentTime - $expiresTimestamp;
                        $result['expires_in'] = $this->formatTimeAgo($secondsAgo);
                    } else {
                        $result['is_expired'] = false;
                        $result['status'] = 'active';
                        $secondsLeft = $expiresTimestamp - $currentTime;
                        $result['expires_in'] = $this->formatTimeLeft($secondsLeft);
                    }
                } else {
                    // Неможливо визначити термін дії
                    $result['status'] = 'unknown';
                }
            }
        } catch (Exception $e) {
            if (function_exists('logger')) {
                logger()->logError('CacheViewAdminPage checkCacheExpiry error: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Форматування часу, що пройшов
     *
     * @param int $seconds Кількість секунд
     * @return string
     */
    private function formatTimeAgo(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' сек тому';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return $minutes . ' хв тому';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            return $hours . ' год тому';
        } else {
            $days = floor($seconds / 86400);
            return $days . ' дн тому';
        }
    }

    /**
     * Форматування часу, що залишився
     *
     * @param int $seconds Кількість секунд
     * @return string
     */
    private function formatTimeLeft(int $seconds): string
    {
        if ($seconds < 60) {
            return 'через ' . $seconds . ' сек';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return 'через ' . $minutes . ' хв';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            return 'через ' . $hours . ' год';
        } else {
            $days = floor($seconds / 86400);
            return 'через ' . $days . ' дн';
        }
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) return false;

        $systemFiles = ['.gitkeep', '.htaccess'];
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            if (in_array($file, $systemFiles)) continue;
            $path = $dir . '/' . $file;
            if (is_file($path) && pathinfo($file, PATHINFO_EXTENSION) === 'cache') {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->deleteDirectory($path);
            }
        }
        return true;
    }
}
