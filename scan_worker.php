<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
set_time_limit(20);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once __DIR__ . '/site_paths.php';

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;


// Получение данных из запроса
$data = json_decode(file_get_contents("php://input"), true);
$depth = (int)($data['depth'] ?? 1);
if ($depth < 1 || $depth > 8) $depth = 1;
$iblockId = (int)$data['iblockId'];
$fullRebuild = isset($data['fullRebuild']) ? (bool)$data['fullRebuild'] : true;

if ($iblockId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$jsonPath = __DIR__ . '/iblock_usage.json';
$usageMap = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

if (!Loader::includeModule('iblock')) {
    echo json_encode(['success' => false, 'error' => 'Iblock module not loaded']);
    exit;
}

// Получаем сайты для ИБ
$sites = [];
$siteRes = \Bitrix\Iblock\IblockSiteTable::getList([
    'filter' => ['IBLOCK_ID' => $iblockId],
    'select' => ['SITE_ID']
]);
while ($site = $siteRes->fetch()) {
    $sites[] = $site['SITE_ID'];
}

// Получаем все пути ко всем сайтам
$allSitePaths = getAllSitePaths();

// Фильтруем только нужные по текущему ИБ
$sitePaths = [];
foreach ($sites as $lid) {
    if (isset($allSitePaths[$lid])) {
        $sitePaths[$lid] = $allSitePaths[$lid];
    }
}

// Поиск файлов по каждому сайту
$paths = [];

$skipDirs = [
    '/upload',
    '/bitrix/components/bitrix',
    '/bitrix/modules',
    '/bitrix/cache',
    '/bitrix/managed_cache',
    '/bitrix/stack_cache',
];

$maxDepth = $depth;
$delayMicroseconds = 10000;
$startTime = microtime(true);
$timeLimit = 10; // максимум секунд работы одного запроса
$timeoutReached = false;

foreach ($sitePaths as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }

    /* 1. Итератор-каталог с фильтром ―
      пропускаем тяжёлые каталоги (upload, cache, system) */
    $directory = new RecursiveDirectoryIterator(
        $scanRoot,
        RecursiveDirectoryIterator::SKIP_DOTS
    );

    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        // ← принимаем все три параметра, даже если используем только $fileInfo
        function ($fileInfo, $key, $iterator) use ($skipDirs, $scanRoot) {

            if ($fileInfo->isDir()) {
                $rel = str_replace($scanRoot, '', $fileInfo->getPathname());
                foreach ($skipDirs as $dir) {
                    if (stripos($rel, $dir) === 0) {
                        return false;        // не углубляемся
                    }
                }
            }
            return true;                     // разрешаем проход
        }
    );

    $iterator = new RecursiveIteratorIterator(
        $filter,
        RecursiveIteratorIterator::SELF_FIRST
    );

    /* 2. Проходим только по PHP-файлам */
    foreach ($iterator as $fileInfo) {
        // Отсекаем файлы глубже maxDepth
        if ($iterator->getDepth() >= $maxDepth) {
            continue;
        }

        if (!$fileInfo->isFile()) {
            continue;
        }

        // сканируем только *.php
        if (strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $filePath = $fileInfo->getPathname();
        $content = @file_get_contents($filePath);
        if ($content === false) {
            continue;
        }

//        if (preg_match(
//            '/["\']IBLOCK_ID["\']\s*=>\s*(["\']?)' . preg_quote((string)$iblockId, '/') . '\1/',
//            $content
//        )) {
//            $paths[] = $filePath;
//        }
        if (preg_match(
        // ищем любое упоминание …IBLOCK_ID … 123 …
            '/IBLOCK_ID[^0-9]{0,10}' . preg_quote((string)$iblockId, '/') . '\b/i',
            $content
        )
        ) {
            $paths[] = $filePath;
        }

        // тайм-аут одного прохода
        if ((microtime(true) - $startTime) > $timeLimit) {
            $timeoutReached = true;
            break 2;
        }

        usleep($delayMicroseconds);
    }
}

// убираем дубли, если один и тот же файл встретился несколько раз
$paths = array_values(array_unique($paths));

// Обновление структуры usageMap
if ($fullRebuild || empty($usageMap[$iblockId])) {
    // Полное обновление из БД
    $iblock = IblockTable::getRow([
        'filter' => ['=ID' => $iblockId],
        'select' => ['ID', 'IBLOCK_TYPE_ID', 'LID', 'NAME', 'ACTIVE']
    ]);

    if (!$iblock) {
        echo json_encode(['success' => false, 'error' => 'Iblock not found']);
        exit;
    }

    $typeName = '';
    $typeLang = \Bitrix\Iblock\TypeLanguageTable::getRow([
        'filter' => [
            '=IBLOCK_TYPE_ID' => $iblock['IBLOCK_TYPE_ID'],
            '=LANGUAGE_ID' => LANGUAGE_ID
        ],
        'select' => ['NAME']
    ]);
    if ($typeLang) $typeName = $typeLang['NAME'];

    $usageMap[$iblockId] = [
        'ID' => $iblock['ID'],
        'TYPE_ID' => $iblock['IBLOCK_TYPE_ID'],
        'TYPE_NAME' => $typeName,
        'ACTIVE' => $iblock['ACTIVE'],
        'NAME' => $iblock['NAME'],
        'LID' => implode(', ', $sites),
        'PATHS' => $paths,
        'SITE_PATHS' => $sitePaths
    ];
} else {
    // Только обновляем PATHS
    $usageMap[$iblockId]['PATHS'] = $paths;
}

file_put_contents($jsonPath, json_encode($usageMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'success' => true,
    'paths' => $paths,
    'continueScanning' => $timeoutReached   // true - есть ещё что сканировать
]);