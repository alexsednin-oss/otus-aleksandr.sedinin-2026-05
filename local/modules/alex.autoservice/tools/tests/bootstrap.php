<?php
/**
 * Общие помощники тестов проекта.
 *
 * Каждый тест — обычный скрипт, который печатает ход проверки и зовёт
 * check(). Счётчики живут в глобальных переменных: раннер обнуляет их
 * перед каждым тестом и забирает итог после.
 */

if (!function_exists('check')) {
    /**
     * Одна проверка.
     *
     * @param string $title  что проверяем
     * @param bool   $ok     результат
     * @param string $detail пояснение в строке отчёта
     *
     * @return void
     */
    function check(string $title, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;

        $ok ? $pass++ : $fail++;

        echo ($ok ? '  OK   ' : '  FAIL ') . $title . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

if (!function_exists('report')) {
    /**
     * Печатает отчёт миграции или сервиса построчно.
     *
     * @param string[] $lines
     *
     * @return void
     */
    function report(array $lines): void
    {
        foreach ($lines as $line) {
            echo '    ' . htmlspecialcharsbx($line) . "\n";
        }
    }
}
