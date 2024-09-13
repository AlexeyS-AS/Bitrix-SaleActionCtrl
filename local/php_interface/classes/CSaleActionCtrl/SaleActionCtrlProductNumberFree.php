<?php
use Bitrix\Main\Localization\Loc;

Loc::loadLanguageFile(__FILE__);


\Bitrix\Main\EventManager::getInstance()->addEventHandlerCompatible(
    'sale',
    'OnCondSaleActionsControlBuildList',
    ['SaleActionCtrlProductNumberFree', 'GetControlDescr']
);

\Bitrix\Main\Loader::includeModule('sale');

class SaleActionCtrlProductNumberFree extends \CSaleActionCtrl
{
    public static function GetControlDescr()
    {
        $description = parent::GetControlDescr();
        $description['EXECUTE_MODULE'] = 'sale';
        $description['SORT'] = 1000;

        return $description;
    }

    public static function GetControlID()
    {
        return 'ActSaleProductNumberFree';
    }

    public static function GetControlShow($arParams)
    {
        $arAtoms = static::GetAtomsEx(false, false);

        $arResult = [
            'controlId' => static::GetControlID(),
            'group' => false,
            'label' => Loc::getMessage('SAC_PRODUCT_NUMBER_FREE_TITLE'),
            'defaultText' => Loc::getMessage('BT_SALE_ACT_DELIVERY_DEF_TEXT'),
            'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
            'control' => [
                Loc::getMessage('SAC_PRODUCT_NUMBER_FREE_NUMBER_BEFORE'),
                $arAtoms['Number'],
                Loc::getMessage('SAC_PRODUCT_NUMBER_FREE_NUMBER_AFTER'),
                Loc::getMessage('SAC_PRODUCT_NUMBER_FREE_SECTIONS_BEFORE'),
                $arAtoms['SectionIds'],
                Loc::getMessage('SAC_PRODUCT_NUMBER_FREE_SECTIONS_AFTER'),
            ],
            'mess' => [
                'DELETE_CONTROL' => Loc::getMessage('BT_SALE_ACT_GROUP_DELETE_CONTROL')
            ]
        ];

        return $arResult;
    }

    public static function GetAtoms()
    {
        return static::GetAtomsEx(false, false);
    }

    public static function GetAtomsEx($strControlID = false, $boolEx = false)
    {
        $boolEx = (true === $boolEx ? true : false);
        $arAtomList = [
            'Number' => [
                'JS' => [
                    'id' => 'Number',
                    'name' => 'extra_number',
                    'type' => 'input'
                ],
                'ATOM' => [
                    'ID' => 'Number',
                    'FIELD_TYPE' => 'int',
                    'MULTIPLE' => 'N',
                    'VALIDATE' => ''
                ]
            ],
            'SectionIds' => [
                'JS' => [
                    'id' => 'SectionIds',
                    'name' => 'extra_section',
                    'type' => 'input'
                ],
                'ATOM' => [
                    'ID' => 'SectionIds',
                    'FIELD_TYPE' => 'string',
                    'MULTIPLE' => 'N',
                    'VALIDATE' => ''
                ]
            ],
        ];

        if (!$boolEx)
        {
            foreach ($arAtomList as &$arOneAtom)
            {
                $arOneAtom = $arOneAtom['JS'];
            }
            if (isset($arOneAtom))
                unset($arOneAtom);
        }

        return $arAtomList;
    }

    public static function GetShowIn($arControls)
    {
        return [CSaleActionCtrlGroup::GetControlID()];
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        $mxResult = '';

        if (is_string($arControl))
        {
            if ($arControl == static::GetControlID())
            {
                $arControl = [
                    'ID' => static::GetControlID(),
                    'ATOMS' => static::GetAtoms()
                ];
            }
        }
        $boolError = !is_array($arControl);

        if (!$boolError)
        {
            $arSections = [];
            if (!empty($arOneCondition['SectionIds'])) {
                foreach (explode(',', $arOneCondition['SectionIds']) as $sectionId) {
                    $sectionId = intval(trim($sectionId));
                    if ($sectionId > 0 && !in_array($sectionId, $arSections)) {
                        $arSections[] = $sectionId;
                    }
                }
            }
            $actionParams = [
                'NUMBER' => (int)$arOneCondition['Number'],
                'SECTION_IDS' => $arSections,
            ];

            $mxResult = '\SaleActionCtrlProductNumberFree::applyDiscount('.$arParams['ORDER'].', '.var_export($actionParams, true).')';
            unset($actionParams);
        }

        return $mxResult;
    }

    public static function applyDiscount(array &$order, array $action)
    {
        if (intval($action['NUMBER']) <= 0) return;
        if (empty($order['BASKET_ITEMS'])) return;

        $num = intval($action['NUMBER']);

        \Bitrix\Main\Loader::includeModule('iblock');


        // Собрать все позиции в заказе
        $arItems = [];
        $arBasketItems = $order['BASKET_ITEMS'];
        $priceTotal = 0;
        foreach ($arBasketItems as $key => $arBasketItem) {

            // Проверка условия по разделу
            if (!empty($action['SECTION_IDS']) && is_array($action['SECTION_IDS'])) {
                $isFind = false;
                $arOffer = \CCatalogSku::GetProductInfo($arBasketItem['PRODUCT_ID']);
                $elemId = (is_array($arOffer) ? $arOffer['ID'] : $arBasketItem['PRODUCT_ID']);

                $isFind = \CIBlockElement::GetList(
                    [],
                    [
                        'ID' => $elemId,
                        'SECTION_ID' => $action['SECTION_IDS'],
                        'INCLUDE_SUBSECTIONS' => 'Y',
                    ],
                    false,
                    false,
                    ['ID']
                )->Fetch();

                if (!$isFind) {
                    unset($arBasketItems[$key]);
                    continue;
                }
            }

            for ($i = 1; $i <= $arBasketItem['QUANTITY']; $i++) {
                $arItems[] = [
                    'ID' => $arBasketItem['ID'],
                    'PRICE' => $arBasketItem['PRICE'],
                ];
                $priceTotal += $arBasketItem['PRICE'];
            }
        }

        if (empty($arItems) || count($arItems) < $num) return;
        if (count($arItems) < $num) return;

        // Сортировка по цене
        usort($arItems, fn($a, $b) => $a['PRICE'] <=> $b['PRICE']);
        $arItems = array_values($arItems);

        $priceFree = 0;
        $countFree = floor(count($arItems) / $num);

        for ($i = 0; $i < $countFree; $i++) {
            $priceFree += $arItems[$i]['PRICE'];
        }

        $percent = 0;
        if ($priceFree > 0 && $priceFree < $priceTotal) {
            $percent = (100 / $priceTotal) * $priceFree;
        }

        if ($percent > 0) {
            $order['BASKET_ITEMS'] = $arBasketItems;
            $basketAction = $action;
            $basketAction['UNIT'] = \Bitrix\Sale\Discount\Actions::VALUE_TYPE_PERCENT;
            $basketAction['VALUE'] = -1 * $percent;
            \Bitrix\Sale\Discount\Actions::applyToBasket($order, $basketAction, '');
        }

    }
}
