<?php
declare(strict_types=1);

class Translator
{
    private static array $translations = [];
    private static string $lang = 'en';
    private static bool $loaded = false;

    public static function init(string $lang = 'en'): void
    {
        self::$lang = $lang;
        self::$loaded = false;
        self::$translations = [];
    }

    public static function get(string $key, array $params = []): string
    {
        if (!self::$loaded) {
            self::load();
        }

        $value = self::resolve($key);

        if ($value === null) {
            return $key;
        }

        return self::interpolate($value, $params);
    }

    private static function load(): void
    {
        self::$loaded = true;

        // Load JSON language file
        $file = __DIR__ . '/../languages/' . self::$lang . '.json';
        if (!file_exists($file)) {
            $file = __DIR__ . '/../languages/de.json';
        }

        $json = file_get_contents($file);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                self::$translations = self::flatten($data);
            }
        }

    }

    private static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result += self::flatten($value, $fullKey);
            } else {
                $result[$fullKey] = (string)$value;
            }
        }
        return $result;
    }

    private static function resolve(string $key): ?string
    {
        return self::$translations[$key] ?? null;
    }

    private static function interpolate(string $value, array $params): string
    {
        // Pluralization: "singular form|plural form" split on | when 'count' param present
        if (isset($params['count']) && str_contains($value, '|')) {
            $forms = explode('|', $value, 2);
            $value = ((int)$params['count'] === 1) ? $forms[0] : $forms[1];
        }

        foreach ($params as $placeholder => $replacement) {
            $value = str_replace('{' . $placeholder . '}', (string)$replacement, $value);
        }
        return $value;
    }

    public static function getLang(): string
    {
        return self::$lang;
    }

    public static function getAvailableLanguages(): array
    {
        return [
            ['code' => 'de', 'name' => 'Deutsch'],
            ['code' => 'en', 'name' => 'English'],
        ];
    }
}

function __(string $key, array $params = []): string
{
    return Translator::get($key, $params);
}

function detect_language(): string
{
    // 1. URL parameter
    if (!empty($_GET['lang'])) {
        $lang = preg_replace('/[^a-z]/', '', strtolower($_GET['lang']));
        if (in_array($lang, ['de', 'en'], true)) {
            $_SESSION['lang'] = $lang;
            return $lang;
        }
    }

    // 2. Session
    if (!empty($_SESSION['lang'])) {
        return $_SESSION['lang'];
    }

    // 3. Browser Accept-Language
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (str_starts_with(strtolower($acceptLang), 'de')) {
        $_SESSION['lang'] = 'de';
        return 'de';
    }

    // 4. Default
    $_SESSION['lang'] = 'de';
    return 'de';
}
