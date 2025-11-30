<?php

/**
 * Відстеження статистики використання кешу
 * 
 * Зберігає інформацію про частоту використання кожного ключа кешу
 * для відображення heatmap активності
 */

declare(strict_types=1);

class CacheStatsTracker
{
    private string $statsFile;
    private array $stats = [];
    private int $maxEntries = 10000; // Максимальна кількість записів
    private int $decayDays = 30; // Статистика старша за 30 днів видаляється

    public function __construct(?string $cacheDir = null)
    {
        if ($cacheDir === null) {
            $cacheDir = defined('CACHE_DIR') 
                ? CACHE_DIR 
                : (defined('ROOT_DIR') 
                    ? ROOT_DIR . '/storage/cache' 
                    : dirname(__DIR__, 5) . '/storage/cache');
        }
        
        $this->statsFile = rtrim($cacheDir, '/') . '/.cache-stats.json';
        $this->loadStats();
    }

    /**
     * Завантаження статистики з файлу
     */
    private function loadStats(): void
    {
        if (!file_exists($this->statsFile)) {
            $this->stats = [];
            return;
        }

        $content = @file_get_contents($this->statsFile);
        if ($content === false) {
            $this->stats = [];
            return;
        }

        $data = @json_decode($content, true);
        if (!is_array($data)) {
            $this->stats = [];
            return;
        }

        $this->stats = $data;
        $this->cleanupOldStats();
    }

    /**
     * Збереження статистики в файл
     */
    private function saveStats(): void
    {
        $this->cleanupOldStats();
        
        $content = json_encode($this->stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($content === false) {
            return;
        }

        $dir = dirname($this->statsFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Записуємо файл з блокуванням
        $result = @file_put_contents($this->statsFile, $content, LOCK_EX);
        if ($result !== false) {
            // Оновлюємо час останнього збереження
            $this->setLastSaveTime(time());
        }
    }

    /**
     * Відстеження доступу до ключа кешу
     * 
     * @param string $key Ключ кешу
     */
    public function trackAccess(string $key): void
    {
        if (empty($key)) {
            return;
        }

        $now = time();
        
        if (!isset($this->stats[$key])) {
            $this->stats[$key] = [
                'access_count' => 0,
                'first_access' => $now,
                'last_access' => $now,
                'recent_accesses' => []
            ];
        }

        $this->stats[$key]['access_count']++;
        $this->stats[$key]['last_access'] = $now;
        
        // Зберігаємо останні 100 звернень для аналізу частоти
        $this->stats[$key]['recent_accesses'][] = $now;
        if (count($this->stats[$key]['recent_accesses']) > 100) {
            array_shift($this->stats[$key]['recent_accesses']);
        }

        // Обмежуємо кількість записів
        if (count($this->stats) > $this->maxEntries) {
            // Видаляємо найстаріші записи
            uasort($this->stats, function($a, $b) {
                return ($a['last_access'] ?? 0) <=> ($b['last_access'] ?? 0);
            });
            $this->stats = array_slice($this->stats, -$this->maxEntries, null, true);
        }

        // Зберігаємо статистику:
        // - При першому зверненні (access_count === 1) - негайно
        // - Кожні 5 звернень
        // - Або раз на 30 секунд (щоб швидше бачити статистику)
        $shouldSave = false;
        if ($this->stats[$key]['access_count'] === 1) {
            $shouldSave = true; // Зберігаємо перше звернення негайно
        } elseif ($this->stats[$key]['access_count'] % 5 === 0) {
            $shouldSave = true; // Кожні 5 звернень
        } else {
            $lastSaveTime = $this->getLastSaveTime();
            if ($lastSaveTime === null || ($now - $lastSaveTime) > 30) {
                $shouldSave = true; // Раз на 30 секунд
            }
        }

        if ($shouldSave) {
            $this->saveStats();
        }
    }

    /**
     * Отримання статистики для ключа
     * 
     * @param string $key Ключ кешу
     * @return array{level: string, score: float, access_count: int, last_access: int}|null
     */
    public function getStats(string $key): ?array
    {
        if (!isset($this->stats[$key])) {
            return null;
        }

        $stat = $this->stats[$key];
        $score = $this->calculateActivityScore($stat);
        $level = $this->getActivityLevel($score);

        return [
            'level' => $level, // 'high' - висока, 'medium' - середня, 'low' - низька
            'score' => $score,
            'access_count' => $stat['access_count'] ?? 0,
            'last_access' => $stat['last_access'] ?? 0
        ];
    }

    /**
     * Розрахунок оцінки активності
     * 
     * @param array $stat Статистика ключа
     * @return float Оцінка від 0 до 100
     */
    private function calculateActivityScore(array $stat): float
    {
        $now = time();
        $lastAccess = $stat['last_access'] ?? 0;
        $accessCount = $stat['access_count'] ?? 0;
        $recentAccesses = $stat['recent_accesses'] ?? [];

        // Якщо останній доступ був більше 7 днів тому - низька активність
        $daysSinceLastAccess = ($now - $lastAccess) / 86400;
        if ($daysSinceLastAccess > 7) {
            return 0;
        }

        // Рахуємо активність за останні 24 години
        $last24h = array_filter($recentAccesses, function($timestamp) use ($now) {
            return ($now - $timestamp) < 86400;
        });
        $recent24hCount = count($last24h);

        // Рахуємо активність за останні 7 днів
        $last7d = array_filter($recentAccesses, function($timestamp) use ($now) {
            return ($now - $timestamp) < 604800;
        });
        $recent7dCount = count($last7d);

        // Обчислюємо оцінку:
        // - Останні 24 год: 60% ваги
        // - Останні 7 днів: 30% ваги
        // - Загальна кількість: 10% ваги
        $score24h = min(100, ($recent24hCount / 10) * 60); // 10+ доступів за 24 год = максимум
        $score7d = min(100, ($recent7dCount / 50) * 30); // 50+ доступів за 7 днів = максимум
        $scoreTotal = min(100, (log($accessCount + 1) / log(1000)) * 10); // Логарифмічна шкала

        $totalScore = $score24h + $score7d + $scoreTotal;
        
        // Корекція на основі часу останнього доступу
        $timeMultiplier = max(0.3, 1 - ($daysSinceLastAccess / 7));
        $totalScore *= $timeMultiplier;

        return min(100, max(0, $totalScore));
    }

    /**
     * Визначення рівня активності
     * 
     * @param float $score Оцінка активності (0-100)
     * @return string 'high' (висока, >=60), 'medium' (середня, 20-60), 'low' (низька, <20)
     */
    private function getActivityLevel(float $score): string
    {
        if ($score >= 60) {
            return 'high';
        } elseif ($score >= 20) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Очищення застарілої статистики
     */
    private function cleanupOldStats(): void
    {
        $now = time();
        $cutoffTime = $now - ($this->decayDays * 86400);

        foreach ($this->stats as $key => $stat) {
            $lastAccess = $stat['last_access'] ?? 0;
            
            // Видаляємо записи старші за cutoffTime
            if ($lastAccess < $cutoffTime) {
                unset($this->stats[$key]);
                continue;
            }

            // Очищаємо старі звернення з recent_accesses
            if (isset($stat['recent_accesses']) && is_array($stat['recent_accesses'])) {
                $this->stats[$key]['recent_accesses'] = array_filter(
                    $stat['recent_accesses'],
                    function($timestamp) use ($cutoffTime) {
                        return $timestamp > $cutoffTime;
                    }
                );
            }
        }
    }

    /**
     * Отримання часу останнього збереження
     */
    private function getLastSaveTime(): ?int
    {
        $metaFile = $this->statsFile . '.meta';
        if (file_exists($metaFile)) {
            $content = @file_get_contents($metaFile);
            return $content ? (int)$content : null;
        }
        
        return null;
    }

    /**
     * Збереження часу останнього збереження
     */
    private function setLastSaveTime(int $time): void
    {
        $metaFile = $this->statsFile . '.meta';
        @file_put_contents($metaFile, (string)$time, LOCK_EX);
    }

    /**
     * Збереження статистики (публічний метод для ручного збереження)
     */
    public function save(): void
    {
        $this->setLastSaveTime(time());
        $this->saveStats();
    }

    /**
     * Отримання всієї статистики
     * 
     * @return array
     */
    public function getAllStats(): array
    {
        return $this->stats;
    }
}

