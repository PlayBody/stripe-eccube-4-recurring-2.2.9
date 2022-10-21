<?php

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * License
 *
 * @ORM\Table(name="plg_stripe_rec_shipping_product")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\StripeRecShippingProductRepository")
 */
class StripeRecShippingProduct
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
     * @var string
     * @ORM\Column(name="stripe_shipping_prod_id", type="string", length=255, nullable=true)
     */
    private $stripe_shipping_prod_id;


    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getStripeShippingProdId()
    {
        return $this->stripe_shipping_prod_id;
    }

    /**
     * @param string $stripe_shipping_prod_id
     *
     * @return $this;
     */
    public function setStripeShippingProdId($stripe_shipping_prod_id)
    {
        $this->stripe_shipping_prod_id = $stripe_shipping_prod_id;

        return $this;
    }
}
