<?php

/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Service\Admin;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PageRepository;
use Plugin\StripeRec\Service\Method\StripeRecurringNagMethod;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\Page;
use Eccube\Entity\PageLayout;
use Eccube\Entity\Layout;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;

class ConfigService{

    const WEBHOOK_SIGNATURE = "webhook_signature";

    //=======mail config=========
    const PAID_MAIL_NAME = "Stripe 支払い成功メール";
    const PAY_FAILED_MAIL_NAME = "Stripe 定期支払い失敗メール";
    const PAY_UPCOMING = "Stripe 定期支払い待機メール";
    const REC_CANCELED = "Stripe 定期支払いキャンセル済みメール";

    //===========================
    


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
        return [
            ConfigService::WEBHOOK_SIGNATURE =>  $this->get(ConfigService::WEBHOOK_SIGNATURE)
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
        $old_data = $this->getConfig();
        $diff_keys = [];
        foreach($old_data as $k => $v){
            if(!empty($new_data[$k]) && $new_data[$k] !== $old_data[$k]){
                $diff_keys[] = $k;
                $this->set($k, $new_data[$k]);
            }
        }
        return $diff_keys;
    }    
    public function get($key){        
        // $rec_props_path = $this->container->getParameter('plugin_realdir'). '/StripeRec/Resource/config/webhook.properties';
        
        $rec_props_path = $this->eccubeConfig->get('kernel.project_dir') . "/var/stripe_webhook.properties";
        
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
        $rec_props_path = $this->eccubeConfig->get('kernel.project_dir') . "/var/stripe_webhook.properties";
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
        $this->createTokenPayment();
        $this->insertMailTemplate();
        $this->registerPageForUpdate();
    }
    public function disable_plugin(){        
        $em = $this->container->get('doctrine.orm.entity_manager');
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
        // $em->persist($Payment);
        $em->flush();
    }
    public function isEnable(){
        return $this->enable;
    }
    private function createTokenPayment()
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        $paymentRepository = $entityManager->getRepository(Payment::class);
        $Payment = $paymentRepository->findOneBy([], ['sort_no' => 'DESC']);
        $sortNo = $Payment ? $Payment->getSortNo() + 1 : 1;
        $Payment = $paymentRepository->findOneBy(['method_class' => StripeRecurringNagMethod::class]);
        if ($Payment) {
            if(!$Payment->isVisible()){
                $Payment->setVisible(true);
                // $entityManager->persist($Payment);
                $entityManager->flush();
            }
            return;
        }
        $Payment = new Payment();
        $Payment->setCharge(0);
        $Payment->setSortNo($sortNo);
        $Payment->setVisible(true);
        $Payment->setMethod("クレジットカード定期購入");
        $Payment->setMethodClass(StripeRecurringNagMethod::class);
        $entityManager->persist($Payment);
        $entityManager->flush($Payment);
    }
    public function insertMailTemplate(){
        $template_list = [
                [
                    'name'      =>  ConfigService::PAID_MAIL_NAME,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_success.twig',
                    'mail_subject'  => 'Stripe 支払い成功',                
                ],
                [
                    'name'      =>  ConfigService::PAY_FAILED_MAIL_NAME,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_failed.twig',
                    'mail_subject'  =>  'Stripe 定期支払い失敗'
                ],
                [
                    'name'      =>  ConfigService::PAY_UPCOMING,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_upcoming.twig',
                    'mail_subject'  =>  'Stripe 定期支払い待機'//"Stripe Subsciption Payment Upcoming"
                ],
                [
                    'name'      =>  ConfigService::REC_CANCELED,
                    'file_name' =>  'StripeRec\Resource\template\mail\rec_order_canceled.twig',
                    'mail_subject'  =>  'Stripe 定期支払いキャンセル済み' // 'Stripe Subscription Cancel'
                ]
            ];
        $em = $this->container->get('doctrine.orm.entity_manager');
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
    protected function registerPageForUpdate(){
        $em = $this->container->get('doctrine.orm.entity_manager');
        $page_consts = array(
            [
            'name' =>  'mypage_stripe_rec',
            'label' =>  'MYページ/定期コース',
            'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_tab'
            ]
        );
        foreach($page_consts as $page_url){
            $url = $page_url['name'];
            $page = $em->getRepository(Page::class)->findOneBy(compact('url'));
            if(is_null($page)){
                $page = new Page;
            }
            $page->setName($page_url['label']);
            $page->setUrl($url);
            $page->setMetaRobots('noindex');
            $page->setFileName($page_url['template']);
            $page->setEditType(Page::EDIT_TYPE_DEFAULT);

            $em->persist($page);
            $em->flush();
            // $em->commit();
            
            $pageLayoutRepository = $em->getRepository(PageLayout::class);
            $pageLayout = $pageLayoutRepository->findOneBy([
                'page_id' => $page->getId()
            ]);
            // 存在しない場合は新規作成
            if (is_null($pageLayout)) {
                $pageLayout = new PageLayout;
                // 存在するレコードで一番大きいソート番号を取得
                $lastSortNo = $pageLayoutRepository->findOneBy([], ['sort_no' => 'desc'])->getSortNo();
                // ソート番号は新規作成時のみ設定
                $pageLayout->setSortNo($lastSortNo+1);
            }
            // 下層ページ用レイアウトを取得
            $layout = $em->getRepository(Layout::class)->find(Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);

            $pageLayout->setPage($page);
            $pageLayout->setPageId($page->getId());
            $pageLayout->setLayout($layout);
            $pageLayout->setLayoutId($layout->getId());

            $em->persist($pageLayout);
            $em->flush();
        }
    }
}