<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Синхронизация набора стадий.
 *
 * Идентификаторы стадий в неосновных воронках имеют префикс вида «C2:»,
 * а в смарт-процессах — «DT1038_7:». Префикс не вычисляем по формуле,
 * а снимаем с фактически существующих статусов: так код переживает
 * и смену версии, и перенос на другой портал.
 */
class Stages
{
    /**
     * Приводит набор стадий к заданному списку.
     *
     * @param string   $entityId    например DEAL_STAGE_2
     * @param array    $definitions [['CODE'=>'REPAIR','NAME'=>'Ремонт','SORT'=>40,'SEMANTICS'=>null,'COLOR'=>'#47d1e2'], ...]
     * @param int|null $categoryId  идентификатор воронки, если он нужен статусам
     *
     * @return string[] отчёт
     */
    public static function sync(string $entityId, array $definitions, ?int $categoryId = null): array
    {
        $report   = [];
        $existing = self::getExisting($entityId);
        $prefix = self::prefixFromEntityId($entityId);

        if ($prefix === '') {
            $prefix = self::detectPrefix($existing);
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_STAGES_PREFIX', [
            '#ENTITY#' => $entityId,
            '#PREFIX#' => $prefix !== '' ? $prefix : '—',
        ]);

        $status = new \CCrmStatus($entityId);
        $wanted = [];

        foreach ($definitions as $definition) {
            $statusId          = $prefix . $definition['CODE'];
            $wanted[$statusId] = true;

            $report[] = isset($existing[$statusId])
                ? self::updateStage($status, (int) $existing[$statusId]['ID'], $statusId, $definition)
                : self::addStage($status, $entityId, $statusId, $definition, $categoryId);
        }

        // Лишние стадии, доставшиеся от шаблона воронки
        foreach ($existing as $statusId => $row) {
            if (isset($wanted[$statusId])) {
                continue;
            }

            if (($row['SYSTEM'] ?? 'N') === 'Y') {
                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_STAGES_SYSTEM_KEPT', ['#STATUS#' => $statusId]);

                continue;
            }

            $status->Delete($row['ID']);

            $report[] = Loc::getMessage('ALEX_AUTOSERVICE_STAGES_DELETED', ['#STATUS#' => $statusId]);
        }

        return $report;
    }

    /**
     * Существующие статусы набора: STATUS_ID => строка.
     *
     * @param string $entityId
     *
     * @return array<string, array>
     */
    public static function getExisting(string $entityId): array
    {
        $result = [];
        $res    = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => $entityId]);

        while ($row = $res->Fetch()) {
            $result[(string) $row['STATUS_ID']] = $row;
        }

        return $result;
    }

    /**
     * Переименование и перестановка существующей стадии.
     *
     * STATUS_ID в Update передавать нельзя: CheckFields() валидирует его и
     * отвергает значение, уже занятое этой же стадией, после чего метод
     * выходит до записи. Из-за этого системные стадии (Новая, Сделка успешна,
     * Сделка провалена) молча оставались с исходными названиями.
     *
     * @param \CCrmStatus $status
     * @param int         $id
     * @param string      $statusId
     * @param array       $definition
     *
     * @return string строка отчёта
     */
    private static function updateStage(\CCrmStatus $status, int $id, string $statusId, array $definition): string
    {
        $fields = [
            'NAME' => $definition['NAME'],
            'SORT' => (int) $definition['SORT'],
        ];

        if (!empty($definition['COLOR'])) {
            $fields['COLOR'] = $definition['COLOR'];
        }

        if (!empty($definition['SEMANTICS'])) {
            $fields['SEMANTICS'] = $definition['SEMANTICS'];
        }

        $result = $status->Update($id, $fields);

        if ($result === false) {
            return Loc::getMessage('ALEX_AUTOSERVICE_STAGES_UPDATE_ERROR', [
                '#STATUS#' => $statusId,
                '#ERROR#'  => self::getLastError(),
            ]);
        }

        return Loc::getMessage('ALEX_AUTOSERVICE_STAGES_UPDATED', [
            '#STATUS#' => $statusId,
            '#NAME#'   => $definition['NAME'],
        ]);
    }

    /**
     * Добавление новой стадии.
     *
     * @param \CCrmStatus $status
     * @param string      $statusId уже с префиксом; Битрикс распознаёт его и не дублирует
     * @param array       $definition
     * @param int|null    $categoryId
     *
     * @return string строка отчёта
     */
    private static function addStage(\CCrmStatus $status, string $entityId, string $statusId, array $definition, ?int $categoryId): string
    {
        $fields = [
            'ENTITY_ID' => $entityId,
            'STATUS_ID' => $statusId,
            'NAME'      => $definition['NAME'],
            'SORT'      => (int) $definition['SORT'],
            'SYSTEM'    => 'N',
        ];

        if (!empty($definition['COLOR'])) {
            $fields['COLOR'] = $definition['COLOR'];
        }

        if (!empty($definition['SEMANTICS'])) {
            $fields['SEMANTICS'] = $definition['SEMANTICS'];
        }

        if ($categoryId !== null) {
            $fields['CATEGORY_ID'] = $categoryId;
        }

        $newId = $status->Add($fields);

        if (!$newId) {
            return Loc::getMessage('ALEX_AUTOSERVICE_STAGES_ADD_ERROR', [
                '#STATUS#' => $statusId,
                '#ERROR#'  => self::getLastError(),
            ]);
        }

        return Loc::getMessage('ALEX_AUTOSERVICE_STAGES_ADDED', [
            '#STATUS#' => $statusId,
            '#NAME#'   => $definition['NAME'],
        ]);
    }

    /**
     * Текст последней ошибки CCrmStatus.
     *
     * Свойство LAST_ERROR в актуальных сборках приватное, поэтому ошибку
     * читаем там, куда её кладёт CheckFields() — в исключение приложения.
     *
     * @return string
     */
    private static function getLastError(): string
    {
        global $APPLICATION;

        if (!is_object($APPLICATION)) {
            return '';
        }

        $exception = $APPLICATION->GetException();

        return $exception ? (string) $exception->GetString() : '';
    }

    /**
     * Префикс идентификаторов стадий, снятый с существующих статусов.
     *
     * @param array<string, array> $existing
     *
     * @return string
     */
    private static function detectPrefix(array $existing): string
    {
        foreach (array_keys($existing) as $statusId) {
            $position = mb_strpos($statusId, ':');

            if ($position !== false) {
                return mb_substr($statusId, 0, $position + 1);
            }
        }

        return '';
    }

    /**
     * Префикс идентификаторов стадий, выведенный из имени набора.
     *
     * Вычисляется по правилу, а не по существующим стадиям: у только что
     * созданного типа набор бывает ещё пуст, и тогда определить префикс
     * по данным невозможно — стадия создалась бы без него.
     *
     *   DEAL_STAGE_5            → C5:
     *   DEAL_STAGE              → '' (общая воронка)
     *   DYNAMIC_1046_STAGE_10   → DT1046_10:
     *
     * @param string $entityId
     *
     * @return string
     */
    public static function prefixFromEntityId(string $entityId): string
    {
        if (preg_match('/^DEAL_STAGE_(\d+)$/', $entityId, $matches)) {
            return 'C' . $matches[1] . ':';
        }

        if (preg_match('/^DYNAMIC_(\d+)_STAGE_(\d+)$/', $entityId, $matches)) {
            return 'DT' . $matches[1] . '_' . $matches[2] . ':';
        }

        return '';
    }
}
