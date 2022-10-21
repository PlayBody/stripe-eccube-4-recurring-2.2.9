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
if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}

use Plugin\StripeRec\Entity\StripeRecShippingProduct;
use Stripe\Product as StProduct;
use Stripe\Stripe;
use Stripe\Price as StPrice;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\PaymentMethod;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\StripeRec\Repository\StripeRecOrderRepository;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Product;
use Eccube\Entity\ProductClass;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripePaymentGateway\Entity\StripeConfig;
use Eccube\Event\EventArgs;

class StripeService{
    
    protected $container;
    protected $err_msg;

    public $zeroDecimalCurrencies = ["BIF", "CLP", "DJF", "GNF", "JPY", "KMF", "KRW", "MGA", "PYG", "RWF", "UGX", "VND", "VUV", "XAF", "XOF", "XPF"];

    public function __construct(
        ContainerInterface $container
        // StripeRecOrderRepository $rec_order_repo
        ){
        $this->container = $container;
        $this->em = $this->container->get('doctrine.orm.entity_manager');       
        
        $stripeConfigRepository = $this->em->getRepository(StripeConfig::class);
        $StripeConfig = $stripeConfigRepository->getConfigByOrder(null);
        if($StripeConfig){
            Stripe::setApiKey($StripeConfig->secret_key);
        }
    }

    public function getErrMsg(){
        $err_msg = $this->err_msg;
        $this->err_msg = "";
        return $err_msg;
    }

    public function registerProduct(Product $product){
        $stripe_prod = StProduct::create([
            'name'  =>  $product->getName(),
            'description' => $product->getDescriptionDetail()
        ]);
        if($stripe_prod && $stripe_prod->active){
            $product->setStripeProdId($stripe_prod->id);
            $this->em->persist($product);
            $this->em->flush();
            return true;
        }
        return false;
    }

    public function registerShippingProduct($name,$description,StripeRecShippingProduct $stripeRecShippingProduct){
        $stripe_prod = StProduct::create([
            'name'  =>  $name,
            'description' => $description
        ]);
        if($stripe_prod && $stripe_prod->active){
            $stripeRecShippingProduct->setStripeShippingProdId($stripe_prod->id);
            $this->em->persist($stripeRecShippingProduct);
            $this->em->flush();
            $this->em->commit();
            return true;
        }
        return false;
    }

    public function validateShippingProductExist($shippingProductId){
        try {
            $stripeShippingProduct = StProduct::retrieve($shippingProductId);
            return ($stripeShippingProduct && $stripeShippingProduct->active);
        } catch (\Exception $e){
            return false;
        }
    }

    public function registerPrice(ProductClass $prod_class, $interval = "month"){
        $unit_amount = $prod_class->getPrice02IncTax();
        if(empty($unit_amount)){
            return false;
        }
        //$currency = 'jpy';
        $currency = strtolower($prod_class->getCurrencyCode());
        if(empty($currency)){
            $currency = 'jpy';
        }
        $prod = $prod_class->getProduct();
        if (!$prod->isStripeProduct()){
            return false;
        }        
        $stripe_prod_id = $prod->getStripeProdId();
        $unit_amount=$this->getAmountToSentInStripe($unit_amount,$currency);

        $stripe_price = StPrice::create([
            'unit_amount'   =>  $unit_amount,
            'currency'      =>  $currency,
            'recurring'     =>  [ 'interval'    =>   $interval],
            'product'       =>  $stripe_prod_id
        ]);        
        if($stripe_price && $stripe_price->active){
            $id = $stripe_price->id;
            $prod_class->setStripePriceId($id);        
            $prod_class->setInterval($interval);            
            return $prod_class;
        }        
        return false;   
    }
    public function updatePrice(ProductClass $prod_class){
        if(!$prod_class->isRegistered()){
            return false;
        }
        $prod = $prod_class->getProduct();
        if (!$prod->isStripeProduct()){
            return false;
        }
        $stripe_prod_id = $prod->getStripeProdId();

        $price_id = $prod_class->getStripePriceId();
        // $price = StPrice::retrieve($price_id, []);
        // if(empty($price) || empty($price->active)){
        //     return false;
        // }
        // $interval = $price->recurring->interval;
        $interval = $prod_class->getInterval();
        $unit_amount = $prod_class->getPrice02IncTax();        
        //$currency = 'jpy';
        $currency = strtolower($prod_class->getCurrencyCode());
        if(empty($currency)){
            $currency = 'jpy';
        }
        $unit_amount=$this->getAmountToSentInStripe($unit_amount,$currency);

        log_info("StripeService---update amount---".$unit_amount);
        $stripe_price = StPrice::create([
            'unit_amount'   =>  $unit_amount,
            'currency'      =>  $currency,
            'recurring'     =>  [ 'interval'    =>   $interval],
            'product'       =>  $stripe_prod_id
        ]);
        if($stripe_price && $stripe_price->active){
            $id = $stripe_price->id;
            $prod_class->setStripePriceId($id);        
            return $prod_class;
        }
        return false;
    }

    public function updateSubscription($subscription_id, $new_price_id, $old_price_id){
        $subscription = Subscription::retrieve($subscription_id);
        if(empty($subscription)){
            log_info("StripeService---old subscription retrieve empty---" . $old_item_id);            
            return false;
        }
        log_info($subscription);
        $items = $subscription->items->data;
        $old_item_id = null;
        foreach($items as $item){
            log_info("StripeService---current_price_id---" . $item->price->id);
            if($item->price->id === $old_price_id){
                $old_item_id = $item->id;
            break;
            }
        }
        if(empty($old_item_id)){
            log_info("StripeService---old_item_id not found---" . $old_item_id);            
            return false;
        }
        log_info("StripeService---before---" . $subscription_id);
        $updated_subscription = Subscription::update($subscription_id, [
            'items' => [
                [
                    'id'    =>  $old_item_id,
                    'price' =>  $new_price_id
                ]
            ],
            'proration_behavior'    => 'none'    
        ]);
        if(empty($updated_subscription)){
            log_info("StripeService---after---empty" );
            return false;
        }
        log_info("StripeService---after---" . $updated_subscription->id);
        return $updated_subscription->id;
    }
    public function updateSchedule($id, $object){
        return SubscriptionSchedule::update($id, $object);
    }
    public function prorationOff($subscription_id){
        log_info("StripeService---prorationOff---".$subscription_id);
        $subscription = Subscription::retrieve($subscription_id);
        log_info($subscription);
        $items = [];
        foreach($subscription->items->data as $item){
            if($item->object != "subscription_item"){
                continue;
            }
            $items[] = [
                'id'    =>  $item->id,
                'price' =>  $item->price->id
            ];
        }
        return Subscription::update($subscription_id, [
            'items' =>  $items,
            'proration_behavior'    =>  'none'
        ]);
    }

    public function createSession($params){
        return Session::create($params);
    }

    public function cancelRecurring($subscription_id){
        $subscription = Subscription::retrieve($subscription_id);
        if($subscription && $subscription->status != "canceled"){
            try{
                $subscription->cancel();
                return true;
            }catch(\Exception $ex){
                return false;
            }
        }
        return true;
    }
    public function cancelSchedule($schedule_id){
        if(empty($schedule_id)){
            return true;
        }
        $schedule = SubscriptionSchedule::retrieve($schedule_id);
        if($schedule && ($schedule->status === "not_started" || $schedule->status === "active")){
            try{
                $schedule->cancel();
                return true;
            }catch(\Exception $ex){
                $this->err_msg = $ex->getMessage();
                return false;
            }
        }
        return true;
    }
    public function createSubsctiptionSchedule($params){
        return SubscriptionSchedule::create($params);
    }
    public function createCustomer($params){
        return Customer::create($params);
    }
    public function retrieveNotStartingSchedule($schedule_id){
        $schedule = SubscriptionSchedule::retrieve($schedule_id);
        if($schedule && ($schedule->status === "not_started")){
            return $schedule;
        }else{
            return false;
        }
    }
    public function retrieveUpcomingInvoice($params = null, $opts = null){
        return Invoice::upcoming($params, $opts);
    }

    public function updatePaymentMethod($customer_id, $payment_method_id)
    {
        try{
            $payment_method = PaymentMethod::retrieve($payment_method_id);
            $payment_method->attach([
                'customer' => $customer_id
            ]);
            
        }catch(\Exception $e){
            $this->err_msg = trans('stripe_recurring.checkout.payment_method.retrieve_error');
            return false;
        }
        Customer::update($customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $payment_method_id
            ]
        ]);
        return true;
    }
    public function payInvoice($invoice_id) {
        try{
            $invoice = Invoice::retrieve($invoice_id);
            $invoice->pay();
        }catch(\Exception $e){
            log_info("----StripeService : payInvoice-----");
            log_error($e->getMessage());
            $this->err_msg = $e->getMessage();
            return false;
        }
        return true;
    }

    public function getAmountToSentInStripe($amount, $currency)
    {
        if(!in_array($currency, $this->zeroDecimalCurrencies)){
            return (int)($amount*100);
        }
        return (int)$amount;
    }

}