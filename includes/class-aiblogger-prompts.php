<?php
defined('ABSPATH') || exit;

/**
 * Carga y render de prompts desde assets/prompts/*.txt
 *
 * Regla de mantenimiento:
 * - El copy/instrucciones viven en .txt
 * - PHP solo calcula datos y reemplaza placeholders
 */
class AIBlogger_Prompts {

    /**
     * Devuelve el prompt desde assets/prompts/{name}.txt.
     * Si no existe o está vacío, devuelve $fallback (por defecto: '').
     */
    public static function get(string $name, string $fallback = ''): string {
        $name = trim($name);
        if ($name === '') return $fallback;

        $path = AIBLOGGER_DIR . 'assets/prompts/' . $name . '.txt';
        if (!file_exists($path)) return $fallback;

        $txt = (string)@file_get_contents($path);
        $txt = trim(str_replace(["\r\n", "\r"], "\n", $txt));
        return $txt !== '' ? $txt : $fallback;
    }

    /**
     * Render simple: reemplaza {{KEY}} por valor (KEY en mayúsculas).
     */
    public static function render(string $template, array $vars): string {
        if ($template === '') return '';

        $repl = [];
        foreach ($vars as $k => $v) {
            $key = strtoupper(trim((string)$k));
            if ($key === '') continue;
            $repl['{{' . $key . '}}'] = (string)$v;
        }
        return strtr($template, $repl);
    }
}
