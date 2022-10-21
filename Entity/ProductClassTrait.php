<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Entity;

use Eccube\Annotation\EntityExtension;
use Doctrine\ORM\Mapping as ORM;

use Eccube\Entity\ProductClass;

/**
 * @EntityExtension("Eccube\Entity\ProductClass")
 */
trait ProductClassTrait
{
    /**
     * @var string
     * @ORM\Column(name="stripe_price_id", type="string", length=255, nullable=true)
     */
    private $stripe_price_id;

    /**
     * @var string
     * @ORM\Column(name="_interval", type="string", length=255, nullable=true)
     */
    private $interval;

    /**
     * @var string
     * @ORM\Column(name="bundle_product", type="string", length=255, nullable=true)
     */
    private $bundle_product;

    /**
     * @var string
     *
     * @ORM\Column(name="initial_price", type="decimal", precision=12, scale=2, nullable=true, options={"default":0})
     */
    private $initial_price;

    /**
     * @var string
     *
     * @ORM\Column(name="first_cycle_free", type="boolean", nullable=true, options={"default":0})
     */
    private $first_cycle_free;

    /**
     * @var boolean
     *
     * @ORM\Column(name="bundle_required", type="boolean", options={"default":false})
     */
    private $bundle_required = false;


    private $initial_price_inc_tax;
    
    public function setInitialPrice($initial_price){
        $this->initial_price = $initial_price; 
        return $this;
    }
    public function getInitialPrice(){
        return $this->initial_price;
    }

    public function setFirstCycleFree($first_cycle_free){
        $this->first_cycle_free = $first_cycle_free;
        return $this;
    }
    public function getFirstCycleFree(){
        return $this->first_cycle_free;
    }

    public function setInitialPriceIncTax($initial_price_inc_tax){
        $this->initial_price_inc_tax = $initial_price_inc_tax;
        return $this;
    }
    public function getInitialPriceIncTax(){
        return $this->initial_price_inc_tax;
    }
    public function isInitialPriced(){
        if((int)$this->getFirstCycleFree()){
            return true;
        } else if(!empty((int)$this->getInitialPrice())){
            return $this->getInitialPrice() != $this->getPrice02();
        }else{
            return false;
        }
    }

    public function setBundleRequired($bundle_required){
        $this->bundle_required = $bundle_required;
        return $this;
    }
    public function isBundleRequired(){
        return $this->bundle_required;
    }

    public function setBundleProduct($bundle_product){
        $this->bundle_product = $bundle_product;
        return $this;
    }
    public function getBundleProduct(){
        return $this->bundle_product;
    }

    public function setStripePriceId($stripe_price_id){
        $this->stripe_price_id = $stripe_price_id;
        return $this;
    }
    public function getStripePriceId(){
        return $this->stripe_price_id;
    }

    public function setInterval($interval){
        $this->interval = $interval;
    }
    public function getInterval(){
        return $this->interval;
    }
    
    private $register_flg = 'none';
    
    public function getRegisterFlg(){
        if ($this->stripe_price_id) {            
            return $this->interval;
        }
        return $this->register_flg;
    }
    public function setRegisterFlg($register_flg){
        $this->register_flg = $register_flg;
        return $this;
    }

    public function isRegistered(){
        if ($this->stripe_price_id) {            
            return true;
        }
        return false;
    }
}