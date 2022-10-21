<?php

/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\StripeRec\Entity\PurchasePoint;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Eccube\Entity\ProductClass;
use Eccube\Entity\Order;
use Eccube\Entity\OrderItem;

class PointBundleService{

    private $container;
    private $entityManager;

    public function __construct(ContainerInterface $container){
        $this->container = $container;        
        $this->entityManager = $this->container->get('doctrine.orm.entity_manager');
    }

    // public function createScheduleBySession($order, $session){
    //     log_info(__FUNCTION__ . "---" . __LINE__);
    //     log_info(__FUNCTION__ . "---session_id : {$session->id}, order_id : {$order->getId()}");

    //     $checkout_session = $this->entityManager->getRepository(CheckoutSession::class)->findOneBy(['session_id' => $session->id]);
    //     if(empty($checkout_session)){
    //         return;
    //     }
    //     $meta = $checkout_session->getMeta();    
    //     $meta = unserialize($meta);
    //     $purchase_point = $meta['purchase_point'];
    //     $bundle_include_arr = $meta['bundle_include_arr'];        

    //     $order_items = $order->getProductOrderItems();
    //     $subscription_items_pre = [];
    //     $subscription_items = [];
    //     $subscription_items_initial = [];
    //     $subscription_items_initial_pre = [];
        
    //     foreach($order_items as $order_item){
    //         $pc = $order_item->getProductClass();

    //         if(empty($pc) || !$pc->isRegistered()){
    //             return false;
    //         }
    //         if($pc->isInitialPriced()){
    //             if(empty( $subscription_items_initial_pre[$pc->getStripePriceId()])){
    //                 $subscription_items_initial_pre[$pc->getStripePriceId()] = [
    //                     'price_data'=>  [
    //                         'currency'  => 'jpy',
    //                         'product'   =>  $pc->getProduct()->getStripeProdId(),
    //                         'recurring' =>  [
    //                             'interval'  =>  $pc->getInterval()
    //                         ],
    //                         'unit_amount'   =>  $pc->getInitialPriceIncTax(),
    //                     ],
    //                     'quantity'  =>  $order_item->getQuantity()
    //                 ];
    //             }else{
    //                 $subscription_items_initial_pre[$pc->getStripePriceId()]['quantity'] += $order_item->getQuantity();
    //             }
    //         }
    //         if(empty( $subscription_items_pre[$pc->getStripePriceId()])){
    //             $subscription_items_pre[$pc->getStripePriceId()] = [
    //                 'price' => $pc->getStripePriceId(),
    //                 'quantity'  =>  $order_item->getQuantity()
    //             ];
    //         }else{
    //             $subscription_items_pre[$pc->getStripePriceId()]['quantity'] += $order_item->getQuantity();
    //         }
    //     }
    //     foreach($subscription_items_pre as $item){
    //         $subscription_items[] = $item;
    //     }
    //     if(!empty($subscription_items_initial_pre)){
    //         foreach($subscription_items_pre as $key => $item){
    //             if(isset($subscription_items_initial_pre[$key] )){
    //                 $subscription_items_initial[] = $subscription_items_initial_pre[$key];
    //             }else{
    //                 $subscription_items_initial[] = $item;                    
    //             }
    //         }
    //     }
        
    //     $bundles = $this->getBundleProducts($order, $bundle_include_arr);
        
    //     if(empty($bundles)){            
    //         if(empty($subscription_items_initial_pre)){                
    //             $phases = [
    //                 [
    //                     'items' =>  $subscription_items,
    //                     'proration_behavior' => 'none',
    //                 ],
    //             ];
    //         }else{

    //             $phases = [
    //                 [
    //                     'items'     => $subscription_items_initial,
    //                     'iterations'=> 1,
    //                     'proration_behavior' => 'none',      
    //                 ],
    //                 [
    //                     'items' =>  $subscription_items,
    //                     'proration_behavior' => 'none',
    //                 ],
    //             ];
    //         }
    //     }else{            
    //         $items = [];
    //         if(empty($subscription_items_initial_pre)){                
    //             $items = array_merge($subscription_items, $bundles['phase_items']);
    //         }else{
    //             $items = array_merge($subscription_items_initial, $bundles['phase_items']);
    //         }
    //         $phases = [
    //             [
    //                 'items'     => $items,
    //                 'iterations'=> 1,
    //                 'proration_behavior' => 'none',
    //             ],
    //             [
    //                 'items'     =>  $subscription_items,
    //                 'proration_behavior' => 'none',
    //             ]
    //         ];
    //     }

    //     log_info(__FUNCTION__ . "---" . __LINE__);
    //     log_info($purchase_point);

    //     $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
    //     $subscription_schedule = $stripe_service->createSubsctiptionSchedule([
    //         'customer'      =>  $session->customer,
    //         'start_date'    =>  $purchase_point,
    //         'end_behavior' =>  'release',
    //         'phases'        =>  $phases
    //     ]);
    //     log_info("--- subscription schedule created.");
    //     log_info($subscription_schedule);
    //     $subscription_id = $subscription_schedule->subscription;
    //     $rec_order = $order->getRecOrder();
    //     $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED);
    //     $rec_order->setScheduleId($subscription_schedule->id);
    //     if($subscription_id){
    //         $rec_order->setSubscriptionId($subscription_id);
    //     }
    //     $dt = new \DateTime();
    //     if($purchase_point === "now"){
    //         if($subscription_schedule->current_phase){
    //             $dt->setTimestamp($subscription_schedule->current_phase->start_date);
    //         }
    //     }else{
    //         $dt->setTimestamp($purchase_point);
    //     }
    //     $rec_order->setStartDate($dt);
    //     if(!empty($bundles)){
    //         $rec_order->setBundling($bundles['str']);
    //     }
    //     $rec_order->setStripeCustomerId($session->customer);

    //     $this->entityManager->persist($rec_order);
    //     $this->entityManager->flush();        
    // }
    public function calculatePurchasePoint($purchase_point, $after_days){

        $date = new \DateTime();

        $test_mode = false;
        

        switch($purchase_point){
            case PurchasePoint::POINT_ON_DATE:
                return 'now';
            case PurchasePoint::POINT_NEXT_WEEK:
                $interval = new \DateInterval('P1W');
            break;
            case PurchasePoint::POINT_NEXT_MONTH:
                $interval = new \DateInterval('P1M');
            break;
            case PurchasePoint::POINT_NEXT_YEAR:
                $interval = new \DateInterval('P1Y');
            break;
            case PurchasePoint::POINT_AFTER_DAYS:                        
                $after_days = new \DateTime($after_days);
                $now = new \DateTime();
                if($after_days <= $now){
                    $after_days = $now;
                }
                return $after_days->getTimestamp();
            break;
        }
        if($test_mode){
            $interval = new \DateInterval('PT60S');            
        }
        $date->add($interval);
        return $date->getTimestamp();
    }
    public function getScheduleState(StripeRecOrder $schedule){
        if($schedule->getRecStatus() === StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED){
            return [trans('stripe_recurring.label.rec_status.canceled'), '#A3A3A3', StripeRecOrder::SCHEDULE_CANCELED];
        }
        // $start_date = $schedule->getStartDate();        
        // $now = new \DateTime();
        // if($start_date >= $now){
            return [trans('stripe_recurring.label.rec_status.not_started'), '#fcba03', StripeRecOrder::SCHEDULE_NOT_STARTED];
        // }else{
        //     return [trans('stripe_recurring.label.rec_status.started'), '#437ec4', StripeRecOrder::SCHEDULE_STARTED];
        // }
    }
    public function getBundleProducts($order, $bundle_include_arr = null){
        
        $bundle_items = [];
        $bundle_items_str = "";
        $bundle_amount = 0;
        $pc_repo = $this->entityManager->getRepository(ProductClass::class);
        
        if($order instanceof Order){
            
            $order_items = $order->getProductOrderItems();
            $interval = $order_items[0]->getProductClass()->getInterval();
            foreach($order_items as $order_item){
                $pc = $order_item->getProductClass();                
                $bundle_code = $pc->getBundleProduct();

                if($bundle_include_arr && !$pc->isBundleRequired() && empty($bundle_include_arr[$order_item->getId()])){
                    continue;
                }

                if($bundle_code){
                    $bundle_item = $pc_repo->findOneBy(['code'  =>  $bundle_code]);
                    if($bundle_item && $bundle_item->getProduct()->isStripeProduct()){
                        if(empty($bundle_items[$bundle_code])){

                            $bundle_items[$bundle_code] = [
                                // 'price' =>  $bundle_item->getStripePriceId(),
                                'price_data'    =>  [
                                    'currency'  =>  strtolower($order->getCurrencyCode()),
                                    'product'   =>  $bundle_item->getProduct()->getStripeProdId(),
                                    'recurring' =>  [
                                        'interval' => $interval,
                                    ],
                                    'unit_amount'   =>  $bundle_item->getPrice02IncTax()
                                ],
                                'price'     =>  $bundle_item->getPrice02IncTax(),
                                'quantity'  =>  1,
                                'product_class' => $bundle_item
                            ];
                            $bundle_amount += $bundle_item->getPrice02IncTax();
                        }else{
                            $bundle_items[$bundle_code]['quantity'] ++;
                            $bundle_amount += $bundle_item->getPrice02IncTax();
                        }                    
                        $bundle_items_str .= $bundle_item->getCode() . ":" . $bundle_item->getPrice02IncTax() . ",";
                    }
                }
            }
        }else if($order instanceof StripeRecOrder){
            // $org_order = $order->getOrder();            
            // $order_items = $org_order->getProductOrderItems();
            // $interval = $order_items[0]->getProductClass()->getInterval();
            $interval = $order->getInterval();
            $bundling = $order->getBundling();
            if(empty($bundling)){
                return null;
            }
            
            $codes = explode(",", $bundling);

            $codes_kv = [];
            $occurrences = [];
            foreach($codes as $code){
                $temp = explode(":", $code);
                if(count($temp) == 1){
                    if(trim($temp[0])){
                        $codes_kv[] = $code;
                    }
                }else{
                    $codes_kv[$temp[0]] = $temp[1];
                    if(isset($occurrences[$temp[0]])){
                        $occurrences[$temp[0]]++;
                    }else{
                        $occurrences[$temp[0]] = 1;
                    }
                }
            }
            $codes = array_keys($codes_kv);
            $query = $pc_repo->createQueryBuilder("pc")
                ->where('pc.code in (:codes)')
                ->setParameter('codes', $codes)
                ->getQuery();
            $pcs = $query->execute();            

            foreach($pcs as $bundle_item){
                if($bundle_item){                    
                    $bundle_items[] = [
                        'price_data'=>  [// $bundle_item->getStripePriceId(),
                            'currency'  => strtolower($order->getCurrencyCode()),
                            'product'   =>  $bundle_item->getProduct()->getStripeProdId(),
                            'recurring' =>  [
                                'interval'  =>  $interval
                            ],
                            'unit_amount'   =>  $codes_kv[$bundle_item->getCode()],
                        ],
                        'price'     =>  $codes_kv[$bundle_item->getCode()],
                        'quantity'  =>  $occurrences[$bundle_item->getCode()],
                        'product_class' => $bundle_item
                    ];
                    $bundle_amount += $codes_kv[$bundle_item->getCode()] * $occurrences[$bundle_item->getCode()];    
                    $bundle_items_str .= $bundle_item->getCode() . ":" . $codes_kv[$bundle_item->getCode()] . ",";
                }
            }

        }else{
            return null;
        }


        if(empty($bundle_items)){
            return null;
        }
        $phase_items = [];
        $order_items = [];
        foreach($bundle_items as $item){
            $phase_items[] = [
                'price_data'     =>  $item['price_data'],
                'quantity'  =>  $item['quantity']
            ];
            $order_items[] = [
                'product_class' =>  $item['product_class'],
                'price'         =>  $item['price'],
                'quantity'      =>  $item['quantity']
            ];
        }
        return [
            'phase_items'   =>  $phase_items,
            'order_items'   =>  $order_items,
            'str'           =>  $bundle_items_str,
            'price'         =>  $bundle_amount,
        ];
    }


    public function getBundleProductsOrderByShipping($order, $bundle_include_arr = null){
        $pc_repo = $this->entityManager->getRepository(ProductClass::class);

        $shipping_bundle = [];
        
        $shippings = $order->getShippings();
        $total_amount = 0;
        foreach($shippings as $shipping){
            $bundle_items = [];
            $bundle_items_str = "";
            $bundle_amount = 0;
            $order_items = $shipping->getProductOrderItems();
                        
            foreach($order_items as $order_item){
                $pc = $order_item->getProductClass();
                $bundle_code = $pc->getBundleProduct();
                if($bundle_include_arr && !$pc->isBundleRequired() && empty($bundle_include_arr[$order_item->getId()])){                    
                    continue;
                }
                if($bundle_code){
                    $bundle_item = $pc_repo->findOneBy(['code'  =>  $bundle_code]);
                    if($bundle_item){
                        
                        $bundle_items[$order_item->getId()] = [                                
                            'order_item_id' => $order_item->getId(),
                            'product_class' => $bundle_item,
                            'quantity'  =>  $order_item->getQuantity(),
                            'main_product_class'=> $pc
                        ];
                        $bundle_amount += $bundle_item->getPrice02IncTax();
                        
                        $bundle_items_str .= $bundle_item->getCode() . ":" . $bundle_item->getPrice02IncTax() . ",";
                    }
                }
            }
            
            if(empty($bundle_items)){
                $shipping_bundle[] = null;                    
                continue;
            }
            
            $phase_items = [];
            $order_items = [];
            foreach($bundle_items as $item){
            
                $order_items[$item['order_item_id']] = [
                    'product_class' =>  $item['product_class'],                    
                    'quantity'      =>  $item['quantity'],
                    'order_item_id' =>  $item['order_item_id'],
                    'main_product_class'=> $item['main_product_class']
                ];
            }
            
            $shipping_bundle[] = [
                'order_items'   =>  $order_items,
                'str'           =>  $bundle_items_str,
                'price'         =>  $bundle_amount,
            ];
            
            $total_amount += $bundle_amount;
        }
        return ['shipping_bundle' => $shipping_bundle, 'bundle_amount'  =>  $total_amount];
    }
    public function getPriceSum($rec_order){
        $order_items = $rec_order->getOrderItems();
        $initial_amount = 0;
        $recurring_amount = 0;
        foreach($order_items as $order_item){
            if($order_item instanceof OrderItem){
                if($order_item->getProductClass()){
                    if($order_item->getProductClass()->isInitialPriced()){
                        $initial_price = $order_item->getProductClass()->getInitialPriceIncTax();
                    }else{
                        $initial_price = $order_item->getProductClass()->getPrice02IncTax();
                    }
                }
                else{
                    continue;
                }
                $price = $order_item->getProductClass()->getPrice02IncTax();
            }else{
                $initial_price = $order_item->getInitialPrice();
                $price = $order_item->getPrice();
            }
            $initial_amount += $initial_price * $order_item->getQuantity();
            $recurring_amount += $price * $order_item->getQuantity();
        }
        return compact('initial_amount', 'recurring_amount');
    }
}