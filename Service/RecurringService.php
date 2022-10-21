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
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Entity\StripeRecOrderItem;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;
use Plugin\StripeRec\StripeRecEvent;
use Eccube\Event\EventArgs;
use Eccube\Service\MailService;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Entity\OrderItem;
use Eccube\Repository\OrderRepository;
use Plugin\StripeRec\Service\ConfigService;


class RecurringService{
    
    protected $container;
    protected $em;
    protected $rec_order_repo;
    protected $mail_service;
    protected $stripe_service;
    protected $err_msg = "";
    protected $dispatcher;


    const LOG_IF = "Recurring Service---";
    
    public function __construct(
        ContainerInterface $container        
        ){
        $this->container = $container;
        $this->em = $this->container->get('doctrine.orm.entity_manager'); 
        $this->rec_order_repo = $this->em->getRepository(StripeRecOrder::class);
        $this->mail_service = $this->container->get("plg_stripe_rec.service.email.service");
        $this->stripe_service = $this->container->get("plg_stripe_rec.service.stripe_service");
        $this->dispatcher = $this->container->get('event_dispatcher');
    }

    public function getErrMsg(){
        return $this->err_msg;
    }


    public function sendRecMail($sub_id){
        if($sub_id instanceof StripeRecOrder){
            $rec_order = $sub_id;
        }else{
            $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' => $sub_id]);
        }
        
        if(empty($rec_order)){
            log_info(RecurringService::LOG_IF . "rec_order is empty");
            return;
        }
        $order = $rec_order->getOrder();
        log_info(RecurringService::LOG_IF . "mail sending");
        if(!empty($order)){
            // $this->container->get('plg_stripe_rec.service.email.service')->setRecId($rec_order->getId());
            $this->mail_service->sendOrderMail($order);
        }
    }
    public function subscriptionUpdated($object){
        // $sub_id = $object->id;
        // $items = $object->items->data;
        
        // if(!empty($items[0]->price->id)){
        //     $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' =>  $sub_id]);
        //     if(!empty($rec_order)){
        //         $rec_order->setPriceId($items[0]->price->id);
        //         $this->em->persist($rec_order);
        //         $this->em->flush();
        //     }
        // }
        // $sub_id = $object->id;
        // $upcoming_invoice = $this->stripe_service->retrieveUpcomingInvoice(['subscription' => $sub_id]);
        
        // TODO send upcoming invoice mail

    }
    public function updateRecOrderStatus($sub_id, $paid_status){
        $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' => $sub_id]);
        if(empty($rec_order)){
            log_info(RecurringService::LOG_IF . "rec_order is empty");
            return;
        }
        log_info(RecurringService::LOG_IF . "rec_order status setting to $paid_status");
        $rec_order->setPaidStatus($paid_status); // )
        $this->em->persist($rec_order);
        $this->em->flush();
    }
    /**
     * check rec_status if is_scheduled and into non-scheduled status
     */
    private function checkScheduledStatus($rec_order, $rec_status){
        if($rec_order->isScheduled()){
            $rec_order->setRecStatus($rec_status);
        }
        return $rec_order;
    }
    public function invoicePaid($object){
        $customer = $object->customer;
        $data = $object->lines->data;

        $subscriptions = [];
        foreach($data as $item){
            if(!empty($item->subscription)){                
                if(!in_array($item->subscription, array_keys($subscriptions))){
                    if(count($subscriptions) === 0){
                        $subscriptions[$item->subscription] = [];
                    }
                    
                    $rec_order = $this->createOrUpdateRecOrder(
                        StripeRecOrder::STATUS_PAY_UNDEFINED,
                        $item,
                        $customer,
                        $this->convertDateTime($object->created)
                        );
                    $subscriptions[$item->subscription] = $rec_order;

                    $rec_order->setInvoiceData($object);
                }else{
                    $rec_order = $this->rec_order_repo->findOneBy([
                        'subscription_id' => $item->subscription,
                        'stripe_customer_id' => $customer]);
                }
                $rec_item = $this->em->getRepository(StripeRecOrderItem::class)->getByOrderAndPriceId($rec_order, $item->price->id);
                if($rec_item){
                    log_info("RecurringService---".$rec_item->getId());
                    $rec_item->setPaidStatus(StripeRecOrder::STATUS_PAID);
                    $this->em->persist($rec_item);                     

                }
                $rec_order->addInvoiceItem($item);
            }
        }
        $this->em->flush();
        $this->em->commit(); 
        
        $rec_item_class_ids = [];
        foreach($subscriptions as $sub_id => $rec_order){
            $rec_items = $rec_order->getOrderItems();
            $success_flg = true;
            
            $rec_order->setPaidStatus(StripeRecOrder::STATUS_PAID);                
            
            $rec_order = $this->checkScheduledStatus($rec_order, StripeRecOrder::REC_STATUS_ACTIVE);            
            $this->em->persist($rec_order);
            $this->em->flush();
            $this->em->commit(); 
            $order = $rec_order->getOrder();
            if($order){
                if (!$this->hasOverlappedPaidOrder($rec_order)) {
                    $NewOrder = $this->createNewOrder($rec_order, OrderStatus::PAID);
                    $this->dispatcher->dispatch(StripeRecEvent::REC_ORDER_SUBSCRIPTION_PAID, new EventArgs([
                        'rec_order' =>  $rec_order,
                    ]));
                }
                
                if($rec_order->getLastChargeId() !== $object->charge){
                    $rec_order->setLastChargeId($object->charge);
                    $rec_order->setLastPaymentDate(new \DateTime());
                    $this->em->persist($rec_order);
                    $this->em->flush();
                    $this->em->commit();
                    $this->sendMail($rec_order, "invoice.paid");
                }
            }
        }        
    }
    public function sendMail($rec_order, $type){
        switch($type){
            case "invoice.paid":
                log_info("sending mail invoice.paid");
                $this->mail_service->sendPaidMail($rec_order);
            break;
            case "invoice.upcoming":
                log_info("sending mail invoice.paid");
                $this->mail_service->sendUpcomingMail($rec_order);
            break;
            case "invoice.failed":
                log_info("sending mail invoice.paid");
                $this->mail_service->sendFailedMail($rec_order);  
            break;
            case "subscription.canceled":
                $this->mail_service->sendCancelMail($rec_order);
        }
    }

    public function invoiceUpcoming($object){
        $customer = $object->customer;
        $data = $object->lines->data;

        $subscriptions = [];
        foreach($data as $item){

            if(!empty($item->subscription)){                
                if(!in_array($item->subscription, array_keys($subscriptions))){
                    if(count($subscriptions) === 0){
                        $subscriptions[$item->subscription] = [];
                    }
                    
                    $rec_order = $this->createOrUpdateRecOrder(
                        StripeRecOrder::STATUS_PAY_UPCOMING,
                        $item,
                        $customer,
                        $this->convertDateTime($object->created)
                        );
                    $subscriptions[$item->subscription] = $rec_order;

                    if(!empty($object->charge) && $object->charge != $rec_order->getLastChargeId()){
                        $rec_order->setLastChargeId($object->charge);
                        $this->em->persist($rec_order);                        
                    }
                    $rec_order->setInvoiceData($object);
                }else{
                    $rec_order = $this->rec_order_repo->findOneBy([
                        'subscription_id' => $item->subscription,
                        'stripe_customer_id' => $customer]);
                }
                $rec_item = $this->em->getRepository(StripeRecOrderItem::class)->getByOrderAndPriceId($rec_order, $item->price->id);
                if(!empty($rec_item)){
                    $rec_item->setPaidStatus(StripeRecOrder::STATUS_PAY_UPCOMING);
                    $this->em->persist($rec_item);
                }
                $this->em->flush();
                $rec_order->addInvoiceItem($item);
            }
        }
        $config_service = $this->container->get('plg_stripe_rec.service.admin.plugin.config');
        $upcoming_mail = $config_service->get(ConfigService::INCOMING_MAIL) ? true : false;

        foreach($subscriptions as $sub_id => $rec_order){      
            if ($upcoming_mail) {
                if($rec_order->getOrder()){
                    $this->sendMail($rec_order, "invoice.upcoming");
                }
            }
            $rec_order = $this->checkScheduledStatus($rec_order, StripeRecOrder::REC_STATUS_ACTIVE);
            $this->em->persist($rec_order);
            $this->em->flush();
        }
    }
    public function invoiceFailed($object){
        
        $customer = $object->customer;
        $data = $object->lines->data;

        $subscriptions = [];
        foreach($data as $item){

            if(!empty($item->subscription)){                
                if(!in_array($item->subscription, array_keys($subscriptions))){
                    if(count($subscriptions) === 0){
                        $subscriptions[$item->subscription] = [];
                    }
                    
                    $rec_order = $this->createOrUpdateRecOrder(
                        StripeRecOrder::STATUS_PAY_FAILED,
                        $item,
                        $customer,
                        $this->convertDateTime($object->created)
                        );
                    $rec_order->setFailedInvoice($object->id);
                    $this->em->persist($rec_order);
                    $this->em->flush();
                    
                    $subscriptions[$item->subscription] = $rec_order;

                    if(!empty($object->charge) && $object->charge != $rec_order->getLastChargeId()){
                        $rec_order->setLastChargeId($object->charge);
                        $rec_order->setLastPaymentDate(new \DateTime());
                        $this->em->persist($rec_order);
                    }
                    $rec_order->setInvoiceData($object);
                }else{
                    $rec_order = $this->rec_order_repo->findOneBy([
                        'subscription_id' => $item->subscription,
                        'stripe_customer_id' => $customer]);
                }
                $rec_item = $this->em->getRepository(StripeRecOrderItem::class)->getByOrderAndPriceId($rec_order, $item->price->id);
                if(!empty($rec_item)){
                    $rec_item->setPaidStatus(StripeRecOrder::STATUS_PAY_FAILED);
                    $this->em->persist($rec_item);
                }
                $this->em->flush();
                $rec_order->addInvoiceItem($item);
            }
        }
        foreach($subscriptions as $sub_id => $rec_order){      
            if($rec_order->getOrder()){
                $this->sendMail($rec_order, "invoice.failed");
            }
            $rec_order = $this->checkScheduledStatus($rec_order, StripeRecOrder::REC_STATUS_ACTIVE);
            $this->em->persist($rec_order);
            $this->em->flush();
        }
    }    
    public function subscriptionCreated($object){
        $sub_id = $object->id;
        $stripe_customer_id = $object->customer;  
        log_info("subscription_id : " . $sub_id);
        log_info("stripe_customer_id: " . $stripe_customer_id);
        $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' => $sub_id, "stripe_customer_id" => $stripe_customer_id]);
        if(empty($rec_order) && $object->schedule){
            log_info("rec_order is empty by subscription_id and stripe_customer_id");
            log_info("schedule : " . $object->schedule);
            $rec_order = $this->rec_order_repo->findOneBy(['schedule_id' => $object->schedule]);
            if(empty($rec_order)){
                $rec_order = new StripeRecOrder;
                $rec_order->setScheduleId($object->schedule);
                $rec_order->setSubscriptionId($sub_id);
            }
        }else{
            return;
        }
        if(strpos($rec_order->getRecStatus(), StripeRecOrder::REC_STATUS_SCHEDULED) !== false){
            $rec_order->setRecStatus($object->status);
            $rec_order->copyFrom($object);
            $this->em->persist($rec_order);
            $this->em->flush();
            $this->em->commit();
        }
    }
    public function subscriptionScheduleCanceled($object){        
        $rec_order = $this->rec_order_repo->findOneBy(['schedule_id' => $object->id]);
        if($rec_order){
            $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED);
            $this->em->persist($rec_order);
            $this->em->flush();
        }
    }
    public function createOrUpdateRecOrder($paid_status, $item, $stripe_customer_id, $last_payment_date = null){
        log_info(RecurringService::LOG_IF . "createOrUpdateRecOrder");
        $sub_id = $item->subscription;
        $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' => $sub_id, "stripe_customer_id" => $stripe_customer_id]);
        if(empty($rec_order)){
            
            log_info(RecurringService::LOG_IF . "rec order is empty in webhook");
            $rec_order = new StripeRecOrder;
            $rec_order->setSubscriptionId($sub_id);            
            $rec_order->setStripeCustomerId($stripe_customer_id);

            $stripe_customer = $this->em->getRepository(StripeCustomer::class)->findOneBy(['stripe_customer_id' => $stripe_customer_id]);
            if($stripe_customer){
                $customer = $stripe_customer->getCustomer();
                if($customer){
                    $rec_order->setCustomer($customer);
                }
            }
        }
        log_info(RecurringService::LOG_IF . "rec order is not empty in");

        
        $rec_order->setCurrentPeriodStart($this->convertDateTime($item->period->start));
        $rec_order->setCurrentPeriodEnd($this->convertDateTime($item->period->end));
        
        $rec_order->setPaidStatus($paid_status);
        if(!empty($last_payment_date)){
            $rec_order->setLastPaymentDate($last_payment_date);
        }
        if($paid_status == StripeRecOrder::STATUS_PAID){
            $rec_order->setInterval($item->plan->interval);
        }

        $rec_items = $rec_order->getOrderItems();
        if(!empty($rec_items)){
            foreach($rec_items as $rec_item){
                $rec_item->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
            }
        }
        $this->em->persist($rec_order);
        $this->em->flush();
        $this->em->commit();  

        $order = $rec_order->getOrder();
        log_info("Recurring---orderDate---" );
        if($order){
            $Today = new \DateTime();            
            // $order->setOrderDate($this->convertDateTime($Today->getTimestamp()));
            if(empty($order->getRecOrder())){
                $order->setRecOrder($rec_order);
                log_info("Recurring---orderDate---" );
                log_info($order->getOrderDate());
                $this->em->persist($order);
                $this->em->commit();
            }
        }
        
        return $rec_order;
    }

    public function recurringCanceled($object){
        $sub_id = $object->id;
        $rec_order = $this->rec_order_repo->findOneBy(['subscription_id' => $sub_id]);
        if(!empty($rec_order)){
            $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_CANCELED);
            $this->em->persist($rec_order);
            $this->em->flush();
            $this->em->commit();
            $this->sendMail($rec_order, 'subscription.canceled');
        }
    }
    // public function completeOrder($object){
    //     $session_id = $object->id;
    //     $order = $this->em->getRepository(CheckoutSession::class)->getOrderBySession($session_id);
    //     if(empty($order)){
    //         return;
    //     }
    //     $util_service = $this->container->get("plg_stripe_recurring.service.util");
    //     if($order->isRecurring()){
    //         $rec_order = $order->getRecOrder();
    //         if($rec_order->isScheduled()){
    //             log_info(__FUNCTION__ . "---" . 'recurring scheduled');
    //             $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
    //             $pb_service->createScheduleBySession($order, $object);
    //         }else{
    //             $util_service->completeOrder($order, $object);
    //         }
    //         // log_info("RecurringService---completeOrder---prorate off");
    //         // $this->stripe_service->prorationOff($object->subscription);            
    //     }
    // }
    public function convertDateTime($timestamp){
        $dt1 = new \DateTime();
        $dt1->setTimestamp($timestamp);
        return $dt1;
    }
    public function cancelRecurring($rec_order){
        $res = true;

        if($rec_order->isScheduled()){
            $pb_service = $this->container->get("plg_stripe_rec.service.pointbundle_service");
            $state = $pb_service->getScheduleState($rec_order);
            if($state[2] === StripeRecOrder::SCHEDULE_STARTED){
                $this->err_msg = "stripe_recurring.schedule.error.alreasy_started";
                return false;
            }            
            $res = $this->stripe_service->cancelSchedule($rec_order->getScheduleId());
            if($res === false){
                $this->err_msg = $this->stripe_service->getErrMsg();
            }else{
                $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED);
                $this->em->persist($rec_order);
                $this->em->flush();
            }            
            return $res;
        }else{
            $sub_id = $rec_order->getSubscriptionId();
            if(empty($sub_id)){                
                return true;
            }
            if($rec_order->getRecStatus() != StripeRecOrder::REC_STATUS_CANCELED){
                $res = $this->stripe_service->cancelRecurring($sub_id);
                if(!empty($res)){
                    $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_CANCELED);
                    $this->em->persist($rec_order);
                    $this->em->flush();
                }
            }            
            return $res;
        }
    }

    public function createNewOrder($rec_order, $paid_status_id = OrderStatus::PAID) 
    {
        log_info("create new order");
        $OriginalOrder = $rec_order->getOrder();
        
        $NewOrder = clone $OriginalOrder;
        // $NewOrder->copy($OriginalOrder);       
// OrderStatus
        // complete_message
        // complete_mail_message
        // payment_date
        // message
        $NewOrder->setPreOrderId(null);
        $NewOrder->setCompleteMessage(null);
        $NewOrder->setCompleteMailMessage(null);
        $NewOrder->setmessage(null);

        $Today = new \DateTime();
        $NewOrder->setPaymentDate($Today);
        $OrderStatus = $this->em->getRepository(OrderStatus::class)->find($paid_status_id);
        $NewOrder->setOrderStatus($OrderStatus);
        $NewOrder->setRecorder($rec_order);
        
        if ($rec_order->getInvoiceData()) {
            $NewOrder->setInvoiceid($rec_order->getInvoiceData()->id);
        }
        $NewOrder->getOrderItems()->clear();


        if ($order_item_id = $rec_order->getOrderItemId()) {
            $OrderItems = $this->em->getRepository(OrderItem::class)->findBy(['id' => $order_item_id]);
            if (!empty($OrderItems[0])) {
                $old_subtotal = $NewOrder->getSubtotal();
                $new_subtotal = $OrderItems[0]->getProductClass()->getPrice02IncTax(); // * $OrderItems[0]->getQuantity();
                $diff = $old_subtotal - $new_subtotal;
                if ($diff) {
                    $NewOrder->setTotal($NewOrder->getTotal() - $diff);
                    $NewOrder->setPaymentTotal($NewOrder->getPaymentTotal() - $diff);
                    $NewOrder->setSubtotal($new_subtotal);
                }
            }
        } else {
            $OrderItems = $OriginalOrder->getOrderItems();
        }

        $shipping_ids = [];
        foreach($OrderItems as $OrderItem) {
            $NewItem = clone $OrderItem;
            // $NewItem->copy($OrderItem);
            $Shipping = $OrderItem->getShipping();

            if ($Shipping) {
                if (empty($shipping_ids[$Shipping->getId()])) {
                    $NewShipping = clone $Shipping;
                    $NewShipping->setOrder($NewOrder);
                    $NewOrder->addShipping($NewShipping);
                    $this->em->persist($NewShipping);
    
                    $shipping_ids[$Shipping->getId()] = $NewShipping;
                    
                } 
                $NewItem->setShipping($shipping_ids[$Shipping->getId()]);
            }
            if ($rec_order->getOrderItemId()) {
                $NewItem->setQuantity(1);
            }
            $NewItem->setOrder($NewOrder);
            $this->em->persist($NewItem);
            $NewOrder->addOrderItem($NewItem);
        }

        log_info("rec_order id : " . $rec_order->getId());
        log_info("payment count---" . $rec_order->getPaymentCount());
        if ($rec_order->getPaymentCount() == 0) {
            $NewOrder->setIsInitialRec(true);
        } else {
            $NewOrder->setIsInitialRec(false);
        }
        $this->em->persist($NewOrder);
        $this->em->flush();

        $event = new EventArgs(
            [
                'OriginalOrder' =>  $OriginalOrder,
                'NewOrder'      =>  $NewOrder,
            ]
        );
        $this->dispatcher->dispatch(StripeRecEvent::NEW_CHARGE_ORDER_COPIED, $event);

        $rec_order->addOrder($NewOrder);
        return $NewOrder;
    }

    public function hasOverlappedPaidOrder($rec_order, $invoice_data = null) 
    {
        if (!$invoice_data ){
            $invoice_data = $rec_order->getInvoiceData();
        }
        $invoice_id = $invoice_data->id;
        $OrderRepository = $this->em->getRepository(Order::class);
        
        $Order = $OrderRepository->findOneBy(['recOrder' => $rec_order, 'invoice_id' => $invoice_id]);
        return !empty($Order);
    }

    public function getPriceDetail($rec_order) 
    {
        $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
        $bundles = $pb_service->getBundleProducts($rec_order);
        
        $price_sum = $pb_service->getPriceSum($rec_order);        
        extract($price_sum);

        if($bundles){
            $bundle_order_items = $bundles['order_items'];
            $initial_amount += $bundles['price'];
        }else{
            $bundle_order_items = null;
        }
        if($coupon_str = $rec_order->getCouponDiscountStr()){
            $coupon_service = $this->container->get('plg_stripe_rec.service.coupon_service');
            $initial_discount = $coupon_service->couponDiscountFromStr($initial_amount, $coupon_str);
            $recurring_discount = $coupon_service->couponDiscountFromStr($recurring_amount, $coupon_str);
        }else{
            $initial_discount = 0;
            $recurring_discount = 0;
        }

        return compact('bundle_order_items', 'initial_amount', 'recurring_amount', 'initial_discount', 'recurring_discount');
    }
}