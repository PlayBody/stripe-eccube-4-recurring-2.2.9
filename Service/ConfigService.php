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
use Eccube\Repository\PaymentRepository;
use Plugin\StripeRec\Service\Method\StripeRecurringNagMethod;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Entity\MailTemplate;
use Plugin\StripeRec\Entity\PurchasePoint;

class ConfigService{

    const WEBHOOK_SIGNATURE = "rec_webhook_sig";
    const COUPON_ENABLE = "coupon_enable";

    //=======mail config=========
    const PAID_MAIL_NAME = "Stripe 支払い成功メール";
    const PAY_FAILED_MAIL_NAME = "Stripe 定期支払い失敗メール";
    const PAY_UPCOMING = "Stripe 定期支払い待機メール";
    const REC_CANCELED = "Stripe 定期支払いキャンセル済みメール";
    const REC_ORDER_THANKS = '定期支払いご注文ありがとうございます';

    //===========================

    // =========Payday options=====
    const PAYDAY_OPTION_1 = "pay_day_option_1";
    const PAYDAY_OPTION_2 = "pay_day_option_2";
    const PAYMENT_DATE = "payment_date";
    const PAY_FULL = "pay_full";
    // ============================

    // ========Multi Product========
    const MULTI_PRODUCT = "multi_product";

    // incoming mail on/off
    const INCOMING_MAIL = "incoming_mail";

    /**
     * コンテナ
     */
    private $container;
    private $eccubeConfig;
    /**
     * コンストラクタ
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container, EccubeConfig $eccubeConfig)
    {
        $this->container = $container;
        $this->eccubeConfig = $eccubeConfig;
    }

    public function getConfig(){
        $payday_option = $this->getPaymentDate();
        $pay_full = $this->get('pay_full');
        if($pay_full === null){
            $pay_full = 1;
        }
        return [
            ConfigService::WEBHOOK_SIGNATURE =>  $this->get(ConfigService::WEBHOOK_SIGNATURE),
            ConfigService::COUPON_ENABLE    =>  $this->get(ConfigService::COUPON_ENABLE),
            'payday_option' =>  $payday_option['payday_option'],
            'payment_date'  =>  $payday_option['payment_date'],
            'pay_full'      =>  $pay_full,
            'multi_product' =>  $this->get(self::MULTI_PRODUCT) ? true : false,
            'incoming_mail' =>  $this->get(self::INCOMING_MAIL) ? true : false,
        ];
    }
    

    public function isMailerSetting(){
        $mailer_url = env("MAILER_URL", "none");
        return $mailer_url !== "none";
    }
    public function getSignature(){
        return $this->get(ConfigService::WEBHOOK_SIGNATURE);
    }
    public function saveConfig($new_data){
        $this->savePaymentOption($new_data);
        if(!isset($new_data['pay_full'])){
            $new_data['pay_full'] = 1;
        }
        $old_data = $this->getConfig();
        if(!empty($new_data)){
            
            $diff_keys = [];
            foreach($new_data as $k => $v){                
                $diff_keys[] = $k;
                $this->set($k, $new_data[$k]);                
            }
        }
        $diff_keys = [];
        foreach($old_data as $k => $v){
            if(!empty($new_data[$k]) && $new_data[$k] !== $old_data[$k]){
                $diff_keys[] = $k;
                $this->set($k, $new_data[$k]);
            }
        }
        $ids = [];
        foreach($new_data['purchase_point'] as $point){
            if($point->isEnabled()){
                $ids[] = $point->getId();
            }
        }
        
        $repo = $this->container->get('doctrine.orm.entity_manager')->getRepository(PurchasePoint::class);
        $qb = $repo->createQueryBuilder('pr');
        $query = $qb->update()
           ->set('pr.enabled', '0')
           ->getQuery();
        $query->execute();
        
        $qb = $repo->createQueryBuilder('pr');
        $query = $qb->update()
           ->set('pr.enabled', '1')
           ->where($qb->expr()->in('pr.id', ':points'))
           ->setParameter('points', $new_data['purchase_point'])
           ->getQuery();
        $res = $query->execute();
        
        
        return $diff_keys;
    }    
    public function get($key){

        // $rec_props_path = $this->container->getParameter('plugin_realdir'). '/StripeRec/Resource/config/webhook.properties';
        $rec_props_path = $this->eccubeConfig->get("kernel.project_dir") . "/var/stripe_rec_webhook.properties";
        
        if(!\file_exists($rec_props_path)){
            return null;
        }
        $rec_props = file($rec_props_path);

        if ($rec_props === false) {
            return null;
        }
        foreach($rec_props as $k => $val){
            $temp_arr = explode("=", $val);
            if(count($temp_arr) == 1){
                continue;
            }
            if($temp_arr[0] == $key){
                return trim($temp_arr[1]);
            }
        }
        return null;
    }
    public function set($key, $s_val){
        
        // $rec_props_path = $this->container->getParameter('plugin_realdir'). '/StripeRec/Resource/config/webhook.properties';
        $rec_props_path = $this->eccubeConfig->get("kernel.project_dir") . "/var/stripe_rec_webhook.properties";
        if(!\file_exists($rec_props_path)){
            $f = fopen($rec_props_path, "w");
            fwrite($f, "");
            fclose($f);
        }
        $rec_props = file($rec_props_path);
        
        if (empty($rec_props)) {            
            file_put_contents($rec_props_path, $key . "=" . $s_val . "\n");
            return true;            
        }

        $flag = false;
        foreach($rec_props as $k => $val){
            $temp_arr = explode("=", $val);
            if(count($temp_arr) < 2){
                continue;
            }
            if($temp_arr[0] == $key){
                $flag = true;
                $rec_props[$k] = $key . "=" . $s_val . "\n";
            break;
            }
        }
        if(!$flag){
            $rec_props[$k + 1] = $key . "=" . $s_val . "\n";
        }

        $str = join("", $rec_props);
        file_put_contents($rec_props_path, $str);
        return true;
    }
    public function enable_plugin(){
        $this->createTokenPayment($this->container);
        $this->insertMailTemplate($this->container);
    }
    public function disable_plugin(){
        $container = $this->container;
        $em = $container->get('doctrine.orm.entity_manager');
        $paymentRepository = $em->getRepository(Payment::class);
        $Payment = $paymentRepository->findOneBy(['method_class' => StripeRecurringNagMethod::class]);
        if (empty($Payment)) {
            return;
        }
        $pay_options = $em->getRepository(PaymentOption::class)->findBy(['payment_id'   =>  $Payment->getId()]);
        foreach($pay_options as $pay_option){
            $em->remove($pay_option);            
        }
        
        $Payment->setVisible(false);
        $em->flush();
    }

    // protected function registerPageForUpdate($container){
    //     $em = $container->get('doctrine.orm.entity_manager');
    //     $page_consts = array(
    //         [
    //             'name' =>  'mypage_stripe_rec',
    //             'label' =>  'MYページ/定期コース',
    //             'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_tab'
    //         ],
    //         [
    //             'name'  =>  'mypage_stripe_cancel_confirm',
    //             'label' =>  'MYページ/定期コースキャンセル',
    //             'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_cancel_confirm'
    //         ]
    //     );
    //     foreach($page_consts as $page_url){
    //         $url = $page_url['name'];
    //         $page = $container->get(PageRepository::class)->findOneBy(compact('url'));
    //         if(is_null($page)){
    //             $page = new Page;
    //         }
    //         $page->setName($page_url['label']);
    //         $page->setUrl($url);
    //         $page->setMetaRobots('noindex');
    //         $page->setFileName($page_url['template']);
    //         $page->setEditType(Page::EDIT_TYPE_DEFAULT);

    //         $em->persist($page);
    //         $em->flush();
    //         // $em->commit();
            
    //         $pageLayoutRepository = $em->getRepository(PageLayout::class);
    //         $pageLayout = $pageLayoutRepository->findOneBy([
    //             'page_id' => $page->getId()
    //         ]);
    //         // 存在しない場合は新規作成
    //         if (is_null($pageLayout)) {
    //             $pageLayout = new PageLayout;
    //             // 存在するレコードで一番大きいソート番号を取得
    //             $lastSortNo = $pageLayoutRepository->findOneBy([], ['sort_no' => 'desc'])->getSortNo();
    //             // ソート番号は新規作成時のみ設定
    //             $pageLayout->setSortNo($lastSortNo+1);
    //         }
    //         // 下層ページ用レイアウトを取得
    //         $layout = $em->getRepository(Layout::class)->find(Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);

    //         $pageLayout->setPage($page);
    //         $pageLayout->setPageId($page->getId());
    //         $pageLayout->setLayout($layout);
    //         $pageLayout->setLayoutId($layout->getId());

    //         $em->persist($pageLayout);
    //         $em->flush();
    //     }
    // }
    // protected function unregisterPageForUpdate($container){
    //     $page_names = [
    //         'mypage_stripe_rec',
    //         'mypage_stripe_cancel_confirm'
    //     ];
    //     $em = $container->get('doctrine.orm.entity_manager');
    //     foreach($page_names as $page_name){
    //         $page = $em->getRepository(Page::class)->findOneBy(['url' => $page_name]);
    //         if(is_null($page)){
    //             continue;
    //         }
    //         $pageLayoutRepository = $em->getRepository(PageLayout::class);
    //         $pageLayout = $pageLayoutRepository->findOneBy([
    //             'page_id' => $page->getId()
    //         ]);
    //         if(!is_null($pageLayout)){
    //             $em->remove($pageLayout);
    //             // $em->persist($pageLayout);
    //             $em->flush();
    //         }
    //         $em->remove($page);
    //         $em->flush();
    //     }
    // }

    private function createTokenPayment(ContainerInterface $container)
    {
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $paymentRepository = $entityManager->getRepository(Payment::class);
        $Payment = $paymentRepository->findOneBy([], ['sort_no' => 'DESC']);
        $sortNo = $Payment ? $Payment->getSortNo() + 1 : 1;
        $Payment = $paymentRepository->findOneBy(['method_class' => StripeRecurringNagMethod::class]);
        if (empty($Payment)) {
            $Payment = new Payment();
            $Payment->setCharge(0);
            $Payment->setSortNo($sortNo);
            $Payment->setVisible(true);
            $Payment->setMethodClass(StripeRecurringNagMethod::class);            
        }
        $Payment->setMethod("クレジットカード定期購入");
        $entityManager->persist($Payment);
        $entityManager->flush($Payment);
    }
    
    public function insertMailTemplate(ContainerInterface $container){
        $template_list = [
                [
                    'name'      =>  ConfigService::PAID_MAIL_NAME,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_paid.twig',
                    'mail_subject'  => 'Stripe 支払い成功',                
                ],
                [
                    'name'      =>  ConfigService::PAY_FAILED_MAIL_NAME,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_failed_invoice.twig',
                    'mail_subject'  =>  'Stripe 定期支払い失敗'
                ],
                [
                    'name'      =>  ConfigService::PAY_UPCOMING,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_upcoming_invoice.twig',
                    'mail_subject'  =>  'Stripe 定期支払い待機'//"Stripe Subsciption Payment Upcoming"
                ],
                [
                    'name'      =>  ConfigService::REC_CANCELED,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_canceled.twig',
                    'mail_subject'  =>  'Stripe 定期支払いキャンセル済み' // 'Stripe Subscription Cancel'
                ],
            ];
        $em = $container->get('doctrine.orm.entity_manager');
        foreach($template_list as $template){
            $template1 = $em->getRepository(MailTemplate::class)->findOneBy(["name" => $template["name"]]);
            if ($template1){
                continue;
            }
            $item = new MailTemplate();
            $item->setName($template["name"]);
            $item->setFileName($template["file_name"]);
            $item->setMailSubject($template["mail_subject"]);
            $em->persist($item);            
            $em->flush();
        }
    }

    public function removeMailTemplate(ContainerInterface $container){
        $name_list = [
            ConfigService::PAID_MAIL_NAME,
            ConfigService::PAY_FAILED_MAIL_NAME,
            ConfigService::PAY_UPCOMING,
            ConfigService::REC_CANCELED
        ];
        $em = $container->get('doctrine.orm.entity_manager');
        foreach($name_list as $name){
            $template = $em->getRepository(MailTemplate::class)->findOneBy(["name" => $name]);
            if ($template){
                $em->remove($template);
                $em->flush();
            }
        }
    }

    public function insertPurchasePoints(ContainerInterface $container){
        $purchase_points  = [
            [
                'id'        =>  0,
                'name'      =>  'stripe_recurring.admin.purchase_point_on_date_label',
                'point'     =>  PurchasePoint::POINT_ON_DATE,
                'sort_no'   =>  0,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  1,
                'name'      =>  'stripe_recurring.admin.purchase_point_next_week_label',
                'point'      =>  PurchasePoint::POINT_NEXT_WEEK,
                'sort_no'   =>  1,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  2,
                'name'      =>  'stripe_recurring.admin.purchase_point_next_month_label',
                'point'      =>  PurchasePoint::POINT_NEXT_MONTH,
                'sort_no'   =>  2,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  3,
                'name'      =>  'stripe_recurring.admin.purchase_point_next_years_label',
                'point'      =>  PurchasePoint::POINT_NEXT_YEAR,
                'sort_no'   =>  3,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  4,
                'name'      =>  'stripe_recurring.admin.purchase_point_after_days_label',
                'point'      =>  PurchasePoint::POINT_AFTER_DAYS,
                'sort_no'   =>  4,
                'enabled'   =>  true,
            ],
        ];
        $em = $container->get('doctrine.orm.entity_manager');

        foreach($purchase_points as $point){
            $item = $em->getRepository(PurchasePoint::class)->findOneBy(['point' =>  $point['point']]);
            if($item){
                continue;
            }
            $item = new PurchasePoint();
            $item->setId($point['id']);
            $item->setName(trans($point['name']));
            $item->setSortNo($point['sort_no']);
            $item->setPoint($point['point']);
            $item->setEnabled($point['enabled']);
            $em->persist($item);
            $em->flush();
        }
    }
    private function savePaymentOption(&$config_data){

        $this->set(self::PAYDAY_OPTION_1, 0);
        $this->set(self::PAYDAY_OPTION_2, 0);
        
        if(!empty($config_data['payday_option'])){
            $payday_options = $config_data['payday_option'];
            
            foreach($payday_options as $option){
                if($option == "first_date"){
                    $this->set(self::PAYDAY_OPTION_1, 1);
                }else if($option == "mid_date"){
                    $this->set(self::PAYDAY_OPTION_2, 1);
                }
            }
        }
        if(!empty($config_data['payment_date'])){
            $this->set(self::PAYMENT_DATE, $config_data['payment_date']);
        }else{
            $this->set(self::PAYMENT_DATE, 0);
        }
        unset($config_data['payday_option']);
        unset($config_data['payment_date']);
    }
    private function getPaymentDate(){
        $payday_option_1 = $this->get(self::PAYDAY_OPTION_1);

        $payment_date = $this->get(self::PAYMENT_DATE);

        $payday_option = [];
        if(!empty($payday_option_1)){
            $payday_option[] = "first_date";
        }
        if(!empty($payment_date)){
            $payday_option[] = "mid_date";
        }
        
        $res = [
            'payday_option' => $payday_option,
            'payment_date'  =>  empty($payment_date) ? NULL : $payment_date,
        ];
        return $res;
    }
    public function getPaymentDateOptions(){
        $payday_option_1 = $this->get(self::PAYDAY_OPTION_1);

        $payment_date = $this->get(self::PAYMENT_DATE);
        $pay_full = $this->get(self::PAY_FULL);

        $res = [];
        if(!empty($payday_option_1)){
            $res[self::PAYDAY_OPTION_1] = 1;
        }else{
            $res[self::PAYDAY_OPTION_1] = 0;
        }
        if(!empty($payment_date)){
            $res[self::PAYDAY_OPTION_2] = 1;
            $res[self::PAYMENT_DATE] = $payment_date;
        }else{
            $res[self::PAYDAY_OPTION_2] = 0;
            $res[self::PAYMENT_DATE] = NULL;
        }
        $res[self::PAY_FULL] = empty($pay_full) ? 0 : 1;
        return $res;
    }
}