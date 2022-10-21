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

/**
 * Config
 *
 * @ORM\Table(name="plg_stripe_rec_session")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\PurchasePointRepository")
 * 
 * @ORM\Table(name="plg_stripe_purchase_point")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="discriminator_type", type="string", length=255)
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\PurchasePointRepository")
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class PurchasePoint extends \Eccube\Entity\Master\AbstractMasterEntity
{
    const POINT_ON_DATE = 'on_date';
    const POINT_NEXT_WEEK = 'next_week';
    const POINT_NEXT_MONTH = 'next_month';
    const POINT_NEXT_YEAR = 'next_year';
    const POINT_AFTER_DAYS = 'after_days';
    
    /**
     * @var string
     *
     * @ORM\Column(name="point", type="string", length=255)
     */
    protected $point;

    /**
     * @var boolean
     *
     * @ORM\Column(name="enabled", type="smallint", options={"default" : 0}, nullable=true)
     */
    private $enabled;
    

    public function getPoint(){
        return $this->point;
    }
    public function setPoint($point){
        $this->point = $point;
        return $this;
    }
    public function isEnabled(){
        return $this->enabled > 0;
    }
    public function setEnabled($enabled){
        $this->enabled = $enabled;
        return $this;
    }
}