<?php

use Bitrix\Main\Loader;
use Bitrix\Main\SiteTable;
use Bitrix\Main\SiteDomainTable;

function getAllSitePaths(): array
{
    if (!Loader::includeModule('main')) {
        return [];
    }

    $paths = [];

    //  ▸  Базовая таблица  —  SiteTable
    $res = SiteTable::getList([
        'select'  => [
            'LID',
            'DIR',
            // берём домен из присоединённой таблицы SiteDomainTable
            'DOMAIN_NAME' => 'D.DOMAIN',
        ],
        'runtime' => [
            'D' => [
                'data_type' => SiteDomainTable::class,
                // правильная связь: LID ↔ LID
                'reference' => ['=this.LID' => 'ref.LID'],
                'join_type' => 'left',
            ],
        ],
    ]);

    while ($row = $res->fetch()) {
        if (!$row['DOMAIN_NAME']) {
            continue;                 // пропускаем сайты без домена
        }
        $dir = rtrim($row['DIR'], '/');
        $paths[$row['LID']] = "/home/bitrix/ext_www/{$row['DOMAIN_NAME']}{$dir}";
    }

    return $paths;
}