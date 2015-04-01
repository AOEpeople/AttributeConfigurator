<?php

/**
 * Class Aoe_AttributeConfigurator_Model_Attribute
 *
 * @category Model
 * @package  Aoe_AttributeConfigurator
 * @author   Firegento <contact@firegento.com>
 * @author   AOE Magento Team <team-magento@aoe.com>
 * @license  Open Software License v. 3.0 (OSL-3.0)
 * @link     https://github.com/AOEpeople/AttributeConfigurator
 * @see      https://github.com/magento-hackathon/AttributeConfigurator
 */
class Aoe_AttributeConfigurator_Model_Attribute extends Mage_Eav_Model_Entity_Attribute
{
    /** @var Aoe_AttributeConfigurator_Helper_Data $_helper */
    protected $_helper;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_helper = Mage::helper('aoe_attributeconfigurator/data');
        parent::_construct();
    }

    /**
     * Converts existing Attribute to different type
     *
     * @param  string $attributeCode Attribute Code
     * @param  int    $entityType    Entity Type which Attribute is attached to
     * @param  array  $data          New Attribute Data
     * @return void
     */
    public function convertAttribute($attributeCode, $entityType, $data = null)
    {
        $_dbConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
        /* @var $attribute Mage_Eav_Model_Entity_Attribute */
        $attribute = $this->loadByCode($entityType, $attributeCode);
        // Stop if $data not set or Attribute not available or Attribute not maintained by module
        $this->_helper->checkAttributeMaintained($attribute);
        if ($data === null || !$attribute || !$this->_helper->checkAttributeMaintained($attribute)) {
            return;
        }
        // Migrate existing Attribute Values if new backend_type different from old one
        if ($attribute->getBackendType() !== $data['backend_type']) {
            $this->migrateData($attribute, $data);
        }
        /*
         * @TODO: jadhub, muss hier noch eventuell vorhandene Select/Multiselect-Values löschen falls der neue BackendType ein anderer ist
         */
        // Actual Conversion of Attribute
        $sql = 'UPDATE eav_attribute SET attribute_model=?, backend_model=?, backend_type=?, backend_table=?, frontend_model=?, frontend_input=?, frontend_label=?, frontend_class=?, source_model=?, is_required=?, is_user_defined=?, default_value=?, is_unique=?, note=? WHERE attribute_id=?';
        try{
            $_dbConnection->query(
                $sql,
                [
                    $data['attribute_model'],
                    $data['backend_model'],
                    $data['backend_type'],
                    $data['backend_table'],
                    $data['frontend_model'],
                    $data['frontend_input'],
                    $data['frontend_label'],
                    $data['frontend_class'],
                    $data['source_model'],
                    $data['is_required'],
                    $data['is_user_defined'],
                    $data['default_value'],
                    $data['is_unique'],
                    $data['note'],
                    $attribute->getId()
                ]
            );
        }catch(Exception $e){
            Mage::exception(__CLASS__.' - '.__LINE__.':'.$e->getMessage());
        }
        // If entity of catalog_product, also update catalog_eav_attribute
        if ($attribute->getEntity()->getData('entity_type_code') === Mage_Catalog_Model_Product::ENTITY) {
            $sql = 'UPDATE catalog_eav_attribute SET frontend_input_renderer=?, is_global, is_visible=?, is_searchable=?, is_filterable=?, is_comparable=?, is_visible_on_front=?, is_html_allowed_on_front=?, is_used_for_price_rules=?, is_filterable_in_search=?, used_in_product_listing=?, used_for_sort_by=?, is_configurable=?, apply_to=?, is_visible_in_advanced_search=?, position=?, is_wysiwyg_enabled=?, is_used_for_promo_rules=?';
            try{
                $_dbConnection->query(
                    $sql,
                    [
                        $data['frontend_input_renderer'],
                        $data['is_global'],
                        $data['is_visible'],
                        $data['is_searchable'],
                        $data['is_filterable'],
                        $data['is_comparable'],
                        $data['is_visible_on_front'],
                        $data['is_html_allowed_on_front'],
                        $data['is_used_for_price_rules'],
                        $data['is_filterable_in_search'],
                        $data['used_in_product_listing'],
                        $data['used_for_sort_by'],
                        $data['is_configurable'],
                        $data['apply_to'],
                        $data['is_visible_in_advanced_search'],
                        $data['position'],
                        $data['is_wysiwyg_enabled'],
                        $data['is_used_for_promo_rules'],
                    ]
                );
            }catch(Exception $e){
                Mage::exception(__CLASS__.' - '.__LINE__.':'.$e->getMessage());
            }
        }
    }

    /**
     * Migrate Entries from source to target tables (if possible)
     *
     * @param  Mage_Eav_Model_Entity_Attribute $attribute Attribute Model
     * @param  array                           $data      Attribute Data
     * @return void
     */
    private function migrateData($attribute, $data = null)
    {
        if ($data === null) {
            return;
        }
        $_dbConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
        // e.g. Entity is 'catalog_product'
        $entityTypeCode = $attribute->getEntity()->getData('entity_type_code');
        // Set Backend Types for later reference
        $sourceType = $attribute->getBackendType();
        $targetType = $data['backend_type'];
        // Create complete Entity Table names, e.g. 'catalog_product_entity_text'
        $sourceTable = implode([$entityTypeCode, 'entity', $sourceType], '_');
        $targetTable = implode([$entityTypeCode, 'entity', $targetType], '_');
        // Select all existing entries for given Attribute
        $srcSql = 'SELECT * FROM '.$sourceTable.' WHERE attribute_id = ? AND entity_type_id = ?';
        $sourceQuery = $_dbConnection->query(
            $srcSql,
            [
                $attribute->getId(),
                $attribute->getEntity()->getData('entity_type_id')
            ]
        );
        while ($row = $sourceQuery->fetch()) {
            $currentValue = $row['value'];
            if (!is_null($currentValue)) {
                // Cast Value Type to new Type (e.g. decimal to text)
                $targetValue = $this->typeCast($currentValue, $sourceType, $targetType);
                // Insert Value to target Entity
                $sql = 'INSERT INTO '.$targetTable.' (entity_type_id, attribute_id, store_id, entity_id, value) VALUES (?,?,?,?,?)';
                try{
                    $_dbConnection->query(
                        $sql,
                        [
                            $row['entity_type_id'],
                            $row['attribute_id'],
                            $row['store_id'],
                            $row['entity_id'],
                            $targetValue
                        ]
                    );
                }catch(Exception $e){
                    Mage::exception(__CLASS__.' - '.__LINE__.':'.$e->getMessage());
                }
            }
            // Delete Value from source Entity
            $sql = 'DELETE FROM '.$sourceTable.' WHERE value_id = ?';
            $_dbConnection->query($sql, $row['value_id']);
        }
    }

    /**
     * Force Casting of Backend Types
     *
     * @param mixed  $value      Current Value
     * @param string $sourceType Current Source Type
     * @param string $targetType New Source Type
     * @return null
     */
    private function typeCast($value, $sourceType, $targetType)
    {
        if ($sourceType === $targetType) {
            return $value;
        }
        switch ($targetType) {
            case 'decimal':
                return min((int) $value, 2147483648);
            case 'gallery':
                return $this->truncateString((string) $value, 254);
            case 'group_price':
                return min((int) $value, 65535);
            case 'int':
                return min((int) $value, 2147483648);
            case 'media_gallery':
                return $this->truncateString((string) $value, 254);
            case 'media_gallery_value':
                return min((int) $value, 65535);
            case 'text':
                return (string) $value;
            case 'tier_price':
                return min((int) $value, 65535);
            case 'url_key':
                return $this->truncateString((string) $value, 254);
            case 'varchar':
                return $this->truncateString((string) $value, 254);
        }
        return null;
    }

    /**
     * Truncate string if too long
     *
     * @param  string  $str    Input String
     * @param  integer $maxlen Maximum String Length
     * @return string
     */
    public static function truncateString($str, $maxlen)
    {
        if (strlen($str) <= $maxlen) {
            return $str;
        }
        return substr($str, 0, $maxlen);
    }

    /**
     * Insert new Attribute
     *
     * @TODO: nhp_havocologe, this needs to set is_maintained_by_configurator to the attribute
     *
     * @param  array $data Attribute Configuration Data
     * @return void
     * @throws Aoe_AttributeConfigurator_Model_Exception
     */
    public function insertAttribute($data)
    {
        $this->_validateImportData($data);

        /** @var Mage_Catalog_Model_Entity_Attribute $attribute */
        $attribute = $this->_loadAttributeByCode($data['code']);

        if ($attribute->getId()) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf('Attribute with code \'%s\' already exists.', $data['code'])
            );
        }


        $newData = [];
        foreach ($data as $node => $value) {
            $newData[$node] = $value;
        }
        $attribute->addData($newData);
        $attribute->save();

        $setup = Mage::getModel('eav/entity_setup');
        foreach ($data['attribute_set'] as $key => $set) {
            // TODO: Load is not performant in Loop
            // @codingStandardsIgnoreStart
            $attributeSetId = Mage::getModel('eav/entity_attribute_set')
                            ->load($set, 'attribute_set_name')
                            ->getAttributeSetId();
            // @codingStandardsIgnoreEnd
            $setup->addAttributeGroup(
                $data['entity_type_id'],
                $attributeSetId,
                $data['group']
            );
            $setup->addAttributeToSet(
                $data['entity_type_id'],
                $attributeSetId, $data['group'],
                $data['attribute_code'],
                $data['sort_order']
            );
        }
    }

    /**
     * Load a catalog entity attribute by its code
     *
     * @param string $attributeCode Attribute code
     * @return Mage_Catalog_Model_Entity_Attribute
     */
    protected function _loadAttributeByCode($attributeCode)
    {
        /** @var Mage_Catalog_Model_Entity_Attribute $result */
        $result = Mage::getModel('catalog/entity_attribute');

        $result->loadByCode(
            'catalog_product',
            $attributeCode
        );

        return $result;
    }

    /**
     * Validate the attribute import data.
     * Throws exception on validation errors
     *
     * @param array $importData Array of import data to set up attributes
     * @return void
     * @throws Aoe_AttributeConfigurator_Model_Exception
     */
    protected function _validateImportData($importData)
    {
        if (!isset($importData['code']) || !trim($importData['code'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                'Data validation: no code set on attribute data array.'
            );
        }

        $attributeCode = $importData['code'];

        if (!isset($importData['settings'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no \'settings\' section.',
                    $attributeCode
                )
            );
        }

        /** @var array $setting */
        $setting = $importData['settings'];
        if (!isset($setting['frontend_label']) || !trim($setting['frontend_label'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no frontend label.',
                    $attributeCode
                )
            );
        }

        if (!isset($importData['attribute_set']) || !is_array($importData['attribute_set'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no attribute set config.',
                    $attributeCode
                )
            );
        }

        if (!isset($importData['entity_type_id']) || !trim($importData['entity_type_id'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no entity type id.',
                    $attributeCode
                )
            );
        }

        if (!isset($importData['group']) || !trim($importData['group'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no group.',
                    $attributeCode
                )
            );
        }

        if (!isset($importData['attribute_code']) || !trim($importData['attribute_code'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no attribute code.',
                    $attributeCode
                )
            );
        }

        if (!isset($importData['sort_order']) || !is_numeric($importData['sort_order'])) {
            throw new Aoe_AttributeConfigurator_Model_Exception(
                sprintf(
                    'Data validation: attribute data for \'%s\' contains no sort order.',
                    $attributeCode
                )
            );
        }
    }
}
