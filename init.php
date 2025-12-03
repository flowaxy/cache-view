<?php
/**
 * Плагін перегляду кешу - ініціалізація
 */

declare(strict_types=1);

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
require_once $rootDir . '/engine/core/support/base/BasePlugin.php';
require_once $rootDir . '/engine/core/support/helpers/UrlHelper.php';

if (! function_exists('addHook')) {
    require_once $rootDir . '/engine/includes/functions.php';
}

// Завантажуємо ClassAutoloader для реєстрації класів
if (file_exists($rootDir . '/engine/core/system/ClassAutoloader.php')) {
    require_once $rootDir . '/engine/core/system/ClassAutoloader.php';
}

class CacheViewPlugin extends BasePlugin
{
    private string $pluginDir;

    public function __construct()
    {
        parent::__construct();
        $reflection = new ReflectionClass($this);
        $this->pluginDir = dirname($reflection->getFileName());
    }

    public function init(): void
    {
        addHook('admin_register_routes', [$this, 'registerAdminRoute'], 10, 1);
        addFilter('admin_menu', [$this, 'registerAdminMenu'], 15);
        addFilter('settings_categories', [$this, 'registerSettingsCategory'], 10);
        
        // Підписуємось на хуки кешу для відстеження статистики
        addHook('cache_get', [$this, 'trackCacheAccess'], 10, 1);
    }

    /**
     * Відстеження доступу до кешу
     */
    public function trackCacheAccess(string $key): void
    {
        try {
            $trackerFile = $this->pluginDir . '/src/Services/CacheStatsTracker.php';
            if (!file_exists($trackerFile)) {
                return;
            }
            
            if (!class_exists('CacheStatsTracker', false)) {
                require_once $trackerFile;
            }
            
            $cacheDir = defined('CACHE_DIR') ? CACHE_DIR : (defined('ROOT_DIR') ? ROOT_DIR . '/storage/cache' : null);
            if ($cacheDir) {
                $tracker = new \CacheStatsTracker($cacheDir);
                $tracker->trackAccess(md5($key));
            }
        } catch (\Throwable $e) {
            // Тихо ігноруємо помилки
        }
    }

    public function registerAdminRoute($router): void
    {
        $pageFile = $this->pluginDir . '/src/admin/pages/CacheViewAdminPage.php';
        if (file_exists($pageFile)) {
            // Реєструємо клас в автозавантажувачі
            if (isset($GLOBALS['engineAutoloader'])) {
                $autoloader = $GLOBALS['engineAutoloader'];
                if ($autoloader instanceof ClassAutoloader || 
                    (is_object($autoloader) && method_exists($autoloader, 'addClassMap'))) {
                    $autoloader->addClassMap([
                        'CacheViewAdminPage' => $pageFile
                    ]);
                }
            }
            
            require_once $pageFile;
            if (class_exists('CacheViewAdminPage')) {
                $router->add(['GET', 'POST'], 'cache-view', 'CacheViewAdminPage');
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $menu
     */
    public function registerAdminMenu(array $menu): array
    {
        // Шукаємо існуюче меню "Система"
        $found = false;
        foreach ($menu as &$item) {
            if (isset($item['page']) && $item['page'] === 'system') {
                if (!isset($item['submenu'])) {
                    $item['submenu'] = [];
                }
                $item['submenu'][] = [
                    'text' => 'Кеш',
                    'icon' => 'fas fa-database',
                    'href' => UrlHelper::admin('cache-view'),
                    'page' => 'cache-view',
                    'order' => 10,
                    'permission' => 'admin.cache.view',
                ];
                $found = true;
                break;
            }
        }
        
        // Якщо меню "Система" не знайдено, створюємо його
        if (!$found) {
            $menu[] = [
                'text' => 'Система',
                'icon' => 'fas fa-server',
                'href' => '#',
                'page' => 'system',
                'order' => 60,
                'permission' => null,
                'submenu' => [
                    [
                        'text' => 'Кеш',
                        'icon' => 'fas fa-database',
                        'href' => UrlHelper::admin('cache-view'),
                        'page' => 'cache-view',
                        'order' => 10,
                        'permission' => 'admin.cache.view',
                    ],
                ],
            ];
        }

        return $menu;
    }

    /**
     * Реєстрація в категоріях налаштувань
     * 
     * Додає плагін до категорії "Система" на сторінці /admin/settings
     * 
     * @param array<string, mixed> $categories Поточні категорії
     * @return array<string, mixed> Оновлені категорії
     */
    public function registerSettingsCategory(array $categories): array
    {
        // Перевіряємо, чи плагін активний
        $pluginManager = function_exists('pluginManager') ? pluginManager() : null;
        if (!$pluginManager || !method_exists($pluginManager, 'isPluginActive') || !$pluginManager->isPluginActive('cache-view')) {
            return $categories;
        }

        // Додаємо до категорії "Система"
        if (isset($categories['system'])) {
            $categories['system']['items'][] = [
                'title' => 'Кеш',
                'description' => 'Управління кешем',
                'url' => UrlHelper::admin('cache-view'),
                'icon' => 'fas fa-database',
                'permission' => 'admin.cache.view',
            ];
        }

        return $categories;
    }

    public function install(): void
    {
        // Немає таблиць для встановлення
    }
}

return new CacheViewPlugin();
