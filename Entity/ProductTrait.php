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

use Eccube\Entity\Product;

/**
 * @EntityExtension("Eccube\Entity\Product")
 */

trait ProductTrait
{

    /**
     * @var string
     * @ORM\Column(name="stripe_prod_id", type="string", length=255, nullable=true)
     */
    private $stripe_prod_id;

    private $registerFlg; // for virtual
    private $price_change_flg; // for virtual
    private $bundle_product; // for virtual
    private $bundle_required = false; // for virtual
    private $initial_price;
    private $first_cycle_free;

    public function setInitialPrice($initial_price){
        $this->initial_price = $initial_price;
        return $this;
    }
    public function getInitialPrice(){
        $def_pc = $this->getDefaultClass();
        if($def_pc){
            return $def_pc->getInitialPrice();
        }else{
            return 0;
        }                
    }

    public function setFirstCycleFree($first_cycle_free){
        $this->first_cycle_free = $first_cycle_free;
        return $this;
    }
    public function getFirstCycleFree(){
        $def_pc = $this->getDefaultClass();
        if($def_pc){
            return $def_pc->getFirstCycleFree();
        }else{
            return 0;
        }
    }

    public function isBundleRequired(){
        $def_pc = $this->getDefaultClass();
        if($def_pc){
            return $def_pc->isBundleRequired();
        }else{
            return false;
        }
    }
    public function setBundleRequired($bundle_required){
        $this->bundle_required = $bundle_required;
        return $this;
    }
    
    public function setStripeProdId($stripe_prod_id){
        $this->stripe_prod_id = $stripe_prod_id;
    }
    public function getStripeProdId(){
        return $this->stripe_prod_id;
    }
    public function isStripeProduct(){
        return !empty($this->stripe_prod_id);
    }

    public function getDefaultClass(){
        
        foreach ($this->ProductClasses as $ProductClass) {
            if (!$ProductClass->isVisible()) {                
                continue;
            }
            if (is_null($ProductClass->getClassCategory1()) && is_null($ProductClass->getClassCategory2())) {                
                return $ProductClass;
            }
        }        return null;
    }

    // ===========virtual======
    public function getBundleProduct(){
        $def_pc = $this->getDefaultClass();
        if($def_pc){
            return $def_pc->getBundleProduct();
        }else{
            return "";
        }        
    }
    public function setBundleProduct($bundle_product){
        $this->bundle_product = $bundle_product;
        return $this;
    }

    public function getRegisterFlg(){
        $def_pc = $this->getDefaultClass();
        if($def_pc){
            return $def_pc->getRegisterflg();
        }else{
            return "None";
        }
    }
    public function setRegisterFlg($registerFlg){
        $this->registerFlg = $registerFlg;        
        return $this;
    }
    public function setPriceChangeFlg($price_change_flg)    {
        $this->price_change_flg = $price_change_flg;
        return $this;
    }
    public function getPriceChangeFlg(){
        return $this->price_change_flg;
    }
}