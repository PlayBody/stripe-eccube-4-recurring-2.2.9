<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\Customer;

/**
 * Customer
 *
 * @ORM\Table(name="plg_stripe_rec_customer")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\StripeCustomerRepository")
 */
class StripeCustomer
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var Customer
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Customer")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="customer_id", referencedColumnName="id")
     * })
     */
    private $Customer;

    /**
     * @var string
     *
     * @ORM\Column(name="stripe_customer_id", type="string")
     */
    private $stripe_customer_id;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime")
     */
    private $created_at;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set Customer.
     *
     * @param Customer $Customer
     *
     * @return $this
     */
    public function setCustomer(Customer $Customer)
    {
        $this->Customer = $Customer;

        return $this;
    }

    /**
     * Get Customer.
     *
     * @return Customer
     */
    public function getCustomer()
    {
        return $this->Customer;
    }


    /**
     * @return string
     */
    public function getStripeCustomerId()
    {
        return $this->stripe_customer_id;
    }

    /**
     * @param string $stripe_customer_id
     *
     * @return $this;
     */
    public function setStripeCustomerId($stripe_customer_id)
    {
        $this->stripe_customer_id = $stripe_customer_id;

        return $this;
    }

    
    /**
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param \DateTime $created_at
     *
     * @return $this;
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }
}
