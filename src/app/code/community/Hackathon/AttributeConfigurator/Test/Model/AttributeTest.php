<?php

/**
 * Class Hackathon_AttributeConfigurator_Test_Model_AttributeTest
 *
 * @category Test
 * @package  Hackathon_AttributeConfigurator
 * @author   Firegento <contact@firegento.com>
 * @license  Open Software License v. 3.0 (OSL-3.0)
 * @link     https://github.com/magento-hackathon/AttributeConfigurator
 */
class Hackathon_AttributeConfigurator_Test_Model_AttributeTest extends EcomDev_PHPUnit_Test_Case
{
    /** @var Hackathon_AttributeConfigurator_Model_Attribute $_model */
    protected $_model;

    /**
     * Setup Method
     * @return void
     */
    protected function setUp()
    {
        $this->_model = Mage::getModel('hackathon_attributeconfigurator/attribute');
        parent::setUp();
    }

    /**
     * @test
     * @return void
     */
    public function insertAttributeThrowsExceptionIfIdExists()
    {
        /** @var Mage_Catalog_Model_Resource_Category_Attribute_Collection $attributes */
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection');
        $attributeCode = $attributes->getFirstItem()->getCode();
        if (!empty($attributes)) {
            $this->_model->insertAttribute(
                [
                    'data' => $attributeCode
                ]
            );
        }
    }
}
