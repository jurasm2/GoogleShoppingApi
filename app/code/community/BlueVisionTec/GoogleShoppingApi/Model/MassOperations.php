<?php
/**
 * @category	BlueVisionTec
 * @package     BlueVisionTec_GoogleShoppingApi
 * @copyright   Copyright (c) 2012 Magento Inc. (http://www.magentocommerce.com)
 * @copyright   Copyright (c) 2015 BlueVisionTec UG (haftungsbeschränkt) (http://www.bluevisiontec.de)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Controller for mass opertions with items
 *
 * @category	BlueVisionTec
 * @package    BlueVisionTec_GoogleShoppingApi
 * @author     Magento Core Team <core@magentocommerce.com>
 * @author      BlueVisionTec UG (haftungsbeschränkt) <magedev@bluevisiontec.eu>
 */
class BlueVisionTec_GoogleShoppingApi_Model_MassOperations
{
    /**
     * Zend_Db_Statement_Exception code for "Duplicate unique index" error
     *
     * @var int
     */
    const ERROR_CODE_SQL_UNIQUE_INDEX = 23000;

    /**
     * Whether general error information were added
     *
     * @var bool
     */
    protected $_hasError = false;

    /**
     * Process locking flag
     *
     * @var BlueVisionTec_GoogleShoppingApi_Model_Flag
     */
    protected $_flag;

    /**
     * Set process locking flag.
     *
     * @param BlueVisionTec_GoogleShoppingApi_Model_Flag $flag
     * @return BlueVisionTec_GoogleShoppingApi_Model_MassOperations
     */
    public function setFlag(BlueVisionTec_GoogleShoppingApi_Model_Flag $flag)
    {
        $this->_flag = $flag;
        return $this;
    }

    /**
     * Add product to Google Content.
     *
     * @param array $productIds
     * @param int $storeId
     * 
     * @throws Mage_Core_Exception
     * @return BlueVisionTec_GoogleShoppingApi_Model_MassOperations
     */
    public function addProducts($productIds, $storeId)
    {
        Mage::log("storeid".$storeId);
        $this->_getLogger()->setStoreId($storeId);
        
        $totalAdded = 0;
        $errors = array();
        if (is_array($productIds)) {
            foreach ($productIds as $productId) {
                if ($this->_flag && $this->_flag->isExpired()) {
                    break;
                }
                try {
                    $product = Mage::getModel('catalog/product')
                        ->setStoreId($storeId)
                        ->load($productId);

                    if ($product->getId()) {
                        Mage::getModel('googleshoppingapi/item')
                            ->insertItem($product)
                            ->save();
                        // The product was added successfully
                        $totalAdded++;
                    } 
                } catch (Mage_Core_Exception $e) {
                    $errors[] = Mage::helper('googleshoppingapi')->__('The product "%s" cannot be added to Google Content. %s', $product->getName(), $e->getMessage());
                } catch (Exception $e) {
                    Mage::logException($e);
                    $errors[] = Mage::helper('googleshoppingapi')->__('The product "%s" hasn\'t been added to Google Content.', $product->getName());
                    $errors[] = $e->getMessage();
                }
            }
            if (empty($productIds)) {
                return $this;
            }
        }

        if ($totalAdded > 0) {
            $this->_getLogger()->addSuccess(
                Mage::helper('googleshoppingapi')->__('Products were added to Google Shopping account.'),
                Mage::helper('googleshoppingapi')->__('Total of %d product(s) have been added to Google Content.', $totalAdded)
            );
        }

        if (count($errors)) {
            $this->_getLogger()->addMajor(
                Mage::helper('googleshoppingapi')->__('Errors happened while adding products to Google Shopping.'),
                $errors
            );
        }

        if ($this->_flag->isExpired()) {
            $this->_getLogger()->addMajor(
                Mage::helper('googleshoppingapi')->__('Operation of adding products to Google Shopping expired.'),
                Mage::helper('googleshoppingapi')->__('Some products may have not been added to Google Shopping bacause of expiration')
            );
        }

        return $this;
    }

    /**
     * Update Google Content items.
     *
     * @param array|BlueVisionTec_GoogleShoppingApi_Model_Resource_Item_Collection $items
     *
     * @throws Mage_Core_Exception
     * @return BlueVisionTec_GoogleShoppingApi_Model_MassOperations
     */
    public function synchronizeItems($items)
    {
        $totalUpdated = 0;
        $totalDeleted = 0;
        $totalFailed = 0;
        $errors = array();

        $itemsCollection = $this->_getItemsCollection($items);

        if ($itemsCollection) {
            if (count($itemsCollection) < 1) {
                return $this;
            }
            foreach ($itemsCollection as $item) {
                if ($this->_flag && $this->_flag->isExpired()) {
                    break;
                }
                $this->_getLogger()->setStoreId($item->getStoreId());
                $removeInactive = $this->_getConfig()->getConfigData('autoremove_disabled',$item->getStoreId());
				$renewNotListed = $this->_getConfig()->getConfigData('autorenew_notlisted',$item->getStoreId());
                try {
					if($removeInactive && ($item->getProduct()->isDisabled() || !$item->getProduct()->getStockItem()->getIsInStock() )) {
						$item->deleteItem();
						$item->delete();
						$totalDeleted++;
						Mage::log("remove inactive: ".$item->getProduct()->getSku()." - ".$item->getProduct()->getName());
					} else {
						$item->updateItem();
						$item->save();
						// The item was updated successfully
						$totalUpdated++;
					}
                } catch (Mage_Core_Exception $e) {
                    $errors[] = Mage::helper('googleshoppingapi')->__('The item "%s" cannot be updated at Google Content. %s', $item->getProduct()->getName(), $e->getMessage());
                    $totalFailed++;
                } catch (Exception $e) {
                    Mage::logException($e);
                    $errors[] = Mage::helper('googleshoppingapi')->__('The item "%s" hasn\'t been updated.', $item->getProduct()->getName());
                    $errors[] = $e->getMessage();
                    $totalFailed++;
                }
            }
        } else {
            return $this;
        }
        if($totalDeleted > 0 || $totalUpdated > 0) {
            $this->_getLogger()->addSuccess(
                Mage::helper('googleshoppingapi')->__('Product synchronization with Google Shopping completed') . "\n"
                . Mage::helper('googleshoppingapi')->__('Total of %d items(s) have been deleted; total of %d items(s) have been updated.', $totalDeleted, $totalUpdated)
            );
        }
        if ($totalFailed > 0 || count($errors)) {
            array_unshift($errors, Mage::helper('googleshoppingapi')->__("Cannot update %s items.", $totalFailed));
            $this->_getLogger()->addMajor(
                Mage::helper('googleshoppingapi')->__('Errors happened during synchronization with Google Shopping'),
                $errors
            );
        }

        return $this;
    }

    /**
     * Remove Google Content items.
     *
     * @param array|BlueVisionTec_GoogleShoppingApi_Model_Resource_Item_Collection $items
     * @return BlueVisionTec_GoogleShoppingApi_Model_MassOperations
     */
    public function deleteItems($items)
    {
        $totalDeleted = 0;
        $itemsCollection = $this->_getItemsCollection($items);
        $errors = array();
        if ($itemsCollection) {
            if (count($itemsCollection) < 1) {
                return $this;
            }
            foreach ($itemsCollection as $item) {
                if ($this->_flag && $this->_flag->isExpired()) {
                    break;
                }
                $this->_getLogger()->setStoreId($item->getStoreId());
                try {
                    $item->deleteItem()->delete();
                    // The item was removed successfully
                    $totalDeleted++;
                } catch (Exception $e) {
                    
                    if($e->getCode() == 404){
						$item->delete();
						$this->_getLogger()->addNotice(
							Mage::helper('googleshoppingapi')->__(
								'The item "%s" was not found on GoogleContent',
								$item->getProduct()->getName()
							)
						);
						$totalDeleted++;
                    } else {
						Mage::logException($e);
						$errors[] = Mage::helper('googleshoppingapi')->__('The item "%s" hasn\'t been deleted.', $item->getProduct()->getName());
                    }
                }
            }
        } else {
            return $this;
        }

        if ($totalDeleted > 0) {
            $this->_getLogger()->addSuccess(
                Mage::helper('googleshoppingapi')->__('Google Shopping item removal process succeded'),
                Mage::helper('googleshoppingapi')->__('Total of %d items(s) have been removed from Google Shopping.', $totalDeleted)
            );
        }
        if (count($errors)) {
            $this->_getLogger()->addMajor(
                Mage::helper('googleshoppingapi')->__('Errors happened while deleting items from Google Shopping'),
                $errors
            );
        }

        return $this;
    }

    /**
     * Return items collection by IDs
     *
     * @param array|BlueVisionTec_GoogleShoppingApi_Model_Resource_Item_Collection $items
     * @throws Mage_Core_Exception
     * @return null|BlueVisionTec_GoogleShoppingApi_Model_Resource_Item_Collection
     */
    protected function _getItemsCollection($items)
    {
        $itemsCollection = null;
        if ($items instanceof BlueVisionTec_GoogleShoppingApi_Model_Resource_Item_Collection) {
            $itemsCollection = $items;
        } else if (is_array($items)) {
            $itemsCollection = Mage::getResourceModel('googleshoppingapi/item_collection')
                ->addFieldToFilter('item_id', $items);
        }

        return $itemsCollection;
    }

    /**
     * Retrieve adminhtml session model object
     *
     * @return Mage_Adminhtml_Model_Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('adminhtml/session');
    }
    
    /**
     * Retrieve logger
     *
     * @return BlueVisionTec_GoogleShoppingApi_Model_Log
     */
    protected function _getLogger()
    {
        return Mage::getSingleton('googleshoppingapi/log');
    }

    /**
     * Provides general error information
     */
    protected function _addGeneralError()
    {
        if (!$this->_hasError) {
            $this->_getLogger()->addMajor(
                Mage::helper('googleshoppingapi')->__('Google Shopping Error'),
                Mage::helper('googleshoppingapi/category')->getMessage()
            );
            $this->_hasError = true;
        }
    }
    
    /**
     * Get Google Shopping config model
     *
     * @return BlueVisionTec_GoogleShoppingApi_Model_Config
     */
    protected function _getConfig()
    {
        return Mage::getSingleton('googleshoppingapi/config');
    }
}
