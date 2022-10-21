<?php

namespace Plugin\StripeRec\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Service\MailService;
use Eccube\Event\EventArgs;
use Stripe\Stripe;
use Stripe\Coupon;
use Plugin\StripePaymentGateway\Entity\StripeConfig;

class CouponService{

    protected $error = "";
    protected $stripe_config;
    protected $container;
    protected $entityManager;
    protected $mailService;
    
    public function __construct(ContainerInterface $container, MailService $mailService){
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->mailService = $mailService;
        $this->stripe_config = $this->entityManager->getRepository(StripeConfig::class)->getConfigByOrder(null);
    }
    public function setConfig($StripeConfig){
        $this->stripe_config = $StripeConfig;
    }

    public function getError(){
        return $this->error;
    }

    public function retrieveCoupon($coupon_id){
        // if(!$this->stripe_config->coupon_enable){
        //     $this->error = trans('stripe_rec.coupon_id.invalid_coupon');
        //     return null;
        // }
        try{
            Stripe::setApiKey($this->stripe_config->secret_key);
            $data = Coupon::retrieve($coupon_id);
            if(!empty($data->id)){
                return $data;
            }else{
                $this->error = trans('stripe_rec.coupon_id.invalid_coupon');
                return null;
            }
        }catch(\Exception $e){
            $msg = $e->getJsonBody();
            
            if(!empty($msg['error']['message'])){
                $this->error = trans($msg['error']['message']);
            }else{
                $this->error = $e->getMessage();
            }
            return null;
        }
    }
    public function couponDiscountAmount($initial_price, $coupon_id){
        if(\is_object($coupon_id)){
            $coupon = $coupon_id;
        }else{
            $coupon = $this->retrieveCoupon($coupon_id);
        }
        if(empty($coupon)){
            $this->error = trans("stripe_recurring.admin.coupon.no_such_coupon");
            return false;
        }
        if(!empty($coupon->amount_off)){
            if($initial_price < $coupon->amount_off){
                $this->error = trans("stripe_recurring.coupon.discount_bigger");
                return false;
            }
            return $coupon->amount_off;
        }
        if(!empty($coupon->percent_off)){
            return \round($initial_price * $coupon->percent_off / 100);
        }
        $this->error = trans("stripe_recurring.coupon.invalid_coupon");
        return false;
    }
    public function couponDiscountStr($coupon_id){
        if(\is_object($coupon_id)){
            $coupon = $coupon_id;
        }else{
            $coupon = $this->retrieveCoupon($coupon_id);
        }
        if(!empty($coupon->amount_off)){
            return $coupon->amount_off . "-amount";
        }
        if(!empty($coupon->percent_off)){
            return $coupon->percent_off . "-percent";
        }
        $this->error = trans("stripe_recurring.coupon.invalid_coupon");
        return false;
    }
    public function couponDiscountFromStr($amount, $coupon_discount_str){
        $temp = explode("-", $coupon_discount_str);
        if(count($temp) < 2){
            return 0;
        }
        if($temp[1] == "amount"){
            return \round($amount - \floatval($temp[0]));
        }
        if($temp[1] == "percent"){
            return \round($amount * \floatval($temp[0]) / 100);
        }
        return 0;
    }
    
    public function onSendOrderMailBefore(EventArgs $event){
        $Order = $event->getArgument('Order');
        if(empty($Order->getCouponAmount())){
            return;
        }
        $template = $this->container->getParameter('plugin_realdir').'/StripePaymentGateway/Resource/template/default/Mail/stripe_coupon_order_complete.twig';
        $engine = $this->container->get('twig');
        $coupon_msg = $engine->render($template, ['Order' => $Order], null);

        $message = $event->getArgument('message');
        $MailTemplate = $event->getArgument('MailTemplate');
        $orderMassage_org = $Order->getMessage();

        $htmlFileName = $this->mailService->getHtmlTemplate($MailTemplate->getFileName());

        // HTML形式テンプレートを使用する場合
        if (!is_null($htmlFileName)) {
            // 注文完了メールに表示するメッセージの改行コードをbrタグに変換して再設定
            $orderMassage = str_replace(["\r\n", "\r", "\n"], "<br/>", $orderMassage_org.$coupon_msg);
            $Order->setMessage($orderMassage);

            $htmlBody = $engine->render($htmlFileName, compact('Order'));

            // HTML形式で使われるbodyを再設定
            $beforeBody = $message->getChildren();
            $message->detach($beforeBody[0]);
            $message->addPart(htmlspecialchars_decode($htmlBody), 'text/html');
        }

        // テキスト形式用にHTMLエンティティをデコードして設定
        $Order->setMessage(htmlspecialchars_decode($orderMassage_org.$coupon_msg));
        $body = $engine->render($MailTemplate->getFileName(), compact('Order'));
        $message->setBody($body);

        // Orderのmessageを元に戻す
        $Order->setMessage($orderMassage_org);
    }
    /**
     * 管理画面メール通知(注文完了メール)初期処理後の処理です。<br>
     * 問い合わせの下に決済情報を表示するため、messageに決済情報をセットします。
     * @param EventArgs $event
     */
    public function onAdminOrderMailInitAfter(EventArgs $event)
    {
        $Order = $event->getArgument('Order');
        if(empty($Order->getCouponAmount())){
            return;
        }
        $engine = $this->container->get('twig');
        $param = ['Order' => $Order];
        $template = $this->container->getParameter('plugin_realdir').'/StripePaymentGateway/Resource/template/default/Mail/stripe_coupon_order_complete.twig';

        $message = $engine->render($template, $param, null);
        $orderMassage_org = $Order->getMessage();

        // HTMLエンティティをデコードして設定
        $Order->setMessage(htmlspecialchars_decode($orderMassage_org.$message));
        
    }

}
