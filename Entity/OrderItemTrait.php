<?php
/*
* Plugin Name : StripePaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/


namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;
use Eccube\Entity\OrderItem;
use Plugin\StripeRec\Entity\StripeRecOrder;
/**
 * @EntityExtension("Eccube\Entity\OrderItem")
 */
trait OrderItemTrait
{
    public function copy(OrderItem $OrderItem)
    {
        $this->product_name = $OrderItem->getProductName();
        $this->product_code = $OrderItem->getProductCode();
        $this->class_name1 = $OrderItem->getClassName1();
        $this->class_name2 = $OrderItem->getClassName2();
        $this->class_category_name1 = $OrderItem->getClassCategoryName1();
        $this->class_category_name2 = $OrderItem->getClassCategoryName2();
        $this->price = $OrderItem->getPrice();
        $this->quantity = $OrderItem->getQuantity();
        $this->tax = $OrderItem->getTax();
        $this->tax_rate = $OrderItem->getTaxRate();
        $this->tax_adjust = $OrderItem->getTaxAdjust();
        $this->tax_rule_id = $OrderItem->getTaxRuleId();
        $this->currency_code = $OrderItem->getCurrencyCode();
        $this->processor_name = $OrderItem->getProcessorName();
        $this->Product = $OrderItem->getProduct();
        $this->ProductClass = $OrderItem->getProductClass();
        $this->Shipping = $OrderItem->getShipping();
        $this->RoundingType = $OrderItem->getRoundingType();
        $this->TaxType = $OrderItem->getTaxType();
        $this->TaxDisplayType = $OrderItem->getTaxDisplayType();
        $this->OrderItemType = $OrderItem->getOrderItemType();
    }
}
