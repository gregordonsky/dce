<?php
namespace T3\Dce\Components\FlexformToTcaMapper;

/*  | This extension is made for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2019 Armin Vieweg <armin@v.ieweg.de>
 */
use T3\Dce\Utility\DatabaseUtility;
use T3\Dce\Utility\FlashMessage;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class FlexformToTcaMapper
 * Get SQL for DCE fields which extend tt_content table.
 */
class Mapper
{
    /**
     * Returns SQL to add new fields in tt_content
     *
     * @return string SQL CREATE TABLE statement
     */
    public static function getSql() : string
    {
        $fields = [];
        foreach (static::getDceFieldMappings() as $fieldName => $fieldType) {
            $fields[] = $fieldName . ' ' . $fieldType;
        }
        if (!empty($fields)) {
            return 'CREATE TABLE tt_content (' . PHP_EOL . implode(',' . PHP_EOL, $fields) . PHP_EOL . ');';
        }
        return '';
    }

    /**
     * Returns all DceFields which introduce new columns to tt_content
     *
     * @return array of DceField rows or empty array
     */
    public static function getDceFieldRowsWithNewTcaColumns() : array
    {
        try {
            $rows = DatabaseUtility::getDatabaseConnection()->exec_SELECTgetRows(
                '*',
                'tx_dce_domain_model_dcefield',
                'map_to="*newcol" AND deleted=0 AND type=0 AND new_tca_field_name!="" AND new_tca_field_type!=""'
            );
        } catch (\Exception $exception) {
            return [];
        }

        if ($rows === null) {
            return [];
        }
        return $rows;
    }

    /**
     * Returns array with DceFields (in key) and their sql type (value)
     *
     * @return array
     */
    protected static function getDceFieldMappings() : array
    {
        $fieldMappings = [];
        foreach (static::getDceFieldRowsWithNewTcaColumns() as $dceFieldRow) {
            if ($dceFieldRow['new_tca_field_type'] === 'auto') {
                $fieldMappings[$dceFieldRow['new_tca_field_name']] = static::getAutoFieldType($dceFieldRow);
            } else {
                $fieldMappings[$dceFieldRow['new_tca_field_name']] = $dceFieldRow['new_tca_field_type'];
            }
        }
        return $fieldMappings;
    }

    /**
     * Determines database field type for given DceField, based on the defined field configuration.
     *
     * @param array $dceFieldRow
     * @return string Determined SQL type of given dceFieldRow
     */
    protected static function getAutoFieldType(array $dceFieldRow) : string
    {
        $fieldConfiguration = GeneralUtility::xml2array($dceFieldRow['configuration']);
        switch ($fieldConfiguration['type']) {
            case 'input':
                return 'varchar(255) DEFAULT \'\' NOT NULL';
            case 'check':
            case 'radio':
                return 'tinyint(4) unsigned DEFAULT \'0\' NOT NULL';
            case 'text':
            case 'select':
            case 'group':
            default:
                return 'text';
        }
    }

    /**
     * Check if DceFields has been mapped with TCA columns
     * and writes values to columns in database, if so.
     *
     * @param array $row
     * @param string $piFlexform
     * @return void
     * @throws \TYPO3\CMS\Core\Exception
     */
    public static function saveFlexformValuesToTca(array $row, $piFlexform) : void
    {
        $dceUid = DatabaseUtility::getDceUidByContentElementRow($row);
        $dceFieldsWithMapping = DatabaseUtility::getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'tx_dce_domain_model_dcefield',
            'parent_dce=' . $dceUid . ' AND map_to!="" AND deleted=0'
        );
        if (!isset($piFlexform) || empty($piFlexform) || \count($dceFieldsWithMapping) === 0) {
            return;
        }

        $flexFormArray = GeneralUtility::xml2array($piFlexform);
        if (!\is_array($flexFormArray)) {
            return;
        }

        /** @var array $fieldToTcaMappings */
        $fieldToTcaMappings = [];
        foreach ($dceFieldsWithMapping as $dceField) {
            $mapTo = $dceField['map_to'];
            if ($mapTo === '*newcol') {
                $mapTo = $dceField['new_tca_field_name'];
                if (empty($mapTo)) {
                    throw new \InvalidArgumentException('No "new_tca_field_name" given in DCE field configuration.');
                }
            }
            $fieldToTcaMappings[$dceField['variable']] = $mapTo;
        }

        $updateData = [];
        $flatFlexFormData = ArrayUtility::flatten($flexFormArray);
        foreach ($flatFlexFormData as $key => $value) {
            $fieldName = preg_replace('/.*settings\.(.*?)\.vDEF$/', '$1', $key);
            if (array_key_exists($fieldName, $fieldToTcaMappings)) {
                if (empty($updateData[$fieldToTcaMappings[$fieldName]])) {
                    $updateData[$fieldToTcaMappings[$fieldName]] = $value;
                } else {
                    $updateData[$fieldToTcaMappings[$fieldName]] .= PHP_EOL . PHP_EOL . $value;
                }
            }
        }

        if (!empty($updateData)) {
            $databaseColumns = DatabaseUtility::getDatabaseConnection()->admin_get_fields('tt_content');
            foreach (array_keys($updateData) as $columnName) {
                if (!array_key_exists($columnName, $databaseColumns)) {
                    throw new \InvalidArgumentException(
                        'It seems you have forgotten to perform a Database Schema update, ' .
                        'after you did changes to TCA mapping in DCE field.'
                    );
                }
            }
            $updateStatus = DatabaseUtility::getDatabaseConnection()->exec_UPDATEquery(
                'tt_content',
                'uid=' . $row['uid'],
                $updateData
            );
            if (!$updateStatus) {
                FlashMessage::add(
                    'Can\'t update tt_content item with uid ' . $row['uid'],
                    'Flexform to TCA mapping failure',
                    AbstractMessage::ERROR
                );
            }
        }
    }
}
