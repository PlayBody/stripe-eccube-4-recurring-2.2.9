<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\Master\OrderItemType;
use Eccube\Entity\Master\RoundingType;
use Eccube\Entity\Master\TaxDisplayType;
use Eccube\Entity\AbstractEntity;
use Eccube\Entity\ItemInterface;
use Eccube\Entity\PointRateTrait;
/**
 * StripeRecOrderItem
 *
 * @ORM\Table(name="plg_stripe_rec_order_item") 
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\StripeRecOrderItemRepository")
 */
class StripeRecOrderItem
{
    

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="quantity", type="decimal", precision=10, scale=0, options={"default":0})
     */
    private $quantity = 0;
    
    /**
     * @var string
     *
     * @ORM\Column(name="tax", type="decimal", precision=10, scale=0, options={"default":0})
     */
    private $tax = 0;

    /**
     * @var string
     *
     * @ORM\Column(name="tax_rate", type="decimal", precision=10, scale=0, options={"unsigned":true,"default":0})
     */
    private $tax_rate = 0;

    /**
     * @var string
     *
     * @ORM\Column(name="tax_adjust", type="decimal", precision=10, scale=0, options={"unsigned":true,"default":0})
     */
    private $tax_adjust = 0;

    /**
     * @var int|null
     *
     * @ORM\Column(name="tax_rule_id", type="smallint", nullable=true, options={"unsigned":true})
     */
    private $tax_rule_id;

    /**
     * @var string|null
     *
     * @ORM\Column(name="currency_code", type="string", nullable=true)
     */
    private $currency_code;

    
    /**
     * @var \Plugin\StripeRec\Entity\StripeRecOrder
     *
     * @ORM\ManyToOne(targetEntity="Plugin\StripeRec\Entity\StripeRecOrder", inversedBy="OrderItems")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="rec_order_id", referencedColumnName="id")
     * })
     */
    private $recOrder;

    /**
     * @var \Eccube\Entity\Product
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Product")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="product_id", referencedColumnName="id")
     * })
     */
    private $Product;

    /**
     * @var \Eccube\Entity\ProductClass
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\ProductClass")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="product_class_id", referencedColumnName="id")
     * })
     */
    private $ProductClass;

    /**
     * @var \Eccube\Entity\Shipping
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Shipping", inversedBy="OrderItems")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="shipping_id", referencedColumnName="id")
     * })
     */
    private $Shipping;

    /**
     * @var string
     * @ORM\Column(name="paid_status", type = "text", nullable=true)
     */
    private $paid_status;

    /**
     * @var string
     *
     * @ORM\Column(name="initial_price", type="decimal", precision=12, scale=2, nullable=true, options={"default":0})
     */
    private $initial_price;

    /**
     * @var \Eccube\Entity\Master\TaxDisplayType
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Master\TaxDisplayType")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="tax_display_type_id", referencedColumnName="id")
     * })
     */
    private $TaxDisplayType;

    /**
     * @var \Eccube\Entity\Master\TaxType
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Master\TaxType")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="tax_type_id", referencedColumnName="id")
     * })
     */
    private $TaxType;

    

    /**
     * Set taxType
     *
     * @param \Eccube\Entity\Master\TaxType $taxType     
     */
    public function setTaxType(\Eccube\Entity\Master\TaxType $taxType = null)
    {
        $this->TaxType = $taxType;

        return $this;
    }

    /**
     * Get taxType
     *
     * @return \Eccube\Entity\Master\TaxType
     */
    public function getTaxType()
    {
        return $this->TaxType;
    }
    
    public function getPrice(){
        return $this->getProductClass()->getPrice02IncTax();
    }

    public function getTaxDisplayType(){
        return $this->TaxDisplayType;
    }
    public function setTaxDisplayType($TaxDisplayType){
        $this->TaxDisplayType = $TaxDisplayType;
        return $this;
    }


    public function getInitialPrice(){
        return $this->initial_price;
    }
    public function setInitialPrice($initial_price){
        $this->initial_price = $initial_price;
        return $this;
    }

    public function getPaidStatus(){
        return $this->paid_status;
    }
    public function setPaidStatus($paid_status){
        $this->paid_status = $paid_status;
        return $this;
    }


    public function copyOrderItem($order_item){
        $this->quantity = $order_item->getQuantity();
        $this->tax = $order_item->getTax();
        $this->tax_rate = $order_item->getTaxRate();
        $this->TaxType = $order_item->getTaxType();
        // $this->tax_adjust = $order_item->getTaxAdjust();
        // $this->tax_rule_id = $order_item->getTaxRuleId();
        $this->TaxDisplayType = $order_item->getTaxDisplayType();
        $this->currency_code = $order_item->getCurrencyCode();
        
        $this->Product = $order_item->getProduct();
        $this->ProductClass = $order_item->getProductClass();
        
        if($this->ProductClass->isInitialPriced()){
            $this->initial_price = $this->ProductClass->getInitialPriceIncTax();
        }else{
            $this->initial_price = $this->ProductClass->getPrice02IncTax();
        }
        return $this;
    }
    /**
     * Set quantity.
     *
     * @param string $quantity
     *
     * @return StripeRecOrderItem
     */
    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Get quantity.
     *
     * @return string
     */
    public function getQuantity()
    {
        return $this->quantity;
    }
    /**
     * @return string
     */
    public function getTax()
    {
        return $this->tax;
    }

    /**
     * @param string $tax
     *
     * @return $this
     */
    public function setTax($tax)
    {
        $this->tax = $tax;

        return $this;
    }

    
    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    

    
    /**
     * Set taxRate.
     *
     * @param string $taxRate
     *
     * @return StripeRecOrderItem
     */
    public function setTaxRate($taxRate)
    {
        $this->tax_rate = $taxRate;

        return $this;
    }

    /**
     * Get taxRate.
     *
     * @return string
     */
    public function getTaxRate()
    {
        return $this->tax_rate;
    }

    /**
     * Set taxAdjust.
     *
     * @param string $tax_adjust
     *
     * @return StripeRecOrderItem
     */
    public function setTaxAdjust($tax_adjust)
    {
        $this->tax_adjust = $tax_adjust;

        return $this;
    }

    /**
     * Get taxAdjust.
     *
     * @return string
     */
    public function getTaxAdjust()
    {
        return $this->tax_adjust;
    }

    /**
     * Set taxRuleId.
     * @deprecated 税率設定は受注作成時に決定するため廃止予定
     *
     * @param int|null $taxRuleId
     *
     * @return StripeRecOrderItem
     */
    public function setTaxRuleId($taxRuleId = null)
    {
        $this->tax_rule_id = $taxRuleId;

        return $this;
    }

    /**
     * Get taxRuleId.
     * @deprecated 税率設定は受注作成時に決定するため廃止予定
     *
     * @return int|null
     */
    public function getTaxRuleId()
    {
        return $this->tax_rule_id;
    }

    /**
     * Get currencyCode.
     *
     * @return string
     */
    public function getCurrencyCode()
    {
        return $this->currency_code;
    }

    /**
     * Set currencyCode.
     *
     * @param string|null $currencyCode
     *
     * @return StripeRecOrderItem
     */
    public function setCurrencyCode($currencyCode = null)
    {
        $this->currency_code = $currencyCode;

        return $this;
    }

    
    /**
     * Set order.
     *
     * @param \Plugin\StripeRec\Entity\StripeRecOrder|null $recOrder
     *
     * @return StripeRecOrderItem
     */
    public function setRecOrder(\Plugin\StripeRec\Entity\StripeRecOrder $recOrder = null)
    {
        $this->recOrder = $recOrder;
        return $this;
    }

    /**
     * Get order.
     *
     * @return \Plugin\StripeRec\Entity\StripeRecOrder|null
     */
    public function getRecOrder()
    {
        return $this->recOrder;
    }

    public function getOrderId()
    {
        if (is_object($this->getOrder())) {
            return $this->getOrder()->getId();
        }

        return null;
    }

    /**
     * Set product.
     *
     * @param \Eccube\Entity\Product|null $product
     *
     * @return StripeRecOrderItem
     */
    public function setProduct(\Eccube\Entity\Product $product = null)
    {
        $this->Product = $product;

        return $this;
    }

    /**
     * Get product.
     *
     * @return \Eccube\Entity\Product|null
     */
    public function getProduct()
    {
        return $this->Product;
    }

    /**
     * Set productClass.
     *
     * @param \Eccube\Entity\ProductClass|null $productClass
     *
     * @return StripeRecOrderItem
     */
    public function setProductClass(\Eccube\Entity\ProductClass $productClass = null)
    {
        $this->ProductClass = $productClass;

        return $this;
    }

    /**
     * Get productClass.
     *
     * @return \Eccube\Entity\ProductClass|null
     */
    public function getProductClass()
    {
        return $this->ProductClass;
    }

    /**
     * Set shipping.
     *
     * @param \Eccube\Entity\Shipping|null $shipping
     *
     * @return StripeRecOrderItem
     */
    public function setShipping(\Eccube\Entity\Shipping $shipping = null)
    {
        $this->Shipping = $shipping;

        return $this;
    }

    /**
     * Get shipping.
     *
     * @return \Eccube\Entity\Shipping|null
     */
    public function getShipping()
    {
        return $this->Shipping;
    }
    
}

