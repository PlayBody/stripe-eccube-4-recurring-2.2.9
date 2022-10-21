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

namespace Plugin\StripeRec;

// if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
//     include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
// }
use Eccube\Common\EccubeConfig;
use Eccube\Event\TemplateEvent;
use Eccube\Event\EventArgs;
use Eccube\Entity\Payment;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Plugin\StripePaymentGateway\Repository\StripeConfigRepository;
use Plugin\StripePaymentGateway\Service\Method\StripeCreditCard;
use Plugin\StripePaymentGateway\Entity\StripeOrder;
use Plugin\StripePaymentGateway\Repository\StripeOrderRepository;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;
use Plugin\StripePaymentGateway\StripeClient;
use Plugin\StripeRec\Service\ConfigService;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Entity\Customer as Customer;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Event\EccubeEvents;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Plugin\StripeRec\Service\Method\StripeRecurringNagMethod;
use Eccube\Service\OrderHelper;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormError;
use Plugin\StripeRec\Entity\PurchasePoint;
use Eccube\Entity\ProductClass;
use Eccube\Form\Type\PriceType;
use Eccube\Common\Constant;

class StripeRecEvent implements EventSubscriberInterface
{
    const NEW_CHARGE_ORDER_COPIED = "plg_striperec.new_charge_order_copied";
    const REC_ORDER_CREATED = "plg_striperec.rec_order_created";
    const RECURRING_CHECKOUT_FINALIZE = "plg_striperec.rec_order_checkout_finalize";
    const ADMIN_RECORDER_INDEX_COMPLETE = "plg_striperec.admin.rec_order_index_complete";
    const REC_ORDER_SUBSCRIPTION_PAID = "plg_striperec.rec_order_subscription_paid";


    /**
     * @var エラーメッセージ
     */
    private $errorMessage = null;

    /**
     * @var 国際化
     */
    private static $i18n = array();

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var StripeConfigRepository
     */
    protected $stripeConfigRepository;

    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var StripeOrderRepository
     */
    private $stripeOrderRepository;

    /**
     * @var StripeCustomerRepository
     */
    private $stripeCustomerRepository;

    /**
     * @var string ロケール（jaかenのいずれか）
     */
    private $locale = 'en';

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var Session
     */
    protected $session;

    protected $container;
    protected $util_service;    

    public function __construct(
        EccubeConfig $eccubeConfig,
        StripeConfigRepository $stripeConfigRepository,
        StripeOrderRepository $stripeOrderRepository,
        OrderStatusRepository $orderStatusRepository,
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        ContainerInterface $container
    )
    {
        $this->eccubeConfig = $eccubeConfig;
        $this->locale=$this->eccubeConfig['locale'];
        $this->stripeConfigRepository = $stripeConfigRepository;
        $this->stripeOrderRepository = $stripeOrderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->entityManager = $entityManager;
        $this->session = $session;
        $this->container = $container;
        $this->util_service = $this->container->get("plg_stripe_payment.service.util");     
        $this->stripeCustomerRepository = $this->entityManager->getRepository(StripeCustomer::class);
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'Shopping/index.twig'           => 'onShoppingIndexTwig',
            'Shopping/confirm.twig'         => 'onShoppingConfirmTwig',
            "@admin/Product/product.twig"   => "onProductEdit",             
            // 'front.shopping.complete.initialize'=>'onFrontShoppingCompleteInitialize',
            '@admin/Order/edit.twig'               =>  'onAdminOrderEdit',
            'Mypage/index.twig'             => 'myPageNaviRenderBefore',
            'Mypage/history.twig'           => 'myPageNaviRenderBefore',
            'Mypage/favorite.twig'          => 'myPageNaviRenderBefore',
            'Mypage/change.twig'            => 'myPageNaviRenderBefore',
            'Mypage/change_complete.twig'   => 'myPageNaviRenderBefore',
            'Mypage/delivery.twig'          => 'myPageNaviRenderBefore',
            'Mypage/delivery_edit.twig'     => 'myPageNaviRenderBefore',
            'Mypage/withdraw.twig'          => 'myPageNaviRenderBefore',  
            'Cart/index.twig'               =>  'onCartPage',
            'StripeRec/Resource/template/Mypage/recurring_tab.twig' => 'myPageNaviRenderBefore',
            EccubeEvents::MAIL_ORDER => 'sendOrderMailBefore',
            EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE =>  'onProdEditInit',
            EccubeEvents::ADMIN_PRODUCT_EDIT_COMPLETE   =>  'onProdEditComplete',
        ];
    }
    public function onCartPage(TemplateEvent $event) {
        $recItemMap = [];
        $Carts = $event->getParameter('Carts');

        $config_service = $this->container->get('plg_stripe_rec.service.admin.plugin.config');

        $is_multi_product = !$config_service->get(ConfigService::MULTI_PRODUCT);

        if (!$is_multi_product) {
            foreach($Carts as $Cart) {
                $CartItems = $Cart->getItems();
                foreach($CartItems as $item) {
                    $pc = $item->getProductClass();
                    if ($pc->isRegistered()) {
                        $cart_plus_url = $this->container->get('router')->generate('cart_handle_item', 
                            array('operation' => 'up', 'productClassId' => $pc->getId()), UrlGeneratorInterface::ABSOLUTE_URL);
                        $cart_minus_url = $this->container->get('router')->generate('cart_handle_item',
                            array('operation' => 'down', 'productClassId' => $pc->getId()), UrlGeneratorInterface::ABSOLUTE_URL);
                        if (!\in_array($cart_plus_url, $recItemMap)) {
                            $recItemMap[] = $cart_plus_url;
                        }
                        if (!\in_array($cart_minus_url, $recItemMap)) {
                            $recItemMap[] = $cart_minus_url;
                        }
                    }
                }
            }
            if (count($recItemMap) > 0) {
                $event->setParameter('recItemMap', $recItemMap);
                $event->addSnippet('StripeRec/Resource/template/default/Cart/index.js.twig');
            }
        }
    }
    public function onAdminOrderEdit(TemplateEvent $event) 
    {
        $Order = $event->getParameter('Order');
        if ($Order->isInitialRec()) {
            $rec_order = $Order->getRecOrder();

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
            $event->setParameter('rec_order', $rec_order);
            $event->setParameter('bundle_order_items', $bundle_order_items);
            $event->setParameter('initial_amount', $initial_amount);
            $event->setParameter('recurring_amount', $recurring_amount);
            $event->setParameter('initial_discount', $initial_discount);
            $event->setParameter('recurring_discount', $recurring_discount);
            $event->addSnippet('StripeRec/Resource/template/admin/Order/edit.twig');
        }
        if ($Order->getRecOrder()) {
            $event->addSnippet("StripeRec/Resource/template/admin/Order/rec_mail_dialog.css.twig");
        }

    }
    public function onProdEditComplete(EventArgs $args){
        $form = $args->getArgument('form');
        $Product = $args->getArgument('Product');
        
        $def_pc = $Product->getDefaultClass();
        if(!$Product->isStripeProduct() || !$def_pc){
            return;
        }
        // =======add bundle product========
        $bundle_code = $form->get('bundle_product')->getData();
        if($bundle_code){
            $bundle_product = $this->entityManager->getRepository(ProductClass::class)->findOneBy(['code' =>  $bundle_code]);
            if(!empty($bundle_product) && $def_pc != $bundle_product){                
                $def_pc->setBundleProduct($bundle_code);
            }
            if (empty($bundle_product)) {
                $form->get('bundle_product')->addError(new FormError("stripe_recurring.admin.rec_order.error.invalid_bundle_product"));
            }
        } else {
            $def_pc->setBundleProduct("");
        }
        $this->entityManager->persist($def_pc);
        $this->entityManager->flush();

        $bundle_required = $form->get('bundle_required')->getData();
        if($bundle_required !== $def_pc->isBundleRequired()){

            if ($bundle_required && !$bundle_code) {
                $form->get('bundle_product')->addError(new FormError("stripe_recurring.admin.rec_order.error.empty_bundle_product"));
            } else {
                $def_pc->setBundleRequired($bundle_required);
                $this->entityManager->persist($def_pc);
                $this->entityManager->flush();
            }
        }

        //BOC check if first cycle free
        $first_cycle_free = $form->get('first_cycle_free')->getData();
        if($first_cycle_free !== $def_pc->getFirstCycleFree()){
            $def_pc->setFirstCycleFree($first_cycle_free);
            $this->entityManager->persist($def_pc);
            $this->entityManager->flush();
        }
        //EOC check if first cycle free

        if($first_cycle_free){//If first cycle is free make initial price as product price
            $initial_price=0;
            if ($initial_price !== $def_pc->getInitialPrice()) {
                $def_pc->setInitialPrice($initial_price);
                $this->entityManager->persist($def_pc);
                $this->entityManager->flush();
            }
        } else {
            $initial_price = $form->get('initial_price')->getData();
            if ($initial_price !== $def_pc->getInitialPrice()) {
                $def_pc->setInitialPrice($initial_price);
                $this->entityManager->persist($def_pc);
                $this->entityManager->flush();
            }
        }
        
        $register_flg = $form->get('register_flg')->getData();
        // ==========add price object==========
        $price_change_flg = $form->get("price_change_flg")->getData() === "1" ? "update": "register";
        if( (!$def_pc->isRegistered() && (empty($register_flg) || $register_flg === "none")) || ($def_pc->isRegistered() && $price_change_flg !== "update")){
            return;
        }
        
        $util_service = $this->container->get("plg_stripe_recurring.service.util");
        
        $res = $util_service->saveDefaultClass($def_pc, $register_flg, $price_change_flg);
        if(!$res){
            $form->get('register_flg')->addError(new FormError($util_service->getErrMsg()));
        }
    }
    public function onProdEditInit(EventArgs $args){   
        
        $builder = $args->getArgument('builder');
        $Product = $args->getArgument("Product");        
        $def_pc = $Product->getDefaultClass();        
        if($def_pc && $Product->isStripeProduct()){
            $isFirstCycleFree=$def_pc->getFirstCycleFree();
            $initialPriceOptions=[
                'required'  =>  false
            ];
            if($isFirstCycleFree){
                $initialPriceOptions=[
                    'required'  =>  false,
                    'disabled'  =>  true
                ];
            }
            $builder->add('register_flg', ChoiceType::class, [
                'required'  => false,
                // 'label'     =>  trans('stripe_recurring.admin.product_class.register_flg')
                'choices'   =>  [
                    '指定なし' =>  'none',
                    '日次' =>  'day',
                    // '３ヶ月ごと' =>  'quarter',
                    // '６ヶ月ごと' =>  'semiannual',
                    '週次' =>   'week',
                    '月次' => 'month',
                    '年次' =>  'year'
                ],
            ])
            ->add('price_change_flg', HiddenType::class, [
                'required'  =>  false                
            ])
            ->add('bundle_product', TextType::class, [
                'required'  =>  false,
            ])
            ->add('bundle_required', CheckboxType::class, [
                'label' => 'stripe_recurring.mypage.schedule.bundle_required',
                'required' => false,
            ])
            ->add('initial_price', PriceType::class, $initialPriceOptions)
            ->add('first_cycle_free',CheckboxType::class,[
                'label' => 'stripe_recurring.admin.product.first_cycle_free',
                'required' => false,
            ]);
            
        }
    }

    /**
     * @param TemplateEvent $event
     */
    public function onShoppingConfirmTwig(TemplateEvent $event)
    {
        $Order=$event->getParameter('Order');
        
        if ($Order->getPayment()->getMethodClass() === StripeRecurringNagMethod::class
                &&  $this->isEligiblePaymentMethod($Order->getPayment(),$Order->getPaymentTotal())
                && $Order->hasStripePriceId()) {
            
            $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
            $purchase_point =  empty($_REQUEST['purchase_point']) ? 'on_date' : $_REQUEST['purchase_point'];
            $after_days = empty($_REQUEST['_shopping_order']['after_days']) ? null : $_REQUEST['_shopping_order']['after_days'];
            $bundle_include_arr = empty($_REQUEST['bundle_include']) ? null : $_REQUEST['bundle_include'];
            $bundle_include_arr = $this->precessBundleInclude($bundle_include_arr, $Order);
            
            $purchase_point = $pb_service->calculatePurchasePoint($purchase_point, $after_days);
            $this->session->getFlashBag()->set("purchase_point", $purchase_point);
            $this->session->set("bundle_include_arr", $bundle_include_arr);
            
            $is_bundle = empty($_REQUEST['is_bundle']) ? false : $_REQUEST['is_bundle'] === "checked";        
            $this->session->set('is_bundle', $is_bundle);
            $bundles = $pb_service->getBundleProductsOrderByShipping($Order, $bundle_include_arr);

            $eccube_version = $this->getEccubeVersion();
            if($eccube_version < "4.0.3"){
                $event->addSnippet('@StripeRec/default/Shopping/bundle_product.4.0.0.twig');                    
            }else{
                $event->addSnippet('@StripeRec/default/Shopping/bundle_product.4.0.4.twig');
            }
            
            $event->setParameter('is_bundle_disabled', true);
            $event->setParameter('is_bundle', $is_bundle);
            $event->setParameter('bundle_include_arr', $bundle_include_arr);
            $event->setParameter('bundles', $bundles);
            $this->replaceItems($event);                
            $event->addAsset('StripeRec/Resource/assets/css/shopping_index_bundle.css.twig');

            $event->addSnippet('StripeRec/Resource/template/default/Shopping/confirm_screen.js.twig');
        }
    }
    public function onShoppingIndexTwig(TemplateEvent $event){
        $pc = $this->entityManager->getRepository(ProductClass::class)->findOneBy(['id' => 11]);
        
        $Order = $event->getParameter('Order');
        $this->session->getFlashBag()->set("stripe_customer_id", false);
        $this->session->getFlashBag()->set("payment_method_id", false);
        $this->session->getFlashBag()->set("purchase_point", false);
        $this->session->set('is_bundle', true);
        $this->session->set("bundle_include_arr", null);
        $is_bundle = true;
        if($Order) {
            $StripeConfig = $this->stripeConfigRepository->getConfigByOrder($Order);
            $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
            if ($Order->getPayment()->getMethodClass() === StripeRecurringNagMethod::class
                &&  $this->isEligiblePaymentMethod($Order->getPayment(),$Order->getPaymentTotal())
                && $Order->hasStripePriceId()) {
                
                $purchase_points = $this->entityManager->getRepository(PurchasePoint::class)->findby(['enabled' => true]);
                
                $stripeClient = new StripeClient($StripeConfig->secret_key);
                //BOC check if registered shop customer
                $stripePaymentMethodObj = false;
                $customerObj=false;
                $isSaveCardOn=false;
                $Customer=$Order->getCustomer();
                if($Customer instanceof Customer){
                    $customerObj=$Customer;
                    $StripeCustomer=$this->stripeCustomerRepository->findOneBy(array('Customer'=>$Customer));
                    if($StripeCustomer instanceof StripeCustomer){
                        $isSaveCardOn=$StripeCustomer->getIsSaveCardOn();
                        $stripePaymentMethodObj = $stripeClient->retrieveLastPaymentMethodByCustomer($StripeCustomer->getStripeCustomerId());
                        if( !($stripePaymentMethodObj instanceof PaymentMethod) || !$stripeClient->isPaymentMethodId($stripePaymentMethodObj->id) ) {
                            $stripePaymentMethodObj = false;
                        }
                    }
                }
                //EOC check if registered shop customer
                
                if(isset($_REQUEST['stripe_card_error'])){
                    $this->errorMessage=$_REQUEST['stripe_card_error'];
                }
                $bundles = $pb_service->getBundleProductsOrderByShipping($Order, null);

//                $StripeConfig = $this->stripeConfigRepository->get();
                $stripeCSS = 'StripePaymentGateway/Resource/assets/css/stripe.css.twig';
                $event->addAsset($stripeCSS);

                $event->setParameter('stripConfig', $StripeConfig);
                $event->setParameter('stripeErrorMessage', $this->errorMessage);
                $event->setParameter('stripeCreditCardPaymentId', $Order->getPayment()->getId());
                $event->setParameter('stripePaymentMethodObj', $stripePaymentMethodObj);
                $event->setParameter('customerObj', $customerObj);
                $event->setParameter('stripeIsSaveCardOn', true);
                $event->setParameter('stripe_locale', $this->locale);
                $event->setParameter('purchase_points', $purchase_points);
                $event->setParameter('bundle_include_arr', null);
                $event->setParameter('bundles', $bundles);

                // $event->addSnippet('@StripeRec/default/Shopping/stripe_rec_org.twig');
                $event->addSnippet('@StripeRec/default/Shopping/purchase_point_select.twig');
    
                $event->setParameter('is_bundle_disabled', false);
                $event->setParameter('is_bundle', $is_bundle);

                $eccube_version = $this->getEccubeVersion();
                if($eccube_version < "4.0.3"){
                    $event->addSnippet('@StripeRec/default/Shopping/bundle_product.4.0.0.twig');                    
                }else{
                    $event->addSnippet('@StripeRec/default/Shopping/bundle_product.4.0.4.twig');
                }
                
                // $event->addSnippet('@StripeRec/default/Shopping/shopping_index.twig');
                $this->replaceItems($event);
                $stripeJS= 'StripeRec/Resource/assets/js/stripe_recurring_js.twig';
                $event->addAsset($stripeJS);
                $event->addAsset('StripeRec/Resource/assets/css/shopping_index_bundle.css.twig');

               
            }
            // change payment label
            $Payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['method_class'  => StripeRecurringNagMethod::class]);
            if($Payment){
               $event->setParameter('stripe_pay_id', $Payment->getId());
               $event->setParameter('method_name', $Payment->getMethod());
               $event->setParameter('checkout_ga_enable', $StripeConfig->checkout_ga_enable);
               $event->addSnippet('@StripeRec/default/Shopping/paymethod_label.js.twig');
            }
        }
    }

    public function sendOrderMailBefore(EventArgs $event)
    {
        $this->container->get('plg_stripe_rec.service.email.service')->onSendOrderMailBefore($event);
    }
    public function myPageNaviRenderBefore(TemplateEvent $event){
        $event->addSnippet('@StripeRec/default/Mypage/navi.twig');
    }
    public function onProductEdit(TemplateEvent $event){
        
        $event->addSnippet('@StripeRec/admin/product_recurring.twig');
    }
    private function isEligiblePaymentMethod(Payment $Payment,$total){
        $min = $Payment->getRuleMin();
        $max = $Payment->getRuleMax();
        if (null !== $min && $total < $min) {
            return false;
        }
        if (null !== $max && $total > $max) {
            return false;
        }
        return true;
    }

    private function precessBundleInclude($bundle_include_arr, $Order){
        $order_items = $Order->getProductOrderItems();
        $res = [];
        foreach($order_items as $order_item){
            $pc = $order_item->getProductClass();
            if(!$pc->isBundleRequired()){
                if(empty($bundle_include_arr[$order_item->getId()])){
                    $res[$order_item->getId()] = 0;
                }else{
                    $res[$order_item->getId()] = $bundle_include_arr[$order_item->getId()];
                }
            }else{
                $res[$order_item->getId()] = 1;
            }
        }
        if(empty($res)){
            $res = null;
        }
        return $res;
    }

    private function replaceItems(TemplateEvent $event){
        $source = $event->getSource();
        $find_delivery_for = $this->between("{% for orderItem in shipping.productOrderItems %}", "{% endfor %}", $source);
        $replace = "{% include '@StripeRec/default/Shopping/shopping_index.twig' %}";
        $source = str_replace($find_delivery_for, $replace, $source);
        $event->setSource($source);
    }

    public function between($from, $to, $source) {
        $arr = explode($from, $source);
        if (isset($arr[1])) {
            $arr = explode($to, $arr[1]);
            return $arr[0];
        }
        return '';
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $source
     * @return string
     */
    public function betweenInclude($from, $to, $source) {
        return $from . $this->between($from, $to, $source) . $to;
    }

    public function getEccubeVersion(){
        // $kernel = $this->container->get('kernel');
        // $project_dir = $kernel->getProjectDir();

        // $package_path = $project_dir.'/package.json';
        // if (file_exists($package_path) === false) {
        //     return "4.0.4xxx";
        // }
        // $json = json_decode(file_get_contents($package_path));
        // if(!empty($json->version)){
        //     return $json->version;
        // }
        return Constant::VERSION;
    }
}
