<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.

* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Service\Method;

if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}

use Plugin\StripeRec\Entity\StripeRecShippingProduct;
use Plugin\StripeRec\Service\StripeService;
use Stripe\Customer as StripeLibCustomer;
use Stripe\PaymentMethod;
use Stripe\Subscription;
use Stripe\Stripe;
use Stripe\SubscriptionSchedule;

use Eccube\Common\EccubeConfig;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Entity\Customer;
use Eccube\Entity\ProductClass;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Service\Payment\PaymentDispatcher;
use Eccube\Service\Payment\PaymentMethodInterface;
use Eccube\Service\Payment\PaymentResult;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\RemisePayment4\Form\Type\Admin\ConfigType;
use Symfony\Component\Form\FormInterface;
use Plugin\StripePaymentGateway\Entity\StripeConfig;
use Plugin\StripePaymentGateway\Repository\StripeConfigRepository;
use Plugin\StripePaymentGateway\Entity\StripeLog;
use Plugin\StripePaymentGateway\Repository\StripeLogRepository;
use Plugin\StripePaymentGateway\Entity\StripeOrder;
use Plugin\StripePaymentGateway\Repository\StripeOrderRepository;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;
use Plugin\StripePaymentGateway\Repository\StripeCustomerRepository;
use Plugin\StripePaymentGateway\StripeClient;
use Plugin\StripeRec\StripeRecEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Eccube\Event\EventArgs;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Entity\StripeRecOrderItem;
use Plugin\StripeRec\Service\ConfigService;
use Eccube\Repository\ProductClassRepository;


/**
 * Stripe Recurring Non Apple/Google pay method
 */
class StripeRecurringNagMethod implements PaymentMethodInterface
{

    public $zeroDecimalCurrencies = ["BIF", "CLP", "DJF", "GNF", "JPY", "KMF", "KRW", "MGA", "PYG", "RWF", "UGX", "VND", "VUV", "XAF", "XOF", "XPF"];
    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var Order
     */
    protected $Order;

    /**
     * @var FormInterface
     */
    protected $form;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var StripeConfigRepository
     */
    private $stripeConfigRepository;

    /**
     * @var StripeLogRepository
     */
    private $stripeLogRepository;

    /**
     * @var StripeOrderRepository
     */
    private $stripeOrderRepository;

    /**
     * @var StripeCustomerRepository
     */
    private $stripeCustomerRepository;


    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var PurchaseFlow
     */
    private $purchaseFlow;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    protected $productClassRepository;

    const LOG_IF = "StripeRecurringNagMethod--stripeRecurringNagMethod---";

    /**
     * CreditCard constructor.
     *
     * EccubeConfig $eccubeConfig
     * @param EntityManagerInterface $entityManager
     * @param OrderStatusRepository $orderStatusRepository
     * @param StripeConfigRepository $stripeConfigRepository
     * @param StripeLogRepository $stripeLogRepository
     * @param StripeOrderRepository $stripeOrderRepository
     * @param StripeCustomerRepository $stripeCustomerRepository
     * @param ContainerInterface $containerInterface
     * @param PurchaseFlow $shoppingPurchaseFlow
     * @param SessionInterface $session
     */
    public function __construct(
        EccubeConfig $eccubeConfig,
        EntityManagerInterface $entityManager,
        OrderStatusRepository $orderStatusRepository,
        StripeConfigRepository $stripeConfigRepository,
        StripeLogRepository $stripeLogRepository,
        StripeOrderRepository $stripeOrderRepository,
        StripeCustomerRepository $stripeCustomerRepository,
        ContainerInterface $containerInterface,
        PurchaseFlow $shoppingPurchaseFlow,
        SessionInterface $session,
        ProductClassRepository $productClassRepository
    ) {
        $this->eccubeConfig=$eccubeConfig;
        $this->entityManager = $entityManager;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->stripeConfigRepository = $stripeConfigRepository;
        $this->stripeLogRepository = $stripeLogRepository;
        $this->stripeOrderRepository = $stripeOrderRepository;
        $this->stripeCustomerRepository = $stripeCustomerRepository;
        $this->container = $containerInterface;
        $this->purchaseFlow = $shoppingPurchaseFlow;
        $this->session = $session;

        $this->productClassRepository = $productClassRepository;
        $this->dispatcher = $this->container->get('event_dispatcher');
    }

    /**
     * 注文確認画面遷移時に呼び出される.
     *
     * クレジットカードの有効性チェックを行う.
     *
     * @return PaymentResult
     *
     * @throws \Eccube\Service\PurchaseFlow\PurchaseException
     */
    public function verify()
    {
        // $customer_id = $this->session->getFlashBag()->get("stripe_customer_id");
        // $payment_method_id = $this->session->getFlashBag()->get("payment_method_id");
        // $purchase_point = $this->session->getFlashBag()->get("purchase_point");

        $result = new PaymentResult();
        
        $order_items = $this->Order->getProductOrderItems();
        log_info("StripeRecurringNagMethod---verify---order_item id: ". $order_items[0]->getId());
        foreach($order_items as $order_item){
            $pc = $order_item->getProductClass();
            if(empty($pc) || !$pc->isRegistered()){

                $result->setSuccess(false);                
                $result->setErrors(['stripe_rec.shopping.error.not_recurring_order']);
                return $result;                
            }
        }
        
        $result->setSuccess(true);
        
        return $result;
    }

    /**
     * 注文時に呼び出される.
     *
     * 受注ステータス, 決済ステータスを更新する.
     * ここでは決済サーバとの通信は行わない.
     *
     * @return PaymentDispatcher|null
     */
    public function apply()
    {
        // 受注ステータスを決済処理中へ変更
        $OrderStatus = $this->orderStatusRepository->find(OrderStatus::PENDING);
        $this->Order->setOrderStatus($OrderStatus);

        // purchaseFlow::prepareを呼び出し, 購入処理を進める.
        $this->purchaseFlow->prepare($this->Order, new PurchaseContext());

    }

    /**
     * 注文時に呼び出される.
     *
     * クレジットカードの決済処理を行う.
     *
     * @return PaymentResult
     */
    public function checkout()
    {
        $config_service = $this->container->get("plg_stripe_rec.service.admin.plugin.config");
        $rec_config = $config_service->getConfig();
        if (!$rec_config['multi_product']) {
            return $this->multi_checkout($rec_config);
        }

        $result = new PaymentResult();
        // 決済サーバに仮売上のリクエスト送る(設定等によって送るリクエストは異なる)
        // ...
        log_info(self::LOG_IF . "---nag method checkout");
        $customer_id = $this->session->getFlashBag()->get("stripe_customer_id");
        $payment_method_id = $this->session->getFlashBag()->get("payment_method_id");
        $purchase_point = $this->session->getFlashBag()->get("purchase_point");
        $bundle_include_arr = $this->session->get('bundle_include_arr');
        
        $customer_id = $customer_id[0];
        $payment_method_id = $payment_method_id[0];
        $purchase_point = $purchase_point[0];
        if($bundle_include_arr){
            $bundle_include_arr = $bundle_include_arr;
            $this->session->set('bundle_include_arr', null);
        }

        log_info(self::LOG_IF . "---purchase_point : ". $purchase_point);

        $StripeConfig = $this->stripeConfigRepository->getConfigByOrder($this->Order);
        // $stripeClient = new StripeClient($StripeConfig->secret_key);
        Stripe::setApiKey($StripeConfig->secret_key);
        
        $coupon_enable = $rec_config[ConfigService::COUPON_ENABLE];

        try{
            $payment_method = PaymentMethod::retrieve($payment_method_id);
            $payment_method->attach([
                'customer' => $customer_id
            ]);
            
        }catch(Exception $e){
            $result->setSuccess(false);
            $result->setErrors([trans('stripe_recurring.checkout.payment_method.retrieve_error')]);
            return $result;
        }
        StripeLibCustomer::update($customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $payment_method_id
            ]
        ]);

        $order_items = $this->Order->getProductOrderItems();
        $product_class = $order_items[0]->getProductClass();
        if(!$product_class->isRegistered()){
            throw new Exception;
        }

        $subscription_items_pre = [];
        $subscription_items = [];
        $subscription_items_initial = [];
        $subscription_items_initial_pre = [];

        $initial_price = 0;
        $lastProductInterval="";
        foreach($order_items as $order_item){
            $pc = $order_item->getProductClass();

            if(empty($pc) || !$pc->isRegistered()){
                return false;
            }
            if($pc->isInitialPriced()){
                if(empty( $subscription_items_initial_pre[$pc->getStripePriceId()])){
                    $subscription_items_initial_pre[$pc->getStripePriceId()] = [
                        'price_data'=>  [
                            'currency'  => strtolower($this->Order->getCurrencyCode()),
                            'product'   =>  $pc->getProduct()->getStripeProdId(),
                            'recurring' =>  [
                                'interval'  =>  $pc->getInterval()
                            ],
                            'unit_amount'   =>  self::getAmountToSentInStripe($pc->getInitialPriceIncTax(),strtolower($this->Order->getCurrencyCode())),
                        ],
                        'quantity'  =>  $order_item->getQuantity()
                    ];
                }else{
                    $subscription_items_initial_pre[$pc->getStripePriceId()]['quantity'] += $order_item->getQuantity();                    
                }
                $initial_price += $pc->getInitialPriceIncTax() * $order_item->getQuantity();
            }else{
                $initial_price += $pc->getPrice02IncTax() * $order_item->getQuantity();
            }
            if(empty( $subscription_items_pre[$pc->getStripePriceId()])){
                $subscription_items_pre[$pc->getStripePriceId()] = [
                    'price' => $pc->getStripePriceId(),
                    'quantity'  =>  $order_item->getQuantity()
                ];
            }else{
                $subscription_items_pre[$pc->getStripePriceId()]['quantity'] += $order_item->getQuantity();
            }
            $lastProductInterval=$pc->getInterval();
        }

        //BOC add shipping fee
        $stripeShippingProductId=$this->getShippingProductId();
        if(!empty($stripeShippingProductId)) {
            $stripeShippingTotal = $this->Order->getDeliveryFeeTotal();
            $subscription_items_initial_pre[$stripeShippingProductId] = [
                'price_data' => [
                    'currency' => strtolower($this->Order->getCurrencyCode()),
                    'product' => $stripeShippingProductId,
                    'recurring' => [
                        'interval' => (!empty($lastProductInterval))?$lastProductInterval:"month"
                    ],
                    'unit_amount' => self::getAmountToSentInStripe($stripeShippingTotal, strtolower($this->Order->getCurrencyCode())),
                ],
                'quantity' => 1
            ];
            $initial_price +=$stripeShippingTotal;
        }
        //EOC add shipping fee

        foreach($subscription_items_pre as $item){
            $subscription_items[] = $item;
        }
        if(!empty($subscription_items_initial_pre)){
            foreach($subscription_items_pre as $key => $item){
                if(isset($subscription_items_initial_pre[$key] )){
                    $subscription_items_initial[] = $subscription_items_initial_pre[$key];
                }else{
                    $subscription_items_initial[] = $item;                    
                }
            }
        }
        
        $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');

        // BOC --- compose subscription phases
        $bundles = $pb_service->getBundleProducts($this->Order, $bundle_include_arr);        
        if(empty($bundles)){
            if(empty($subscription_items_initial_pre)){
                $phases = [
                    [
                        'items' =>  $subscription_items,
                        'proration_behavior' => 'none',
                    ],
                ];
            }else{
                $phases = [
                    [
                        'items'     => $subscription_items_initial,
                        'iterations'=> 1,
                        'proration_behavior' => 'none',      
                    ],
                    [
                        'items' =>  $subscription_items,
                        'proration_behavior' => 'none',
                    ],
                ];
            }
        }else{
            $items = [];
            if(empty($subscription_items_initial_pre)){                
                $items = array_merge($subscription_items, $bundles['phase_items']);
            }else{
                $items = array_merge($subscription_items_initial, $bundles['phase_items']);
            }
            $phases = [
                [
                    'items'     => $items,
                    'iterations'=> 1,
                    'proration_behavior' => 'none',
                ],
                [
                    'items'     =>  $subscription_items,
                    'proration_behavior' => 'none',
                ]
            ];

            $initial_price += $bundles['price'];
        }

        $initial_price=self::getAmountToSentInStripe($initial_price,strtolower($this->Order->getCurrencyCode()));
        // EOC --- compose subscription phases
        $interval = $order_items[0]->getProductClass()->getInterval();
        if($this->isProrateOption($purchase_point, $interval)){
            $phases = [
                [
                    'items' =>  $subscription_items,
                    'proration_behavior' => 'none',
                ],
            ];
        }

        $schedule_params = $this->paydayOptionProcess([
            'customer'      =>  $customer_id,
            'start_date'    =>  $purchase_point,
            'end_behavior' =>  'release',
            'phases'        =>  $phases
        ], $initial_price, $order_items[0]->getProduct()->getStripeProdId(), $interval,strtolower($this->Order->getCurrencyCode()));

        if($coupon_enable){
            $coupon_id = $_REQUEST['coupon_id'];
            if(!empty($coupon_id)){
                foreach($schedule_params['phases'] as $k => $v){
                    $schedule_params['phases'][$k]['coupon'] = $coupon_id;
                }
            
                $coupon_service = $this->container->get('plg_stripe_rec.service.coupon_service');
                $coupon_data = $coupon_service->retrieveCoupon($coupon_id);
                if(empty($coupon_data)){
                    $result->setSuccess(false);
                    $result->setErrors([ $coupon_service->getError() ]);
                    return $result;
                }
                $coupon_discount = $coupon_service->couponDiscountAmount($initial_price, $coupon_data);
                if($coupon_discount === false){
                    $result->setSuccess(false);
                    $result->setErrors([ $coupon_service->getError() ]);
                    return $result;
                }
            }
        }

        if(empty($bundles) && $purchase_point === "now" && empty($subscription_items_initial_pre) && count($schedule_params['phases']) === 1){
            $subscription_data = [
                'customer' => $customer_id,
                'items'    => $subscription_items,
                'expand' => ['latest_invoice.payment_intent']
            ];
            if(!empty($coupon_id)){
                $subscription_data['coupon'] = $coupon_id;
            }
            $subscription = Subscription::create($subscription_data);
            $stripeOrder = $this->entityManager->getRepository(StripeRecOrder::class)->findOneBy([
                'subscription_id'       =>  $subscription->id,
                'stripe_customer_id'    =>  $customer_id
            ]);
            if(empty($stripeOrder)){
                log_info(StripeRecurringNagMethod::LOG_IF . "stripe order is empty in paymentmethod");
                $stripeOrder = new StripeRecOrder();
            }
            if($subscription){
                $stripeOrder->copyFrom($subscription);
            }
            $stripeOrder->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
            $stripeOrder->setRecStatus(StripeRecOrder::REC_STATUS_ACTIVE);
            $stripeOrder->setStartDate(new \DateTime());
        }else{
            $subscription_schedule = SubscriptionSchedule::create($schedule_params);
            log_info(self::LOG_IF . "--- subscription schedule created.");
            log_info($subscription_schedule);
            $subscription_id = $subscription_schedule->subscription;

            if(isset($subscription_schedule['error'])){
                $result->setSuccess(false);
                $result->setErrors([trans('stripe_recurring.subscribe.failed')]);
                return $result;
            }
            
            log_info(self::LOG_IF);
            $stripeOrder = new StripeRecOrder();
            $stripeOrder->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED);
            $stripeOrder->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
            $stripeOrder->setOrder($this->Order);
            $stripeOrder->setSubscriptionId($subscription_id);
            
            $dt = new \DateTime();
            if($purchase_point === "now"){
                if($subscription_schedule->current_phase){
                    $dt->setTimestamp($subscription_schedule->current_phase->start_date);
                }
            }else{
                $dt->setTimestamp($purchase_point);
            }
            $stripeOrder->setStartDate($dt);
            if(!empty($bundles)){
                $stripeOrder->setBundling($bundles['str']);
            }
            $stripeOrder->setScheduleId($subscription_schedule->id);
        }
        // if($subscription_id){
        //     
        //         $subscription = Subscription::retrieve($subscription_id);
        //         
        //     }
        // }
        // else{
            
        // }
        $stripeOrder->setOrder($this->Order);
        $stripeOrder->setStripeCustomerId($customer_id);
        $stripeOrder->setCustomer($this->Order->getCustomer());

        if(!empty($coupon_id)){
            $stripeOrder->setCouponId($coupon_id);
            $stripeOrder->setCouponDiscountStr($coupon_service->couponDiscountStr($coupon_data));
            $stripeOrder->setCouponName($coupon_data->name);
        }
        
        $this->entityManager->persist($stripeOrder);
        $this->entityManager->flush();
        $this->entityManager->commit();

        // $order_items = $order->getMergedProductOrderItems();
        $subscription_items = [];
        foreach($order_items as $order_item){
            $pc = $order_item->getProductClass();

            if(empty($pc) || !$pc->isRegistered()){
                $this->entityManager->remove($stripeOrder);
                $this->entityManager->flush();
                return false;
            }
            $rec_item = new StripeRecOrderItem();
            $rec_item->copyOrderItem($order_item);
            $rec_item->setRecOrder($stripeOrder);            
            $stripeOrder->addOrderItem($rec_item);
            
            $this->entityManager->persist($rec_item);
        }
        
        // there can be order items with same interval for current requirement. so select the first item in the order.
        $stripeOrder->setInterval($order_items[0]->getProductClass()->getInterval());
        $this->entityManager->persist($stripeOrder);
        $this->entityManager->commit();
        $this->entityManager->flush();

        $this->Order->setRecOrder($stripeOrder);
        $this->Order->setOrderDate(new \DateTime());
        $this->entityManager->persist($this->Order);
        // $this->entityManager->commit();
        $this->entityManager->flush();


        // purchaseFlow::commitを呼び出し, 購入処理を完了させる.
        // $this->purchaseFlow->commit($this->Order, new PurchaseContext());

        
        $result->setSuccess(true);
        //EOC create stripe Order

        return $result;
    }

    private function multi_checkout($rec_config)
    {
        $result = new PaymentResult();
        log_info(self::LOG_IF . "---nag method checkout");
        $customer_id = $this->session->getFlashBag()->get("stripe_customer_id");
        $payment_method_id = $this->session->getFlashBag()->get("payment_method_id");
        $purchase_point = $this->session->getFlashBag()->get("purchase_point");
        $bundle_include_arr = $this->session->get('bundle_include_arr');
        
        $customer_id = $customer_id[0];
        $payment_method_id = $payment_method_id[0];
        $purchase_point = $purchase_point[0];
        if($bundle_include_arr){
            $bundle_include_arr = $bundle_include_arr;
            $this->session->set('bundle_include_arr', null);
        }

        $StripeConfig = $this->stripeConfigRepository->getConfigByOrder($this->Order);
        // $stripeClient = new StripeClient($StripeConfig->secret_key);
        Stripe::setApiKey($StripeConfig->secret_key);
        $coupon_enable = $rec_config[ConfigService::COUPON_ENABLE];

        try{
            $payment_method = PaymentMethod::retrieve($payment_method_id);
            $payment_method->attach([
                'customer' => $customer_id
            ]);
            
        }catch(Exception $e){
            $result->setSuccess(false);
            $result->setErrors([trans('stripe_recurring.checkout.payment_method.retrieve_error')]);
            return $result;
        }
        StripeLibCustomer::update($customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $payment_method_id
            ]
        ]);

        $pb_service = $this->container->get("plg_stripe_rec.service.pointbundle_service");
        if ($coupon_enable) {
            $coupon_id = $_REQUEST['coupon_id'];
            if (!empty($coupon_id)) {
                $coupon_service = $this->container->get('plg_stripe_rec.service.coupon_service');
                $coupon_data = $coupon_service->retrieveCoupon($coupon_id);
                if (empty($coupon_data)) {
                    $result->setSuccess(false);
                    $result->setErrors([$coupon_service->getError()]);
                    return $result;
                }
            }
        }
        
        $order_items = $this->Order->getProductOrderItems();
        $errors = [];

        $subscription_count = 0;
        $isDeliveryFeeAdded=false;

        foreach($order_items as $order_item) {
            $pc = $order_item->getProductClass();
            $initial_price = 0;
            $interval = $pc->getInterval();

            $bundle_product = null;
            if (!empty($bundle_include_arr[$order_item->getId()])) {
                $bundle_code = $pc->getBundleProduct();
                if ($bundle_code) {
                    $bundle_item = $this->productClassRepository->findOneBy(['code'  =>  $bundle_code]);
                    if ($bundle_item && $bundle_item->getProduct()->isStripeProduct()) {
                        $bundle_product = [
                            'price_data'    =>  [
                                'currency'  =>  strtolower($this->Order->getCurrencyCode()),
                                'product'   =>  $bundle_item->getProduct()->getStripeProdId(),
                                'recurring' =>  [
                                    'interval'  =>  $interval,
                                ],
                                'unit_amount'   =>  self::getAmountToSentInStripe($bundle_item->getPrice02IncTax(),strtolower($this->Order->getCurrencyCode())),
                            ],
                            'quantity'  =>  1,
                        ];
                        $initial_price += $bundle_item->getPrice02IncTax();
                    }
                };
            }

            
            if (empty($pc) || !$pc->isRegistered()) {
                return false;
            }
            $initial_items = [];
            

            if ($pc->isInitialPriced()) {
                $initial_items[] = [
                    'price_data'    =>  [
                        'currency'  =>  strtolower($this->Order->getCurrencyCode()),
                        'product'   =>  $pc->getProduct()->getStripeProdId(),
                        'recurring' =>  [
                            'interval'  =>  $interval,
                        ],
                        'unit_amount'   =>  self::getAmountToSentInStripe($pc->getInitialPriceIncTax(),strtolower($this->Order->getCurrencyCode())),
                    ],
                    'quantity'  =>  1 //$order_item->getQuantity()
                ];
                $initial_price += $pc->getInitialPriceIncTax(); // * $order_item->getQuantity();
            } else {
                $initial_price += $pc->getPrice02IncTax(); // * $order_item->getQuantity();
            }

            $initial_price=self::getAmountToSentInStripe($initial_price,strtolower($this->Order->getCurrencyCode()));

            $subscription_items = [];
            $subscription_items[] = [
                'price' =>  $pc->getStripePriceId(),
                'quantity'  =>  1, // $order_item->getQuantity()
            ];

            //BOC add shipping fee
            if(!$isDeliveryFeeAdded) {
                $lastProductInterval=$pc->getInterval();
                $stripeShippingProductId = $this->getShippingProductId();
                if (!empty($stripeShippingProductId)) {
                    $stripeShippingTotal = $this->Order->getDeliveryFeeTotal();
                    $subscription_items[] = [
                        'price_data' => [
                            'currency' => strtolower($this->Order->getCurrencyCode()),
                            'product' => $stripeShippingProductId,
                            'recurring' => [
                                'interval' => (!empty($lastProductInterval)) ? $lastProductInterval : "month"
                            ],
                            'unit_amount' => self::getAmountToSentInStripe($stripeShippingTotal, strtolower($this->Order->getCurrencyCode())),
                        ],
                        'quantity' => 1
                    ];
                    $initial_price += $stripeShippingTotal;
                }
                $isDeliveryFeeAdded=true;
            }
            //EOC add shipping fee

            if (empty($bundle_product)) {
                if (empty($initial_items)) {
                    $phases = [
                        [
                            'items' =>  $subscription_items,
                            'proration_behavior'    =>  'none',
                        ],
                    ];
                } else {
                    $phases = [
                        [
                            'items' =>  $initial_items,
                            'iterations'    =>  1,
                            'proration_behavior'    =>  'none',
                        ],
                        [
                            'items' =>  $subscription_items,
                            'proration_behavior'    =>  'none'
                        ]
                    ];
                }
            } else {
                if (empty($initial_items)) {
                    $items = array_merge($subscription_items, [ $bundle_product ]);
                } else {
                    $items = array_merge($initial_items, [ $bundle_product ]);
                }

                $phases = [
                    [
                        'items' =>  $items,
                        'iterations'    =>  1,
                        'proration_behavior'    =>  'none',
                    ],
                    [
                        'items' =>  $subscription_items,
                        'proration_behavior'    =>  'none'
                    ]
                ];
            }

            
            if ($this->isProrateOption($purchase_point, $interval)) {
                $phases = [
                    [
                        'items' =>  $subscription_items,
                        'proration_behavior'    =>  'none',
                    ]
                ];
            }

            $schedule_params = $this->paydayOptionProcess([
                'customer'  =>  $customer_id,
                'start_date'=>  $purchase_point,
                'end_behavior'=>    'release',
                'phases'    =>  $phases,
            ], $initial_price, $order_item->getProduct()->getStripeProdId(), $interval,strtolower($this->Order->getCurrencyCode()));

            if (!empty($coupon_data)) {
                $coupon_discount = $coupon_service->couponDiscountAmount($initial_price, $coupon_data);
                if ($coupon_discount === false) {
                    $errors[] = $coupon_service->getError();
                    continue;
                }
                foreach($schedule_params['phases'] as $k => $v) {
                    $schedule_params['phases'][$k]['coupon'] = $coupon_id;
                }
            }
            $quantity = $order_item->getQuantity();
            for ($i = 0; $i < $quantity; $i++) {
                if (empty($bundle_product) && $purchase_point === "now" && empty($initial_items) && count($schedule_params['phases']) === 1) {
                    $subscription_data = [
                        'customer'  =>  $customer_id,
                        'items'     =>  $subscription_items,
                        'expand'    =>  ['latest_invoice.payment_intent'],
                    ];

                    if (!empty($coupon_id)) {
                        $subscription_data['coupon'] = $coupon_id;
                    }
                    $subscription = Subscription::create($subscription_data);
                    $stripeOrder = $this->entityManager->getRepository(StripeRecOrder::class)->findOneBy([
                        'subscription_id'   =>  $subscription->id,
                        'stripe_customer_id'=>  $customer_id,
                    ]);

                    if (empty($stripeOrder)) {
                        $stripeOrder = new StripeRecOrder;

                    }
                    if ($subscription) {
                        $stripeOrder->copyFrom($subscription);
                    }
                    $stripeOrder->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
                    if (empty($stripeOrder->getRecStatus())) {
                        $stripeOrder->setRecStatus(StripeRecOrder::REC_STATUS_ACTIVE);
                    }
                    $stripeOrder->setStartDate(new \DateTime());
                    log_info("Subscription Created");
                } else {
                    $subscription_schedule = SubscriptionSchedule::create($schedule_params);
                    $subscription_id = $subscription_schedule->subscription;

                    if (isset($subscription_schedule['error'])) {
                        $errors[] = $subscription_schedule['error'];
                        continue;
                    }

                    $stripeOrder = new StripeRecOrder();
                    $stripeOrder->setRecStatus(StripeRecOrder::REC_STATUS_SCHEDULED);
                    $stripeOrder->setPaidStatus(StripeRecOrder::STATUS_PAY_UNDEFINED);
                    $stripeOrder->setSubscriptionId($subscription_id);
                    
                    $dt = new \DateTime();
                    if ($purchase_point === "now") {
                        if ($subscription_schedule->current_phase) {
                            $dt->setTimestamp($subscription_schedule->current_phase->start_date);
                        }
                    } else {
                        $dt->setTimestamp($purchase_point);
                    }
                    $stripeOrder->setStartDate($dt);
                    $stripeOrder->setScheduleId($subscription_schedule->id);
                    log_info("Subscription Schedule Created");
                }
                if (empty($stripeOrder->getInterval())) {
                    $stripeOrder->setInterval($interval);
                }
                $stripeOrder->setOrder($this->Order);
                $stripeOrder->setOrderItemId($order_item->getId());
                $stripeOrder->setStripeCustomerId($customer_id);
                $stripeOrder->setCustomer($this->Order->getCustomer());


                if (!empty($coupon_id)) {
                    $stripeOrder->setCouponId($coupon_id);
                    $stripeOrder->setCouponDiscountStr($coupon_service->couponDiscountStr($coupon_data));
                    $stripeOrder->setCouponName($coupon_data->name);
                }
                if (!empty($bundle_product)) {
                    $stripeOrder->setBundling($bundle_code . ":" . $bundle_item->getPrice02IncTax());
                }
                $this->entityManager->persist($stripeOrder);
                
                $rec_item = new StripeRecOrderItem();
                $rec_item->copyOrderItem($order_item);
                $rec_item->setQuantity(1);
                $rec_item->setRecOrder($stripeOrder);
                $stripeOrder->addOrderItem($rec_item);
                $this->entityManager->persist($rec_item);

                $this->entityManager->flush();
                $this->entityManager->commit();
                $subscription_count++;
                $args = new EventArgs(
                    [
                        'Order' =>  $this->Order,
                        'rec_order'      =>  $stripeOrder,
                    ]
                );
                log_info("rec_order_created dispatching...");
                $this->dispatcher->dispatch(StripeRecEvent::REC_ORDER_CREATED, $args);
            }
        }

        if ($subscription_count == 0) {
            $result->setSuccess(false);
            $result->setErrors($errors);
            return $result;
        }
        $result->setSuccess(true);

        $this->dispatcher->dispatch(StripeRecEvent::RECURRING_CHECKOUT_FINALIZE, new EventArgs([
            'Order' =>  $this->Order,
            'rec_order' =>  $stripeOrder
        ]));

        return $result;

    }


    /**
     * {@inheritdoc}
     */
    public function setFormType(FormInterface $form)
    {
        $this->form = $form;
    }

    /**
     * {@inheritdoc}
     */
    public function setOrder(Order $Order)
    {
        $this->Order = $Order;
    }

    private function writeRequestLog(Order $order, $api) {
        $logMessage = '[Order' . $order->getId() . '][' . $api . '] リクエスト実行';
        log_info($logMessage);

        $stripeLog = new StripeLog();
        $stripeLog->setMessage($logMessage);
        $stripeLog->setCreatedAt(new \DateTime());
        $this->entityManager->persist($stripeLog);
        $this->entityManager->flush($stripeLog);
    }

    private function writeResponseLog(Order $order, $api, $result) {
        $logMessage = '[Order' . $order->getId() . '][' . $api . '] ';
        if (is_object($result)) {
            $logMessage .= '成功';
        } elseif (! is_array($result)) {
            $logMessage .= print_r($result, true);
        } elseif (isset($result['error'])) {
            $logMessage .= $result['error']['message'];
        } else {
            $logMessage .= '成功';
        }
        log_info($logMessage);
        $stripeLog = new StripeLog();
        $stripeLog->setMessage($logMessage);
        $stripeLog->setCreatedAt(new \DateTime());
        $this->entityManager->persist($stripeLog);
        $this->entityManager->flush($stripeLog);
    }

    protected function generateUrl($route, $parameters = array(), $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH)
    {
        return $this->container->get('router')->generate($route, $parameters, $referenceType);
    }

    public function paydayOptionProcess($schedule_params, $initial_price, $temp_product_id, $interval,$currency='jpy'){

        $phases = $schedule_params['phases'];
        $purchase_point = $schedule_params['start_date'];

        $config_service = $this->container->get('plg_stripe_rec.service.admin.plugin.config');
        
        $payday_options = $config_service->getPaymentDateOptions();
        

        $option_1 = $payday_options[ConfigService::PAYDAY_OPTION_1];
        $option_2 = $payday_options[ConfigService::PAYDAY_OPTION_2];
        $payment_date = $payday_options[ConfigService::PAYMENT_DATE];
        $pay_full = $payday_options[ConfigService::PAY_FULL];
        
        $start_time = new \DateTime();
        if($purchase_point != "now"){
            $start_time->setTimestamp($purchase_point);
        }

        if($interval == "day"){
            return $schedule_params;
        }
        $next_payday = new \DateTime();
        $next_payday->setTimestamp($start_time->getTimestamp());
        if($interval == "week" || $interval == "year" ){
            if(!$option_1){
                return $schedule_params;
            }
            $first_day_period = $this->getFirstDayOfPeriod($purchase_point, $interval);
            if($first_day_period === false){
                return $schedule_params;
            }
            if($interval == "week"){
                $w = $start_time->format('N');
                $diff = new \DateInterval('P' . (7 - $w + 1) . 'D');
                
                $next_payday->add($diff);
                
                if($pay_full){
                    $initial_payment = $initial_price;
                }else{
                    $initial_payment = $initial_price * (7 - $w + 1) / 7;
                }
            }
            if($interval == "year"){
                $d = $start_time->format('z');
                $days_year = $start_time->format('L') == 1 ? 366 : 365;
                $diff = new \DateInterval('P' . ($days_year - $d) . 'D');
                $next_payday->add($diff);
                if($pay_full){
                    $initial_payment = $initial_price;
                }else{
                    $initial_payment = $initial_price * ($days_year - $d) / $days_year;
                }
            }
        }else{
            // for monthly recurring
            $day = $start_time->format("j");
            $days_of_month = $start_time->format("t");
            
            if($option_1 && !$option_2){
                if($day > 1){
                    $diff = new \DateInterval('P'. ($days_of_month - $day + 1) . 'D');   
                    
                    $next_payday->add($diff);
                    if($pay_full){
                        $initial_payment = $initial_price;
                    }else{
                        $initial_payment = $initial_price * ($days_of_month - $day + 1) / $days_of_month;
                    }
                }else{
                    return $schedule_params;
                }
            } else if(!$option_1 && $option_2){
                // calculate initial payment
                
                if($day < $payment_date && $days_of_month < $payment_date ){
                    $payment_date = $days_of_month;
                }

                if($day == $payment_date){
                    return $schedule_params;
                }

                if($day < $payment_date){
                    $diff = new \DateInterval('P' . ($payment_date - $day) . 'D');
                    $next_payday->add($diff);
                }else{
                    // BOC---consider 29, 30, 31
                    $next_month_days = $this->getNextMonthDays($start_time);
                    if($next_month_days < $payment_date){
                        $payment_date = $next_month_days;
                    }
                    // EOC---consider 29, 30, 31
                    $diff = new \DateInterval('P' . ($days_of_month - $day + $payment_date) . "D");
                    $next_payday->add($diff);
                }
                if($pay_full){
                    $initial_payment = $initial_price;
                }else{
                    // $days_of_month
                    if($day < $payment_date){
                        $initial_payment = $initial_price * ($payment_date - $day) / $days_of_month;
                        
                    }else{
                        $initial_payment = $initial_price * ($days_of_month - $day + $payment_date) / $days_of_month;
                    }
                }
            }else if($option_1 && $option_2){
                if($day < $payment_date && $days_of_month < $payment_date ){
                    $payment_date = $days_of_month;
                }
                if($day == $payment_date){
                    return $schedule_params;
                }
                if($day < $payment_date){                    
                    $diff = new \DateInterval('P' . ($payment_date - $day) . 'D');
                    $next_payday->add($diff);
                }else{
                    // BOC---consider 29, 30, 31
                    $next_month_days = $this->getNextMonthDays($start_time);
                    if($next_month_days < $payment_date){
                        $payment_date = $next_month_days;
                    }
                    // EOC---consider 29, 30, 31
                    $diff = new \DateInterval('P' . ($days_of_month - $day + $payment_date) . "D");
                    $next_payday->add($diff);
                }
                if($pay_full){
                    $initial_payment = $initial_price;
                }else{
                    if($day < $payment_date){
                        $initial_payment = $initial_price * ($payment_date - 1) / $days_of_month;
                    }else{
                        $initial_payment = $initial_price * ($days_of_month - 1 + $payment_date) / $days_of_month;
                    }
                }
            }else{
                return $schedule_params;
            }

        }

        $phase_first_prod = [
            'items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product' => $temp_product_id,
                        'recurring' => [
                            'interval' => $interval
                        ],
                        'unit_amount' => floor($initial_payment),
                    ],
                    'quantity' => 1
                ]
            ],
            'end_date' => $next_payday->getTimestamp(),
            'proration_behavior' => 'none',
        ];
        array_unshift($phases, $phase_first_prod);
        $phases[1]['billing_cycle_anchor'] = "phase_start";
        $schedule_params['phases'] = $phases;
        return $schedule_params;
    }

    public function isProrateOption($purchase_point, $interval){
        $config_service = $this->container->get('plg_stripe_rec.service.admin.plugin.config');
        $option_1 = $config_service->get(ConfigService::PAYDAY_OPTION_1);
        $option_2 = $config_service->get(ConfigService::PAYDAY_OPTION_2);
        $payment_date = $config_service->get(ConfigService::PAYMENT_DATE);

        if(!$option_1 && !$option_2){
            return false;
        }
        if($interval == "day"){
            return false;
        }
        if($interval == "week" || $interval == "year"){
            if(!$option_1){
                return false;
            }
            if($this->getFirstDayOfPeriod($purchase_point, $interval) === false){
                return false;
            }
            return true;
        }
        $start_time = new \DateTime();
        if($purchase_point != "now"){
            $start_time->setTimestamp($purchase_point);
        }

        $day = $start_time->format("j");

        
        
        if($option_1 && !$option_2 && $day == 1){
            return false;
        }
        if($day == $payment_date && $option_2){
            return false;
        }
        if(!$option_1 && $option_2 && $day == $payment_date){
            return false;
        }
        if($option_1 && $option_2 && $day == $payment_date){
            return false;
        }
        return true;
    }

    /**
     * Only for week, and year
     */
    private function getFirstDayOfPeriod($purchase_point, $interval){
        $start_time = new \DateTime();
        if($purchase_point != "now"){
            $start_time->setTimestamp($purchase_point);
        }
        if($interval == "week"){
            $w = $start_time->format('N');
            if($w === 1){
                return false;
            }
            $diff = new \DateInterval('P' . ($w - 1) . 'D');
            $diff->invert = 1;
            $start_time->add($diff);
            return $start_time;
        }
        if($interval == "year"){
            $d = $start_time->format('z');
            if($d === 0){
                return false;
            }
            $diff = new \DateInterval('P' . $d . 'D');
            $diff->invert = 1;
            $start_time->add($diff);
            return $start_time;
        }
        return null;
    }

    private function getNextMonthDays($base_date){
        $days_of_month = $base_date->format('t');
        $day = $base_date->format('j');
        $new_tt = new \DateTime();
        $new_tt->setTimestamp($base_date->getTimestamp());
        $diff_test = new \DateInterval('P' . ($days_of_month - $day + 5) . 'D');
        $new_tt->add($diff_test);

        return $new_tt->format('t');
    }

    private function getShippingProductId(){
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
        $stripeShippingProduct = $this->entityManager->getRepository(StripeRecShippingProduct::class)->get();
        if(empty($stripeShippingProduct) || !$stripe_service->validateShippingProductExist($stripeShippingProduct->getStripeShippingProdId())){
            if(empty($stripeShippingProduct)) {
                $stripeShippingProduct = new StripeRecShippingProduct();
            }
            $name='送料';
            $description='送料';
            $res = $stripe_service->registerShippingProduct($name,$description,$stripeShippingProduct);
            $stripeShippingProduct = $this->entityManager->getRepository(StripeRecShippingProduct::class)->get();
        }

        return $stripeShippingProduct->getStripeShippingProdId();
    }

    public function getAmountToSentInStripe($amount, $currency)
    {
        if(!in_array($currency, $this->zeroDecimalCurrencies)){
            return (int)($amount*100);
        }
        return (int)$amount;
    }
}
