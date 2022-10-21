<?php

namespace Plugin\StripeRec\Controller;

if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Eccube\Controller\ShoppingController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Eccube\Controller\AbstractController;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
use Eccube\Service\OrderHelper;
use Eccube\Entity\Order;
use Eccube\Entity\Customer;
use Eccube\Form\Type\Shopping\OrderType;
use Eccube\Repository\OrderRepository;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Master\OrderStatus;
use Plugin\StripePaymentGateway\Repository\StripeConfigRepository;
use Plugin\StripePaymentGateway\StripeClient;
use Plugin\StripePaymentGateway\Entity\StripeConfig;
use Plugin\StripePaymentGateway\Entity\StripeOrder;
use Plugin\StripePaymentGateway\Entity\StripeLog;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;
use Plugin\StripeRec\Service\ConfigService;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Repository\StripeRecOrderRepository;
use Plugin\StripeRec\Service\MailExService;
use Stripe\PaymentMethod;
use Stripe\PaymentIntent;

class ShoppingExController extends ShoppingController
{
    protected $container;
    private $em;
    private $stripe_config;    
    protected $util_service;
    protected $session;
    protected $stripeCustomerRepository;
    protected $eccubeConfig;

    public function __construct(
        ContainerInterface $container,
        CartService $cartService,
        MailService $mailService,
        OrderRepository $orderRepository,
        OrderHelper $orderHelper,
        SessionInterface $session,
        EccubeConfig $eccubeConfig
    ) {
        parent::__construct(
            $cartService,
            $mailService,
            $orderRepository,
            $orderHelper
        );
        $this->container = $container;
        $this->em = $container->get('doctrine.orm.entity_manager'); 
        
        $this->util_service = $this->container->get("plg_stripe_recurring.service.util");
        $this->session = $session;
        $this->stripeCustomerRepository = $this->em->getRepository(StripeCustomer::class);
        $this->eccubeConfig = $eccubeConfig;
    }
    

    /**
     * @Route("/plugin/StripeRec/presubscribe", name="plugin_striperec_presubscripe")
     */
    public function presubscribe(Request $request)
    {

//        $StripeConfig = $this->stripeConfigRepository->get();
        $preOrderId = $this->cartService->getPreOrderId();
        /** @var Order $Order */
        $Order = $this->orderHelper->getPurchaseProcessingOrder($preOrderId);
        if (!$Order) {
            return $this->json(['error' => 'true', 'message' => trans('stripe_payment_gateway.admin.order.invalid_request')]);
        }
		$StripeConfig = $this->em->getRepository(StripeConfig::class)->getConfigByOrder($Order);
        $stripeClient = new StripeClient($StripeConfig->secret_key);
        $paymentMethodId = $request->get('payment_method_id');

        $stripeCustomerId = $this->procStripeCustomer($stripeClient, $Order, true);
        if(is_array($stripeCustomerId)) { // エラー
            return $this->json($stripeCustomerId);
        }
        
        if($Order->hasStripePriceId()){
            $this->session->getFlashBag()->set("stripe_customer_id", $stripeCustomerId);
            $this->session->getFlashBag()->set("payment_method_id", $paymentMethodId);
            return $this->json(["success" => true]);
        }else {
            return $this->json(["error" => "Not Recurring Product"]);
        }
    }

    /**
     * @Route("/plugin/stripe_rec/success", name="plugin_stripe_rec_success")
     */
    public function success(Request $request){
        $this->cartService->clear();
        return $this->redirectToRoute("shopping_complete");
    }
    /**
     * @Route("/plugin/stripe_rec/extra_payemnt/{id}/{stamp}", name="plugin_stripe_rec_extra_pay")
     * @Template("@StripeRec/default/Shopping/checkout_recurring_extra.twig")
     */
    public function extraPay(Request $request, $id, $stamp, 
        StripeRecOrderRepository $recOrderRepository, 
        StripeConfigRepository $stripeConfigRepository,
        MailExService $mail_service)
    {
        $recOrder = $recOrderRepository->find($id);
        if (!$recOrder) {
            throw new NotFoundHttpException();
        }
        if ($recOrder->getManualLinkStamp() != $stamp) {
            throw new NotFoundHttpException();
        }
        $stripeConfig = $stripeConfigRepository->getConfigByOrder($recOrder->getOrder());

        $already = $this->orderRepository->findOneBy(['recOrder' => $recOrder, 'manual_link_stamp' => $stamp]);
        if ($already) {
            return [
                'amount'    => 0, 
                'recOrder'  => $recOrder,
                'stripeConfig'=> $stripeConfig,
                'already_paid'=> true
            ];
        }

        $rec_service = $this->container->get('plg_stripe_rec.service.recurring_service');
        $details = $rec_service->getPriceDetail($recOrder);
        extract($details);

        if ($recOrder->getPaymentCount() == 0) {
            // 'bundle_order_items', 'initial_amount', 'recurring_amount', 'initial_discount', 'recurring_discount'
            $amount = $initial_amount - $initial_discount;
        } else {
            $amount = $recurring_amount - $recurring_discount;
        }


        
        $stripeClient = new StripeClient($stripeConfig->secret_key);
        if ($request->getMethod() === "POST") {
            $payment_intent_id = $request->request->get('payment_intent_id');

            if (empty($payment_intent_id)) {
                throw new NotFoundHttpException();
            }
            $is_auth_capture = $stripeConfig->is_auth_and_capture_on;

            log_info("----extra_pay---");
            if ($is_auth_capture) {//Capture if on
                log_info("capturing payment, payment_intent_id : $payment_intent_id");
                $payment_intent = $stripeClient->capturePaymentIntent($payment_intent_id, $amount, $recOrder->getOrder()->getCurrencyCode());
                log_info("captured payment, payment_intent_id : $payment_intent_id");
            } else {
                $payment_intent = $stripeClient->retrievePaymentIntent($payment_intent_id);
            }

            if (is_array($payment_intent) && isset($payment_intent['error'])) {
                $errorMessage = StripeClient::getErrorMessageFromCode($payment_intent['error'], $this->eccubeConfig['locale']);

                return $this->json(['error' =>  $errorMessage]);
            }

            $rec_service = $this->container->get("plg_stripe_rec.service.recurring_service");

            if ($is_auth_capture) {
                $status_id = OrderStatus::PAID;
            } else {
                $status_id = OrderStatus::NEW;
            }
            $NewOrder = $rec_service->createNewOrder($recOrder, $status_id);
            $NewOrder->setManualLinkStamp($stamp);
            $this->entityManager->persist($NewOrder);
            $recOrder->setLastChargeId($payment_intent->charges->data[0]->id);
            $recOrder->setPaidStatus(StripeRecOrder::STATUS_PAID);
            $recOrder->setLastPaymentDate(new \DateTime());
            $this->entityManager->persist($recOrder);
            $this->entityManager->flush();

            $recOrder->setCurrentPaymentTotal($amount);
            $mail_service->sendPaidMail($recOrder);

            return $this->json(['success' => true, 'order_id' => $NewOrder->getId()]);
        }

        return compact('amount', 'recOrder', 'stripeConfig');
    }
    /**
     * @Route("/plugin/stripe_rec/extra_payment/intent/{id}", name="plugin_stripe_rec_extra_pay_intent")
     */
    public function extraPayIntent(Request $request, $id, StripeConfigRepository $stripeConfigRepository)
    {
        $rec_order = $this->entityManager->getRepository(StripeRecOrder::class)->find($id);
        if (!$rec_order) {
            throw new NotFoundHttpException();
        }
        $Order = $rec_order->getOrder();
        $StripeConfig = $stripeConfigRepository->getConfigByOrder($Order);

        $stripeClient = new StripeClient($StripeConfig->secret_key);
        
        $paymentMethodId = $request->request->get('payment_method_id');
        $isSaveCardOn = $request->request->get('is_save_on') === "true" ? true : false;
        $stripeCustomerId = $this->procStripeCustomer($stripeClient, $Order, $isSaveCardOn);

        if (is_array($stripeCustomerId)) {
            return $this->json($stripeCustomerid);
        }
        $rec_service = $this->container->get('plg_stripe_rec.service.recurring_service');
        $details = $rec_service->getPriceDetail($rec_order);
        extract($details);

        if ($rec_order->getPaymentCount() == 0) {
            // 'bundle_order_items', 'initial_amount', 'recurring_amount', 'initial_discount', 'recurring_discount'
            $amount = $initial_amount - $initial_discount;
        } else {
            $amount = $recurring_amount - $recurring_discount;
        }

        $paymentIntent = $stripeClient->createPaymentIntentWithCustomer(
            $amount, 
            $paymentMethodId, 
            $Order->getId(), 
            $isSaveCardOn, 
            $stripeCustomerId,
            $Order->getCurrencyCode());
        return $this->json($this->genPaymentResponse($paymentIntent));
    }


    /**
     * @Route("/plugin/stripe_rec/cancel", name="plugin_stripe_rec_cancel")
     */
    public function cancel(Request $request){
        $preOrderId = $this->cartService->getPreOrderId();
        $order = $this->orderHelper->getPurchaseProcessingOrder($preOrderId);

        if(empty($order)){
            return $this->redirectToRoute("shopping");
        }
        if(!$order->isRecurring()){
            return $this->redirectToRoute("shopping");
        }
        $rec_order = $order->getRecOrder();
        $rec_items  = $rec_order->getOrderItems();
        foreach($rec_items as $rec_item){
            $this->entityManager->remove($rec_item);
            $this->entityManager->flush();
            $this->entityManager->commit();
        }
        
        $this->entityManager->remove($rec_order);
        $this->entityManager->flush();
        $this->entityManager->commit();
        
        return $this->redirectToRoute("shopping");
    }

    /**
     * @Route("/plugin/stripe_rec/checkout_page", name="plugin_striperec_checkout_page")
     * @Template("@StripeRec/default/Shopping/checkout.twig")
     */
    public function credit_payment(Request $request)
    {

        // ログイン状態のチェック.
        if ($this->orderHelper->isLoginRequired()) {
            log_info('[注文処理] 未ログインもしくはRememberMeログインのため, ログイン画面に遷移します.');

            return $this->redirectToRoute('shopping_login');
        }
        // 受注の存在チェック
        $preOrderId = $this->cartService->getPreOrderId();
        $Order = $this->orderHelper->getPurchaseProcessingOrder($preOrderId);
        if (!$Order) {
            log_info('[注文処理] 購入処理中の受注が存在しません.', [$preOrderId]);
            
            return $this->redirectToRoute('shopping_error');
        }
        $StripeConfig = $this->entityManager->getRepository(StripeConfig::class)->getConfigByOrder($Order);
        
        $Customer = $Order->getCustomer();
        // フォームの生成.
        $form = $this->createForm(OrderType::class, $Order,[
            'skip_add_form' =>  true,
        ]);
        $form->handleRequest($request);
        $checkout_ga_enable = $StripeConfig->checkout_ga_enable;
        $config_service = $this->get("plg_stripe_rec.service.admin.plugin.config");
        $rec_config = $config_service->getConfig();
        $coupon_enable = $rec_config[ConfigService::COUPON_ENABLE];
        if ($form->isSubmitted() && $form->isValid()) {
            return [
                'stripeConfig'  =>  $StripeConfig,
                'Order'         =>  $Order,
                'checkout_ga_enable' => $checkout_ga_enable,
                'coupon_enable' =>  $coupon_enable,
            ];
        }
        return $this->redirectToRoute('shopping');
    }
    /**
     * @Route("/plugin/stripe_rec/check_coupon", name="plugin_striperec_coupon_check")
     */
    public function checkCoupon(Request $request){
        $coupon_id = $request->request->get('coupon_id');
        $coupon_service = $this->container->get('plg_stripe_rec.service.coupon_service');
        $res = $coupon_service->retrieveCoupon($coupon_id);
        if(empty($res)){
            return $this->json([
                'error' =>  true,
                'message'   =>  $coupon_service->getError()
            ]);
        }
        if(empty($res->valid)){
            return $this->json([
                'error' =>  true,
                'message'   =>  trans("stripe_recurring.coupon.error.expired_or_invalid")
            ]);
        }
        $Order = $this->getOrder();
        $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
        $bundle_include_arr = $this->session->get('bundle_include_arr');
        $bundles = $pb_service->getBundleProducts($Order, $bundle_include_arr);
        
        $price_sum = $pb_service->getPriceSum($Order);        
        extract($price_sum);
        if($bundles){
            $bundle_order_items = $bundles['order_items'];
            $initial_amount += $bundles['price'];
        }else{
            $bundle_order_items = null;
        }
        $initial_discount = $coupon_service->couponDiscountAmount($initial_amount, $res);
        $recurring_discount = $coupon_service->couponDiscountAmount($recurring_amount, $res);
        
        return $this->json([
            'success'   =>  true,
            'initial_amount'    =>  $initial_amount,
            'recurring_amount'  =>  $recurring_amount,
            'initial_discount'  =>  $initial_discount,
            'recurring_discount'=>  $recurring_discount,
        ]);
    }

    /**
     * @Route("/plugin/striperec/checkout", name="plugin_striperec_checkout")
     */
    public function checkout(Request $request){
        $Order = $this->getOrder();
        if (!$Order) {
            $this->addError(trans('stripe_payment_gateway.admin.order.invalid_request'));
            return $this->redirectToRoute('shopping_error');
        }
        
        // EOC validation checking
        try {
            $response = $this->executePurchaseFlow($Order);
            $this->entityManager->flush();
            if ($response) {
                return $response;
            }
            log_info('[注文処理] PaymentMethodを取得します.', [$Order->getPayment()->getMethodClass()]);
            $paymentMethod = $this->createPaymentMethod($Order, null);

            /*
                * 決済実行(前処理)
                */
            log_info('[注文処理] PaymentMethod::applyを実行します.');
            if ($response = $this->executeApply($paymentMethod)) {
                return $response;
            }

            /*
            * 決済実行
            *
            * PaymentMethod::checkoutでは決済処理が行われ, 正常に処理出来た場合はPurchaseFlow::commitがコールされます.
            */
            log_info('[注文処理] PaymentMethod::checkoutを実行します.');
            if ($response = $this->executeCheckout($paymentMethod)) {
                return $response;
            }

            $this->entityManager->flush();

            log_info('[注文処理] 注文処理が完了しました.', [$Order->getId()]);
        }catch (ShoppingException $e) {
            log_error('[注文処理] 購入エラーが発生しました.', [$e->getMessage()]);

            $this->entityManager->rollback();

            $this->addError($e->getMessage());

            return $this->redirectToRoute('shopping_error');
        } catch (\Exception $e) {
            log_error('[注文処理] 予期しないエラーが発生しました.', [$e->getMessage()]);

            $this->entityManager->rollback();

            $this->addError('front.shopping.system_error');

            return $this->redirectToRoute('shopping_error');
        }

        // カート削除
        log_info('[注文処理] カートをクリアします.', [$Order->getId()]);
        $this->cartService->clear();

        // 受注IDをセッションにセット
        $this->session->set(OrderHelper::SESSION_ORDER_ID, $Order->getId());

        // メール送信
        log_info('[注文処理] 注文メールの送信を行います.', [$Order->getId()]);
        $this->mailService->sendOrderMail($Order);
        $this->entityManager->flush();

        log_info('[注文処理] 注文処理が完了しました. 購入完了画面へ遷移します.', [$Order->getId()]);

        return $this->redirectToRoute('shopping_complete');
    }

    // For original card input recurring
    private function procStripeCustomer(StripeClient $stripeClient, $Order, $isSaveCardOn) {

        $Customer = $Order->getCustomer();
        $isEcCustomer=false;
        $isStripeCustomer=false;
        $StripeCustomer = false;
        $stripeCustomerId = false;

        if($Customer instanceof Customer ){
            $isEcCustomer=true;
            $StripeCustomer=$this->stripeCustomerRepository->findOneBy(array('Customer'=>$Customer));
            if($StripeCustomer instanceof StripeCustomer){
                $stripLibCustomer = $stripeClient->retrieveCustomer($StripeCustomer->getStripeCustomerId());
                if(is_array($stripLibCustomer) || isset($stripLibCustomer['error'])) {
                    if(isset($stripLibCustomer['error']['code']) && $stripLibCustomer['error']['code'] == 'resource_missing') {
                        $isStripeCustomer = false;
                    }
                } else {
                    $isStripeCustomer=true;
                }
            }
        }

        if($isEcCustomer) {//Create/Update customer
            if($isSaveCardOn) {
                //BOC check if is StripeCustomer then update else create one
                if($isStripeCustomer) {
                    $stripeCustomerId=$StripeCustomer->getStripeCustomerId();
                    //BOC save is save card
                    $StripeCustomer->setIsSaveCardOn($isSaveCardOn);
                    $this->entityManager->persist($StripeCustomer);
                    $this->entityManager->flush($StripeCustomer);
                    //EOC save is save card

                    $updateCustomerStatus = $stripeClient->updateCustomerV2($stripeCustomerId,$Customer->getEmail());
                    if (is_array($updateCustomerStatus) && isset($updateCustomerStatus['error'])) {//In case of update fail
                        $errorMessage=StripeClient::getErrorMessageFromCode($updateCustomerStatus['error'], $this->eccubeConfig['locale']);
                        return ['error' => true, 'message' => $errorMessage];
                    }
                } else {
                    $stripeCustomerId=$stripeClient->createCustomerV2($Customer->getEmail(),$Customer->getId());
                    if (is_array($stripeCustomerId) && isset($stripeCustomerId['error'])) {//In case of fail
                        $errorMessage=StripeClient::getErrorMessageFromCode($stripeCustomerId['error'], $this->eccubeConfig['locale']);
                        return ['error' => true, 'message' => $errorMessage];
                    } else {
                        if(!$StripeCustomer) {
                            $StripeCustomer = new StripeCustomer();
                            $StripeCustomer->setCustomer($Customer);
                        }
                        $StripeCustomer->setStripeCustomerId($stripeCustomerId);
                        $StripeCustomer->setIsSaveCardOn($isSaveCardOn);
                        $StripeCustomer->setCreatedAt(new \DateTime());
                        $this->entityManager->persist($StripeCustomer);
                        $this->entityManager->flush($StripeCustomer);
                    }
                }
                //EOC check if is StripeCustomer then update else create one
                return $stripeCustomerId;
            }
        }
        //Create temp customer
        $stripeCustomerId=$stripeClient->createCustomerV2($Order->getEmail(),0,$Order->getId());
        if (is_array($stripeCustomerId) && isset($stripeCustomerId['error'])) {//In case of fail
            $errorMessage=StripeClient::getErrorMessageFromCode($stripeCustomerId['error'], $this->eccubeConfig['locale']);
            return ['error' => true, 'message' => $errorMessage];
        }
        return $stripeCustomerId;
    }


    private function getErrorMessages(\Symfony\Component\Form\Form $form) {
        $errors = array();
    
        foreach ($form->getErrors() as $key => $error) {
            if ($form->isRoot()) {
                $errors['#'][] = $error->getMessage();
            } else {
                $errors[] = $error->getMessage();
            }
        }
    
        foreach ($form->all() as $child) {
            if (!$child->isValid()) {
                $errors[$child->getName()] = $this->getErrorMessages($child);
            }
        }
    
        return $errors;
    }
    private function getOrder(){
        // BOC validation checking
        $preOrderId = $this->cartService->getPreOrderId();
        /** @var Order $Order */
        return $this->orderHelper->getPurchaseProcessingOrder($preOrderId);
   }
   /**
     * PaymentMethodをコンテナから取得する.
     *
     * @param Order $Order
     * @param FormInterface $form
     *
     * @return PaymentMethodInterface
     */
    private function createPaymentMethod(Order $Order)
    {
        $PaymentMethod = $this->container->get($Order->getPayment()->getMethodClass());
        $PaymentMethod->setOrder($Order);

        return $PaymentMethod;
    }

    private function genPaymentResponse($intent) {
        if($intent instanceof PaymentIntent ) {
            log_info("genPaymentResponse: " . $intent->status);
            switch($intent->status) {
                case 'requires_action':
                case 'requires_source_action':
                    return [
                        'action'=> 'requires_action',
                        'payment_intent_id'=> $intent->id,
                        'client_secret'=> $intent->client_secret
                    ];
                case 'requires_payment_method':
                case 'requires_source':
                    return [
                        'error' => true,
                        'message' => StripeClient::getErrorMessageFromCode('invalid_number', $this->eccubeConfig['locale'])
                    ];
                case 'requires_capture':
                    return [
                        'action' => 'requires_capture',
                        'payment_intent_id' => $intent->id
                    ];
                default:
                    return ['error' => true, 'message' => trans('stripe_payment_gateway.front.unexpected_error')];
//                    return ['error' => true, 'message' => trans('stripe_payment_gateway.front.unexpected_error')];
            }
        }
        if(isset($intent['error'])) {
            $errorMessage=StripeClient::getErrorMessageFromCode($intent['error'], $this->eccubeConfig['locale']);
        } else {
            $errorMessage = trans('stripe_payment_gateway.front.unexpected_error');
        }
        return ['error' => true, 'message' => $errorMessage];
    }
}