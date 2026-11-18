<?php
/**
 * Раннер тестов проекта.
 *
 * Тесты — файлы tools/tests/NN_name.php. Каждый печатает ход проверки
 * и зовёт check(); раннер обнуляет счётчики перед запуском, ловит
 * исключения и печатает сводку.
 *
 * Из PHP-консоли:
 *   require $_SERVER['DOCUMENT_ROOT'] . '/local/modules/alex.autoservice/tools/tests/run.php';
 *   alexAutoserviceRunTests();                     // все
 *   alexAutoserviceRunTests(['12_journey']);       // выборочно
 *
 * Из браузера доступен на странице «Автосервис: тесты» в админке.
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('alexAutoserviceTestList')) {
    /**
     * Список тестов: код файла => название из шапки.
     *
     * @return array<string, array{title: string, file: string}>
     */
    function alexAutoserviceTestList(): array
    {
        $list = [];

        foreach ((array) glob(__DIR__ . '/[0-9]*.php') as $file) {
            $code   = basename($file, '.php');
            $source = (string) file_get_contents($file);
            $title  = $code;

            if (preg_match('/^\s*\*\s*Тест:\s*(.+)$/mu', $source, $matches)) {
                $title = trim($matches[1]);
            }

            $list[$code] = ['title' => $title, 'file' => $file];
        }

        ksort($list);

        return $list;
    }

    /**
     * Выполняет файл теста в собственной области видимости.
     *
     * Тесты объявляют собственные переменные — $code, $started и прочие.
     * Если включать их прямо в теле раннера, они перетирают его данные:
     * так в сводке вместо имени теста появлялся код стадии, а время
     * прогона становилось отрицательным. Замыкание эту связь разрывает.
     *
     * @param string $file
     *
     * @return void
     */
    function alexAutoserviceIncludeTest(string $file): void
    {
        $run = static function (string $path): void {
            include $path;
        };

        $run($file);
    }

    /**
     * Запускает тесты и печатает сводку.
     *
     * @param string[] $only коды тестов; пустой массив — все
     *
     * @return array<string, array{title: string, ok: int, fail: int, time: float}>
     */
    function alexAutoserviceRunTests(array $only = []): array
    {
        $results = [];

        foreach (alexAutoserviceTestList() as $code => $test) {
            if ($only && !in_array($code, $only, true)) {
                continue;
            }

            $GLOBALS['pass'] = 0;
            $GLOBALS['fail'] = 0;

            echo "\n" . str_repeat('=', 76) . "\n";
            echo $code . ' — ' . $test['title'] . "\n";
            echo str_repeat('=', 76) . "\n\n";

            $started = microtime(true);

            try {
                alexAutoserviceIncludeTest($test['file']);
            } catch (\Throwable $e) {
                $GLOBALS['fail']++;

                echo '  FAIL необработанное исключение — ' . get_class($e) . ': '
                    . $e->getMessage() . "\n";
                echo '       ' . $e->getFile() . ':' . $e->getLine() . "\n";
            }

            $results[$code] = [
                'title' => $test['title'],
                'ok'    => (int) $GLOBALS['pass'],
                'fail'  => (int) $GLOBALS['fail'],
                'time'  => round(microtime(true) - $started, 2),
            ];
        }

        alexAutoservicePrintSummary($results);

        return $results;
    }

    /**
     * @param array<string, array{title: string, ok: int, fail: int, time: float}> $results
     *
     * @return void
     */
    function alexAutoservicePrintSummary(array $results): void
    {
        $ok   = 0;
        $fail = 0;
        $time = 0.0;

        echo "\n" . str_repeat('=', 76) . "\n";
        echo "СВОДКА\n";
        echo str_repeat('=', 76) . "\n\n";

        foreach ($results as $code => $result) {
            $ok   += $result['ok'];
            $fail += $result['fail'];
            $time += $result['time'];

            echo '  ' . ($result['fail'] ? 'FAIL ' : 'OK   ')
                . str_pad($code, 18)
                . str_pad($result['ok'] . ' / ' . ($result['ok'] + $result['fail']), 12)
                . str_pad($result['time'] . ' c', 10)
                . $result['title'] . "\n";
        }

        echo "\n" . str_repeat('-', 76) . "\n";
        echo '  ИТОГО: OK ' . $ok . ', FAIL ' . $fail
            . ', тестов ' . count($results)
            . ', время ' . round($time, 2) . " c\n";
    }
}
