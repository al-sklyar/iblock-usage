<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\TypeTable;

require_once __DIR__ . '/site_paths.php';


$jsonPath = __DIR__ . '/iblock_usage.json';
$usageMap = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

if (!$usageMap) {
    if (!Loader::includeModule('iblock')) {
        echo 'Не удалось подключить модуль iblock';
        return;
    }

    // Получаем пути к сайтам
    $sitePaths = getAllSitePaths();

    $result = [];
    $iblocks = IblockTable::getList([
        'select' => ['ID', 'IBLOCK_TYPE_ID', 'LID', 'NAME', 'ACTIVE']
    ]);

    while ($ib = $iblocks->fetch()) {
        $typeName = '';
        $type = TypeTable::getRow([
            'filter' => ['=ID' => $ib['IBLOCK_TYPE_ID']]
        ]);
        if ($type) {
            $typeLang = \Bitrix\Iblock\TypeLanguageTable::getRow([
                'filter' => [
                    '=IBLOCK_TYPE_ID' => $type['ID'],
                    '=LANGUAGE_ID' => LANGUAGE_ID
                ],
                'select' => ['NAME']
            ]);
            if ($typeLang) $typeName = $typeLang['NAME'];
        }

        $sites = [];
        $siteRes = \Bitrix\Iblock\IblockSiteTable::getList([
            'filter' => ['IBLOCK_ID' => $ib['ID']],
            'select' => ['SITE_ID']
        ]);
        while ($site = $siteRes->fetch()) {
            $sites[] = $site['SITE_ID'];
        }

        $sitePathData = [];
        foreach ($sites as $lid) {
            if (isset($sitePaths[$lid])) {
                $sitePathData[$lid] = $sitePaths[$lid];
            }
        }

        $result[$ib['ID']] = [
            'ID' => $ib['ID'],
            'TYPE_ID' => $ib['IBLOCK_TYPE_ID'],
            'TYPE_NAME' => $typeName,
            'ACTIVE' => $ib['ACTIVE'],
            'NAME' => $ib['NAME'],
            'LID' => implode(', ', $sites),
            'PATHS' => [],
            'SITE_PATHS' => $sitePathData
        ];
    }

    file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $usageMap = $result;
}
?>

<style>
    table {
        border-collapse: collapse;
        margin-top: 20px
    }

    th, td {
        border: 1px solid #ccc;
        padding: 4px 8px
    }

    button {
        margin-right: 10px
    }
</style>

<h2>Использование инфоблоков</h2>
<div style="margin-bottom: 10px">
    Глубина сканирования:
    <input id="scan-depth" type="number" min="1" max="8" value="1" style="width: 50px">
</div>
<div>
    <button onclick="startScan(true)">Сканировать все</button>
    <button onclick="startScan(false)">Только не найденные</button>
</div>
<div id="scan-progress" style="margin: 10px 0; color: #666"></div>
<table id="iblock-table">
    <thead>
    <tr>
        <th>ID типа ИБ</th>
        <th>Название типа ИБ</th>
        <th>ID ИБ</th>
        <th>Акт.</th>
        <th>Название ИБ</th>
        <th>ID сайта</th>
        <th>Путь сайта</th>
        <th>Пути к файлам</th>
    </tr>
    </thead>
    <tbody></tbody>
</table>

<script>
    const iblocks = <?= json_encode($usageMap) ?>;
    const tableBody = document.querySelector("#iblock-table tbody");
    let currentScanningId = null;

    function renderTable() {
        tableBody.innerHTML = '';

        Object.values(iblocks).forEach(ib => {
            const isScanning = ib.ID == currentScanningId;

            const tr = document.createElement('tr');
            tr.innerHTML = `
            <td>${ib.TYPE_ID}</td>
            <td>${ib.TYPE_NAME || ''}</td>
            <td>${ib.ID}</td>
            <td>${ib.ACTIVE}</td>
            <td>${ib.NAME}</td>
            <td>${ib.LID}</td>
            <td>
                ${(ib.SITE_PATHS && Object.values(ib.SITE_PATHS).length)
                ? Object.entries(ib.SITE_PATHS).map(([lid, path]) => `${lid}: ${path}`).join('<br>')
                : '-'}
            </td>
            <td>
                ${isScanning
                ? `<svg width="16" height="16" viewBox="0 0 100 100" style="vertical-align: middle">
                        <circle cx="50" cy="50" r="35" stroke="#999" stroke-width="10" fill="none" stroke-dasharray="164" stroke-dashoffset="0">
                            <animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50" dur="1s" repeatCount="indefinite"/>
                        </circle>
                       </svg>
                       <span style="margin-left: 5px; color: #666">Сканируется...</span>`
                : (ib.PATHS || []).join('<br>')}
            </td>
        `;
            tableBody.appendChild(tr);
        });
    }

    async function startScan(fullRebuild) {

        const depth = parseInt(document.getElementById('scan-depth').value) || 1;
        const ids = Object.keys(iblocks).filter(id => {
            return fullRebuild || (iblocks[id].PATHS || []).length === 0;
        });

        const progress = document.getElementById('scan-progress');
        const total = ids.length;
        let current = 0;

        progress.textContent = `Начинаем сканирование (${total} инфоблоков)...`;

        for (let i = 0; i < total; i++) {

            const id = ids[i];
            currentScanningId = id;
            current++;

            renderTable();

            progress.textContent = `Сканируется ИБ #${id} (${current} из ${total})...`;
            let continueScanning = true;

            while (continueScanning) {
                try {
                    const response = await fetch('scan_worker.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({iblockId: id, fullRebuild, depth})
                    });

                    if (!response.ok) {
                        console.error(`HTTP ${response.status}`, await response.text());
                        continueScanning = false;
                        continue;                                                 // переходим к след. циклу
                    }

                    const text = await response.text();
                    let result;

                    try {
                        result = JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON:', text);
                        continueScanning = false;   // сбрасываем флаг
                        continue;                  // идём к следующему ID или выходим из while
                    }
                    if (result.success) {
                        iblocks[id].PATHS = result.paths;
                    }

                    renderTable();

                    // Проверка: завершено или надо продолжить
                    continueScanning = result.continueScanning === true;

                } catch (e) {
                    console.error('Fetch error:', e);
                    continueScanning = false;
                    continue;
                }

                await new Promise(r => setTimeout(r, 500));
            }
            currentScanningId = null;
            renderTable();
        }
        progress.textContent = `Сканирование завершено.`;

        // В конце всех запросов сохраняем данные
        fetch('save_usage.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(iblocks)
        });
    }

    renderTable();
</script>