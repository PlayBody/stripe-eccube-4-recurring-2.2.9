<?php
/*
* Plugin Name : StripePaymentGateway
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Service;

if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Stripe\Stripe;
use Stripe\Checkout\Session as StSession;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Entity\StripeRecOrderItem;
use Plugin\StripePaymentGateway\Entity\LicenseKey as OrgLicenseKey;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;
use Plugin\StripeRec\Entity\LicenseKey;
use Eccube\Entity\ProductClass;

class UtilService{
    //===========================

    protected $container;
    protected $em;
    protected $router;


    protected $err_msg = "Error";

    // const LIC_URL = "http://wordpress-69479-1385070.cloudwaysapps.com/?wc-api=software-api&";
    const LIC_URL = "https://subspire.co.jp/?wc-api=software-api&";
    

    public function __construct(
        ContainerInterface $container
    ){
        $this->container = $container;
        $this->em = $this->container->get('doctrine.orm.entity_manager');        
        $this->router = $this->container->get('router'); 
        $this->stripe_service = $this->container->get("plg_stripe_rec.service.stripe_service");
    }

    public function getErrMsg(){
        $err = $this->err_msg;
        $this->err_msg = null;
        return $err;
    }
    // // Create stripe checkout session
    // public function checkoutSession($order){
    //     $rec_order = $order->getRecOrder();        
    //     $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');        
    //     $is_bundle = $this->container->get('session')->get('is_bundle', true);
        
    //     $session = $this->container->get("session");
    //     $bundle_include_arr = $session->get('bundle_include_arr');
    //     if($bundle_include_arr){
    //         $bundle_include_arr = $bundle_include_arr;
    //         $session->set('bundle_include_arr', null);
    //     }
    //     $bundles = $pb_service->getBundleProducts($order, $bundle_include_arr);

    //     $purchase_point = $this->container->get('session')->getFlashBag()->get("purchase_point");
    //     if(empty($purchase_point) || empty($purchase_point[0])){
    //         $this->err_msg = trans('stripe_recurring.schedule.error.purchase_point_not_provided');
    //         return false;
    //     }
    //     $purchase_point = $purchase_point[0];

    //     if(empty($rec_order)){

    //         $rec_order = new StripeRecOrder();  
    //         $rec_order->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
    //         if(empty($bundles) && $purchase_point === "now" && !$order->isInitialPriced()){
    //             $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_PENDING);
    //         }else{
    //             $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED);
    //         }
    //         $rec_order->setOrder($order);
    //         $rec_order->setCustomer($order->getCustomer());
    //         $this->em->persist($rec_order);
    //         $this->em->flush();

    //         $order->setRecOrder($rec_order);
            
    //         $this->em->persist($order);
    //         $this->em->flush();

    //     }else{
    //         $this->err_msg = trans("stripe_rec.shopping.error.already_recurring");
    //         return false;
    //     }
        
    //     // $payment_total = $order->getPaymentTotal();        
    //     $order_items = $order->getProductOrderItems();       
        

    //     $subscription_items = [];
    //     foreach($order_items as $order_item){
    //         $pc = $order_item->getProductClass();

    //         if(empty($pc) || !$pc->isRegistered()){                
    //             $this->em->remove($rec_order);
    //             $this->em->flush();
    //             return false;
    //         }
    //         $rec_item = new StripeRecOrderItem();
    //         $rec_item->copyOrderItem($order_item);
    //         $rec_item->setRecOrder($rec_order);
    //         $rec_order->addOrderItem($rec_item);
    //         $subscription_items[] = [
    //             'price' => $pc->getStripePriceId(),
    //             'quantity'  =>  $rec_item->getQuantity()
    //         ];
    //         $this->em->persist($rec_item);
    //     }
        
    //     // there can be order items with same interval for current requirement. so select the first item in the order.
    //     $rec_order->setInterval($order_items[0]->getProductClass()->getInterval());

    //     $this->em->persist($rec_order);
    //     $this->em->commit();
    //     $this->em->flush();
        
        
    //     $success_url = $this->router->generate('plugin_stripe_rec_success', [], UrlGeneratorInterface::ABSOLUTE_URL);
    //     $cancel_url = $this->router->generate('plugin_stripe_rec_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

    //     $customer = $order->getCustomer();
    //     $stripe_customer = $this->getStripeCustomerByCustomer($customer);
    //     if($stripe_customer){
    //         $stripe_customer_id = $stripe_customer->getStripeCustomerId();            
    //     }else{
    //         $stripe_customer = $this->createStripeCustomer($customer);
    //         $stripe_customer_id = $stripe_customer->getStripeCustomerId();
    //     }
        
    //     if(empty($bundles) && $purchase_point === "now" && !$order->isInitialPriced()){
    //         // $line_items = empty($bundles) ? $subscription_items : array_merge($subscription_items, $bundles['phase_items']);
    //         $session = $this->stripe_service->createSession([
    //             'payment_method_types'  =>  ['card'],
    //             'line_items' => [
    //                 $subscription_items
    //             ],
    //               'customer'  =>$stripe_customer_id,
    //               'mode' => 'subscription',
    //               'success_url' => $success_url,
    //               'cancel_url' => $cancel_url,
    //         ]);
    //     }else{
    //         $session = $this->stripe_service->createSession([
    //             'payment_method_types'  =>  ['card'],
    //               'customer'  =>$stripe_customer_id,
    //               'mode' => 'setup',
    //               'success_url' => $success_url,
    //               'cancel_url' => $cancel_url,
    //         ]);
    //     }
        
    //     log_info("setup mode session created : " . $session->id);
        
        
    //     $stripe_session = new CheckoutSession();
    //     $stripe_session->setSessionId($session->id);
    //     $stripe_session->setOrderId($order->getId());
    //     $stripe_session->setMeta(serialize(['purchase_point' => $purchase_point, 'bundle_include_arr' => $bundle_include_arr]));
    //     $stripe_session->setSessionStatus(CheckoutSession::SESS_STATUS_PENDING);        
    //     $this->em->persist($stripe_session);
    //     $this->em->flush();        
    //     $rec_order->setSession($stripe_session);
    //     $this->em->persist($rec_order);
    //     $this->em->flush();
    //     return $session;
    // }

    private function getStripeCustomerByCustomer($customer){
        $repo = $this->em->getRepository(StripeCustomer::class);
        return $repo->findOneBy(['Customer' => $customer]);
    }
    private function createStripeCustomer($customer){
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
        $stripe_customer = $stripe_service->createCustomer([]);
        if($stripe_customer){
            $server_stripe_customer = new StripeCustomer();
            $server_stripe_customer->setCustomer($customer);
            $server_stripe_customer->setStripeCustomerId($stripe_customer->id);
            $server_stripe_customer->setIsSaveCardOn(false);
            $server_stripe_customer->setCreatedAt(new \DateTime());
            $this->em->persist($server_stripe_customer);
            $this->em->flush();
            return $server_stripe_customer;
        }
        return null;
    }

    public function convertDateTime($timestamp){
        $dt1 = new \DateTime();
        $dt1->setTimestamp($timestamp);
        return $dt1;
    }

    // Only use this function in recurring payment webhook
    // public function completeOrder($order, $stripe_session){
    //     log_info("UtilService---" . "completeOrder-----");
    //     if(!$order->isRecurring()){
    //         log_info("UtilService---" . "completeOrder not recurring");
    //         return false;
    //     }
    //     // TODO
    //     $rec_order = $this->em->getRepository(StripeRecOrder::class)->findOneBy([
    //         'subscription_id'   =>  $stripe_session->subscription,
    //         'stripe_customer_id'=>  $stripe_session->customer]);
    //     if(empty($rec_order) ){
    //         $rec_order = $order->getRecOrder();            
    //         $rec_order->setSubscriptionId($stripe_session->subscription);
    //         $rec_order->setStripeCustomerId($stripe_session->customer);
    //         log_info("UtilService---completeOrder rec_order empty");    
    //     }else{
    //         if($order->getRecOrder()->getId() !== $rec_order->getId()){
    //             log_info("UtilService---completeOrder diff rec order exist");
    //             $rc_org = $order->getRecOrder();
    //             $rc_org->copyFromTempRecOrder($rec_order);
    //             $this->em->remove($rec_order);
    //             $rec_order = $rc_org;                
    //         }
    //     }
    //     $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_ACTIVE);
    //     $this->em->persist($rec_order);
    //     $order->setRecOrder($rec_order);
    //     $this->em->persist($order);
    //     $this->em->flush();

    //     return $rec_order;
    // }

    public function checkProductClassPriceId($register_flg){

        $interval_arr = [
            'day', 'month', 'week','year', 'none'
        ];
        if ($register_flg === "none" || empty($register_flg) || !in_array($register_flg, $interval_arr)){
            return true;
        }
        // if(!empty($register_flg) && in_array($register_flg, $interval_arr)){
        //     return false;
        // }
        // return $register_flg;
        return false;
    }

    public function paidStatusObj($paid_status){
        if($paid_status instanceof StripeRecOrder){
            $paid_status = $paid_status->getPaidStatus();
        }
        switch($paid_status){
            case StripeRecOrder::STATUS_PAID:
                return [trans('stripe_recurring.label.paid_status.paid'), '#437ec4'];
            case StripeRecOrder::STATUS_PAY_FAILED:
                return [trans('stripe_recurring.label.paid_status.failed'), '#C04949'];
            case StripeRecOrder::STATUS_PAY_UPCOMING:
                return [trans('stripe_recurring.label.paid_status.upcoming'), '#EEB128'];
            case StripeRecOrder::STATUS_PAY_UNDEFINED:
                return [trans('stripe_recurring.label.paid_status.undefined'), '#A3A3A3'];            
        }
        return [trans('stripe_recurring.label.paid_status.undefined'), '#A3A3A3'];
    }
    public function recStatusObj($rec_status){
        if($rec_status instanceof StripeRecOrder){
            $rec_status = $rec_status->getRecStatus();
        }
        switch($rec_status){
            case StripeRecOrder::REC_STATUS_ACTIVE:
                return [trans('stripe_recurring.label.rec_status.active'), '#437ec4'];
            case StripeRecOrder::REC_STATUS_CANCELED:
                return [trans('stripe_recurring.label.rec_status.canceled'), '#A3A3A3'];
            case StripeRecOrder::REC_STATUS_SCHEDULED:
                return [trans('stripe_recurring.label.rec_status.scheduled'), '#fcba03'];                            
            case StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED:
                return [trans('stripe_recurring.label.rec_status.canceled'), '#A3A3A3'];
        }
        return [trans('stripe_recurring.label.rec_status.active'), '#437ec4'];
    }
    
    public function getItemByOrderAndPriceid($rec_order, $price_id){
        return $this->em->getRepository(StripeRecOrderItem::class)->getByOrderAndPriceId($rec_order, $price_id);
    }

    public function requestLicense($key){
        $url = UtilService::LIC_URL
            .'request=activation'
            .'&email='.$key->getEmail()
            .'&license_key='.$key->getLicenseKey()
            .'&product_id=stripe_recurring_eccube4'
            .'&instance='.$key->getInstance();
        $content = json_decode(\file_get_contents($url));
        return isset($content->activated) && $content->activated === true;
            
    }
    public function requestOrgLicense($key){
        $url = UtilService::LIC_URL
        .'request=activation'
        .'&email='.$key->getEmail()
        .'&license_key='.$key->getLicenseKey()
        .'&product_id=stripe_eccube4'
        .'&instance='.$key->getInstance();
        $content = json_decode(\file_get_contents($url));
        return isset($content->activated) && $content->activated === true;
    }

    public function checkOrgLicense(){
        if(class_exists(OrgLicenseKey::class)){
            $key = $this->em->getRepository(OrgLicenseKey::class)->get();
            if($key){
                if($key->getKeyType() === 1){
                    return "test";
                }
                if($this->requestOrgLicense($key)){
                    return "authed";
                }else{
                    return "unauthed";
                }
            }
            else{
                return "unauthed";
            }
        }else{
            return "no_license";
        }
    }
    public function checkLicense(){        
        $license_repo = $this->em->getRepository(LicenseKey::class);
        $key = $license_repo->get();
        if ($key) {                    
            return $this->requestLicense($key);            
        }else{
            return false;
        }
    }

    public function saveDefaultClass(ProductClass $pc, $register_flg /* means interval*/, $stripe_register_flg = "register" /* is price changed ? 'update' : 'register'*/){
            
        if (!$pc->getId() && !$pc->isVisible()) {
            return;
        }
        $this->err_msg = null;
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');

        // $stripe_register_flg = $this->checkPriceChange($pc, $register_flg);        
        if($stripe_register_flg){     
            if($stripe_register_flg === 'update'){
                $pc_new = $this->updateDefaultProdClass($pc);
                if(empty($pc_new)){                    
                    // $this->addError("stripe_rec.admin.stripe_price.update_err", 'admin');
                    $this->err_msg = "stripe_rec.admin.stripe_price.update_err";
                }else{
                    $pc = $pc_new;                    
                }
            }else{                
                $pc_new = $stripe_service->registerPrice($pc, $register_flg);                
                if(empty($pc_new)){
                    // $this->addError("stripe_rec.admin.stripe_price.register_err", 'admin');
                    $this->err_msg = "stripe_rec.admin.stripe_price.update_err";

                }else{
                    $pc = $pc_new;
                }
            }
            $this->em->flush();
        }        
        if($this->err_msg){
            return false;
        }else{
            return true;            
        }
    }
    public function updateDefaultProdClass($prod_class){
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
        
        $price_id = $prod_class->getStripePriceId();
        $rec_orders = $this->em->getRepository(StripeRecOrder::class)->getByPriceId($price_id)->toArray();         
        $this->em->persist($prod_class);
        $this->em->flush($prod_class);
        
        $new_pc = $stripe_service->updatePrice($prod_class);
        if(empty($new_pc)){
            log_info("ProductClassExController---update price empty---");
            return false;
        }
        log_info("ProductClassExController---update price success---");
        foreach($rec_orders as $rec_order){
            log_info("ProductClassExController---update rec order---" . $rec_order->getId());
            if(empty($rec_order->getSubscriptionId())){
                continue;
            }
            $res = $stripe_service->updateSubscription($rec_order->getSubscriptionId(), $new_pc->getStripePriceId(), $price_id);
            if(empty($res)){
                log_info("ProductClassExController---update subscription failed---");
                continue;
            }
            $rec_order->setSubscriptionId($res);            
            $this->em->persist($rec_order);
        }
    }

    public function checkPriceChange($new_pc, $register_flg){
        $interval_arr = [
            'day', 'month', 'week','year'
        ];

        if($new_pc->isRegistered()){
            $connection = $this->em->getConnection();
            $statement = $connection->prepare('select price02 from dtb_product_class where id = :id');
            $statement->bindValue('id', $new_pc->getId());
            $statement->execute();
            $pcs = $statement->fetchAll();
            
            if(!empty($pcs[0]['price02'])){
                if($new_pc->getPrice02() != $pcs[0]['price02']){
                    return 'update';
                }else{
                    return false;
                }
            }else{
                return 'update';
            }
        }        
        
        
        if(empty($register_flg) || !in_array($register_flg, $interval_arr)){            
            return false;
        }
        return true;
    }    
    public function transInterval($interval){
        $trans = [
            'day'   =>  '日次',            
            'week'  =>  '週次',
            'month' =>  '月次',
            'year'  =>  '年次',
        ];
        if(isset($trans[$interval])){
            return $trans[$interval];
        }
        return "";
    }
}