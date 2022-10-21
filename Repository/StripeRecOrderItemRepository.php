<?php

namespace Plugin\StripeRec\Repository;

use Eccube\Repository\AbstractRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Eccube\Repository\ProductClassRepository;
use Plugin\StripeRec\Entity\StripeRecOrderItem;

class StripeRecOrderItemRepository extends AbstractRepository{
    
    private $pc_repo;

    public function __construct(
        RegistryInterface $registry,
        ProductClassRepository $pc_repo
    ){
        parent::__construct($registry, StripeRecOrderItem::class);
        $this->pc_repo = $pc_repo;
    }
    public function getByPriceId($price_id){
        $pc = $this->pc_repo->findOneBy(['stripe_price_id'  => $price_id ]);
        if(empty($pc)){
            return null;
        }
        return $this->findBy(['ProductClass'   =>  $pc]);
    }
    public function getByOrderAndPriceId($rec_order, $price_id){
        $pc = $this->pc_repo->findOneBy(['stripe_price_id'  => $price_id ]);
        if(empty($pc)){
            log_info("StripeRecItemRepository---$price_id is empty");
            return null;
        }
        log_info("StripeRecItemRepository---".$rec_order->getId() . "---" . $pc->getId());
        return $this->findOneBy(['recOrder' =>  $rec_order, 'ProductClass'  =>  $pc]);
    }
}