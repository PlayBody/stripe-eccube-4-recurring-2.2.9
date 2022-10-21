<?php

/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec;


use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PageRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\StripeRec\Service\Method\StripeRecurringNagMethod;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\Page;
use Eccube\Entity\PageLayout;
use Eccube\Entity\Layout;
use Eccube\Exception\PluginException;
use Eccube\Entity\Plugin;
use Plugin\StripeRec\Service\ConfigService;
use Plugin\StripeRec\Entity\PurchasePoint;
use Plugin\StripeRec\Entity\RecCsv;

class PluginManager extends AbstractPluginManager{
    
    private $stripe_js_file_path;
    private $backup_path;
    private $stripe_instead;

    public function __construct(){
        $this->stripe_js_file_path = __DIR__ . "/../StripePaymentGateway/Resource/assets/js/stripe_js.twig";        
        $this->backup_path = __DIR__ . "/Resource/assets/js/stripe_js.twig";
        $this->stripe_instead = __DIR__ . "/Resource/assets/js/stripe_recurring_js.twig";
    }

    public function enable(array $meta, ContainerInterface $container)
    {        
        // if(!file_exists($this->stripe_js_file_path)){
        //     return;
        // }

        // $js_contents = file_get_contents($this->stripe_js_file_path);
        // file_put_contents($this->backup_path, $js_contents);
        // $instead_js = file_get_contents($this->stripe_instead);
        // file_put_contents($this->stripe_js_file_path, $instead_js);  
        
        // throw new \Exception();
        $this->createTokenPayment($container);
        $this->insertMailTemplate($container);
        $this->registerPageForUpdate($container);
        $this->insertPurchasePoints($container);
    }

    public function disable(array $meta, ContainerInterface $container){
        // if(!file_exists($this->stripe_js_file_path)){
        //     return;
        // }
        // if(!file_exists($this->backup_path)){
        //     return;
        // }
        // $backup_js = \file_get_contents($this->backup_path);
        // if(!empty($backup_js)){
        //     file_put_contents($this->stripe_js_file_path, $backup_js);
        // }
        $this->removeMailTemplate($container);
        $this->unregisterPageForUpdate($container);
    }
    /**
     * プラグインインストール時の処理
     *
     * @param array $meta
     * @param ContainerInterface $container
     */
    public function install(array $meta, ContainerInterface $container)
    {
        $stripe_org_path = __DIR__ . "/../StripePaymentGateway";
        if(!is_dir($stripe_org_path)){
            throw new PluginException("You have to install and enable StripePaymentGateway plugin");
        }

        $em = $container->get('doctrine.orm.entity_manager');
        $plg_repo = $em->getRepository(Plugin::class);
        $stripe_org_plg = $plg_repo->findOneBy(['code' => 'StripePaymentGateway']);
        if(empty($stripe_org_plg)){
            throw new PluginException("You have to install and enable StripePaymentGateway plugin");
        }
        if(!$stripe_org_plg->isEnabled()){
            throw new PluginException("You have to enable StripePaymentGateway plugin");
        }
        if(empty($stripe_org_plg->getVersion())){
            throw new PluginException("StripePaymentGateway plugin version is invalid");
        }
        if(\version_compare($stripe_org_plg->getVersion(), "1.2.9") < 0){
            throw new PluginException("StripePaymentGateway plugin version needs to be greater than 1.2.9");
        }
        $stripe_price_lib_path = __DIR__."/Resource/stripe_additional/Price.bakphp";
        $cont = \file_get_contents($stripe_price_lib_path);
        $to_path = __DIR__."/../StripePaymentGateway/vendor/stripe/stripe-php/lib";
        if(!is_dir($to_path)){
            throw new PluginException("StripePaymentGateway plugin version is not compatible.");
        }
        \file_put_contents($to_path . "/Price.php", $cont);

        $stripe_init_file = __DIR__."/../StripePaymentGateway/vendor/stripe/stripe-php/init.php";
        if(!\file_exists($stripe_init_file)){
            throw new PluginException("StripePaymentGateway plugin is invalid or not compatible");
        }
        $ini_cont = \file_get_contents($stripe_init_file);

        $res = \strpos($ini_cont, "/lib/Price.php");
        if(empty($res)){
            $ini_cont .= "\nrequire __DIR__ . '/lib/Price.php';";
            \file_put_contents($stripe_init_file, $ini_cont);
        }
        $this->warmUpRouteCache($container);
    }
    /**
     * プラグインアップデート時の処理
     *
     * @param array              $meta
     * @param ContainerInterface $container
     */
    public function update(array $meta, ContainerInterface $container)
    {
        // $this->registerPageForUpdate($container);
        try{
            $entityManager = $container->get('doctrine')->getManager();
            if(\method_exists($this, 'migration')){
                $this->migration($entityManager->getConnection(), $meta['code']);
            }
            self::updateCsvExportData($container);

        }catch(\Exception $e){}
    }
        
    /**
     * プラグインアンインストール時の処理
     *
     * @param array $meta
     * @param ContainerInterface $container
     */
    public function uninstall(array $meta, ContainerInterface $container)
    {
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
        $em->flush();
    }

    protected function warmUpRouteCache($container) {

        $router = $container->get('router');
        $filesystem = $container->get('filesystem');
        $kernel = $container->get('kernel');
        $cacheDir = $kernel->getCacheDir();
    
        foreach (array('matcher_cache_class', 'generator_cache_class') as $option) {
            $className = $router->getOption($option);
            $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $className . '.php';            
            $filesystem->remove($cacheFile);
        }
        
        // $router->warmUp($cacheDir);    
    }


    protected function registerPageForUpdate($container){
        $em = $container->get('doctrine.orm.entity_manager');
        $page_consts = array(
            [
                'name' =>  'mypage_stripe_rec',
                'label' =>  'MYページ/定期コース',
                'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_tab'
            ],
            [
                'name'  =>  'mypage_stripe_cancel_confirm',
                'label' =>  'MYページ/定期コースキャンセル',
                'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_cancel_confirm'
            ],
            [
                'name'  =>  'mypage_stripe_schedule',
                'label' =>  'MYページ/定期コーススケジュール',
                'template'  =>  'StripeRec/Resource/template/default/Mypage/schedule_tab'
            ],
            [
                'name'  =>  'plugin_striperec_update_method',
                'label' =>  '支払い方法変更',
                'template'  =>  'StripeRec/Resource/template/default/Shopping/collect_method'
            ],
            [
                'name'  =>  'plugin_striperec_checkout_page',
                'label' =>  '支払い画面',
                'template'  =>  'StripeRec/Resource/template/default/Shopping/checkout'
            ],
            
        );
        foreach($page_consts as $page_url){
            $url = $page_url['name'];
            $page = $em->getRepository(Page::class)->findOneBy(compact('url'));
            if(!\is_null($page)){
                continue;
            }
            $page = new Page;
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
    public function insertPurchasePoints(ContainerInterface $container){
        $purchase_points  = [
            [
                'id'        =>  0,
                'name'      =>  '即時',
                'point'     =>  PurchasePoint::POINT_ON_DATE,
                'sort_no'   =>  0,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  1,
                'name'      =>  '翌週から',
                'point'      =>  PurchasePoint::POINT_NEXT_WEEK,
                'sort_no'   =>  1,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  2,
                'name'      =>  ' 翌月から',
                'point'      =>  PurchasePoint::POINT_NEXT_MONTH,
                'sort_no'   =>  2,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  3,
                'name'      =>  '翌年から',
                'point'      =>  PurchasePoint::POINT_NEXT_YEAR,
                'sort_no'   =>  3,
                'enabled'   =>  true,
            ],
            [
                'id'        =>  4,
                'name'      =>  '日数入力',
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

    protected function unregisterPageForUpdate($container){
        $page_names = [
            'mypage_stripe_rec',
            'mypage_stripe_cancel_confirm'
        ];
        $em = $container->get('doctrine.orm.entity_manager');
        foreach($page_names as $page_name){
            $page = $em->getRepository(Page::class)->findOneBy(['url' => $page_name]);
            if(is_null($page)){
                continue;
            }
            $pageLayoutRepository = $em->getRepository(PageLayout::class);
            $pageLayout = $pageLayoutRepository->findOneBy([
                'page_id' => $page->getId()
            ]);
            if(!is_null($pageLayout)){
                $em->remove($pageLayout);
                // $em->persist($pageLayout);
                $em->flush();
            }
            $em->remove($page);
            $em->flush();
        }
    }

    
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
                    'file_name' =>  'StripeRec/Resource/template/mail/rec_order_paid.twig',
                    'mail_subject'  => 'Stripe 支払い成功',                
                ],
                [
                    'name'      =>  ConfigService::PAY_FAILED_MAIL_NAME,
                    'file_name' =>  'StripeRec/Resource/template/mail/rec_order_failed_invoice.twig',
                    'mail_subject'  =>  'Stripe 定期支払い失敗'
                ],
                [
                    'name'      =>  ConfigService::PAY_UPCOMING,
                    'file_name' =>  'StripeRec/Resource/template/mail/rec_order_upcoming_invoice.twig',
                    'mail_subject'  =>  'Stripe 定期支払い待機'//"Stripe Subsciption Payment Upcoming"
                ],
                [
                    'name'      =>  ConfigService::REC_CANCELED,
                    'file_name' =>  'StripeRec/Resource/template/mail/rec_order_canceled.twig',
                    'mail_subject'  =>  'Stripe 定期支払いキャンセル済み' // 'Stripe Subscription Cancel'
                ],
                [
                    'name'      =>  ConfigService::REC_ORDER_THANKS,
                    'file_name' =>  'StripeRec/Resource/template/mail/order.twig',
                    'mail_subject'  =>  'ご注文ありがとうございます'
                ]
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
            ConfigService::REC_CANCELED,
            ConfigService::REC_ORDER_THANKS,
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

    public static function updateCsvExportData(ContainerInterface $container)
    {
        $entityManager = $container->get("doctrine.orm.entity_manager");
        $csv_export_repo = $entityManager->getRepository(RecCsv::class);

        // get rid of n+1 problem for query
        $csvs = $csv_export_repo->findAll();
        foreach(self::CSV_EXPORT_DATA as $data) {
            $existing = false;
            foreach($csvs as $csv) {
                if ($csv->getId() == $data['id']) {
                    $existing = true;
                    break;
                }
            }
            
            if (!$existing) {
                $rec_csv = new RecCsv;
                $rec_csv->setId($data['id']);
                $rec_csv->setSortNo($data['sort_no']);
                if (!empty($data['type'])) {
                    $rec_csv->setType($data['type']);
                }
                if (!empty($data['entity'])) {
                    $rec_csv->setEntity($data['entity']);
                }
                if (!empty($data['field'])) {
                    $rec_csv->setField($data['field']);
                }
                if (!empty($data['name'])) {
                    $rec_csv->setName($data['name']);
                }
                if (!empty($data['label'])) {
                    $rec_csv->setLabel($data['label']);
                }
                if (!empty($data['value'])) {
                    $rec_csv->setValue($data['value']);
                }
                $entityManager->persist($rec_csv);
            }
        }
        $order_id_csv = $csv_export_repo->findOneBy(['field' => 'Order/Id']);
        if ($order_id_csv) {
            $entityManager->remove($order_id_csv);
        }
        $order_id_csv = $csv_export_repo->findOneBy(['name' => 'version']);
        if ($order_id_csv) {
            $entityManager->remove($order_id_csv);
        }
        $entityManager->flush();
    }

    const CSV_EXPORT_DATA = [
        // [
        //     "id"    =>  1,
        //     "sort_no"=> 1,
        //     "type"  => "const",
        //     "name"  =>  "version",
        //     "label" =>  "Version",
        //     "value" =>  "1.0",
        // ],
        [
            "id"    =>  2,
            "sort_no"=> 2,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "Id",
            "label" =>  "定期コースID",
        ],
        [
            "id"    =>  3,
            "sort_no"=> 3,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "PaidOrders/Collection/Id",
            "label" =>  "注文IDs",
        ],
        [
            "id"    =>  4,
            "sort_no"=> 4,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "PaymentCount",
            "label" =>  "決済回数",
        ],
        [
            "id"    =>  5,
            "sort_no"=> 5,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "SubscriptionId",
            "label" =>  "Stripe定期支払いID",
        ],
        [
            "id"    =>  6,
            "sort_no"=> 6,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Name01",
            "label" =>  "お名前(姓)",
        ],
        [
            "id"    =>  7,
            "sort_no"=> 7,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Name02",
            "label" =>  "お名前(名)",
        ],
        [
            "id"    =>  8,
            "sort_no"=> 8,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Kana01",
            "label" =>  "お名前(セイ)",
        ],
        [
            "id"    =>  9,
            "sort_no"=> 9,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Kana02",
            "label" =>  "お名前(メイ)",
        ],
        [
            "id"    =>  10,
            "sort_no"=> 10,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "CompanyName",
            "label" =>  "会社名",
        ],
        [
            "id"    =>  11,
            "sort_no"=> 11,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "PostalCode",
            "label" =>  "郵便番号",
        ],
        [
            "id"    =>  12,
            "sort_no"=> 12,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Pref/Id",
            "label" =>  "都道府県(ID)",
        ],
        [
            "id"    =>  13,
            "sort_no"=> 13,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Pref/Name",
            "label" =>  "都道府県(名称)",
        ],
        [
            "id"    =>  14,
            "sort_no"=> 14,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "addr01",
            "label" =>  "住所1",
        ],
        [
            "id"    =>  15,
            "sort_no"=> 15,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "addr02",
            "label" =>  "住所2",
        ],
        [
            "id"    =>  16,
            "sort_no"=> 16,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Email",
            "label" =>  "メールアドレス",
        ],
        [
            "id"    =>  17,
            "sort_no"=> 17,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "PhoneNumber",
            "label" =>  "TEL",
        ],
        [
            "id"    =>  18,
            "sort_no"=> 18,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Sex/Id",
            "label" =>  "性別(ID)",
        ],
        [
            "id"    =>  19,
            "sort_no"=> 19,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Sex/Name",
            "label" =>  "性別(名称)",
        ],
        [
            "id"    =>  20,
            "sort_no"=> 20,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Job/Id",
            "label" =>  "職業(ID)",
        ],
        [
            "id"    =>  21,
            "sort_no"=> 21,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Job/Name",
            "label" =>  "職業(名称)",
        ],
        [
            "id"    =>  22,
            "sort_no"=> 22,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Birth",
            "label" =>  "誕生日",
        ],
        [
            "id"    =>  23,
            "sort_no"=> 23,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Note",
            "label" =>  "ショップ用メモ欄",
        ],
        [
            "id"    =>  24,
            "sort_no"=> 24,
            "type"  =>  "parameter",
            "field" =>  "initial_amount",
            "label" =>  "初期決済金額",
        ],
        [
            "id"    =>  25,
            "sort_no"=> 25,
            "type"  =>  "parameter",
            "field" =>  "recurring_amount",
            "label" =>  "定期決済金額",
        ],
        [
            "id"    =>  26,
            "sort_no"=> 26,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CreateDate",
            "label" =>  "生成日付",
        ],
        [
            "id"    =>  27,
            "sort_no"=> 27,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CurrentPeriodStart",
            "label" =>  "開始時刻",
        ],
        [
            "id"    =>  28,
            "sort_no"=> 28,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CurrentPeriodEnd",
            "label" =>  "終了時刻",
        ],
        [
            "id"    =>  29,
            "sort_no"=> 29,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "StripeCustomerId",
            "label" =>  "Stripe会員ID",
        ],
        [
            "id"    =>  30,
            "sort_no"=> 30,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "Customer/Id",
            "label" =>  "EC会員ID",
        ],
        [
            "id"    =>  31,
            "sort_no"=> 31,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "RecStatus",
            "label" =>  "定期購入状態",
        ],
        [
            "id"    =>  32,
            "sort_no"=> 32,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "PaidStatus",
            "label" =>  "最終決済状態",
        ],
        [
            "id"    =>  33,
            "sort_no"=> 33,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CancelReason",
            "label" =>  "キャンセル理由",
        ],
        [
            "id"    =>  34,
            "sort_no"=> 34,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "LastPaymentDate",
            "label" =>  "最終決済日",
        ],
        [
            "id"    =>  35,
            "sort_no"=> 35,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "StripeCustomerEmail",
            "label" =>  "Stripe会員メール",
        ],
        [
            "id"    =>  36,
            "sort_no"=> 36,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "Interval",
            "label" =>  "継続課金設定",
        ],
        [
            "id"    =>  37,
            "sort_no"=> 37,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "LastChargeId",
            "label" =>  "最終支払いID",
        ],
        [
            "id"    =>  38,
            "sort_no"=> 38,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "StartDate",
            "label" =>  "注文日",
        ],
        [
            "id"    =>  39,
            "sort_no"=> 39,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "Bundling",
            "label" =>  "オプション商品",
        ],
        [
            "id"    =>  40,
            "sort_no"=> 40,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "ScheduleId",
            "label" =>  "定期決済予定ID",
        ],
        [
            "id"    =>  41,
            "sort_no"=> 41,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CouponId",
            "label" =>  "クーポンID",
        ],
        [
            "id"    =>  42,
            "sort_no"=> 42,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CouponDiscountStr",
            "label" =>  "クーポン文字",
        ],
        [
            "id"    =>  43,
            "sort_no"=> 43,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "CouponName",
            "label" =>  "クーポン名",
        ],
        
        // [
        //     "type"  =>  "field",
        //     "name"  =>  "rec_order",
        //     "field" =>  "ManualLinkStamp",
        //     "label" =>  "定期コースID",
        // ],

        [
            "id"    =>  44,
            "sort_no"=> 44,
            "type"  =>  "field",
            "entity"  =>  "rec_order",
            "field" =>  "FailedInvoice",
            "label" =>  "請求書の失敗",
        ],
        // [
        //     "id"    =>  45,
        //     "sort_no"=> 45,
        //     "type"  =>  "field",
        //     "entity"  =>  "rec_order",
        //     "field" =>  "Order/Id",
        //     "label" =>  "注文ID(src)",
        // ],
        [
            "id"    =>  46,
            "sort_no"=> 46,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "Id",
            "label" =>  "ショップ用メモ欄",
        ],
        [
            "id"    =>  47,
            "sort_no"=> 47,
            "type"  =>   "field",
            "entity"=>  "OrderItem",
            "field" =>  "Product/Id",
            "label" =>  "商品ID",
        ],
        [
            "id"    =>  48,
            "sort_no"=> 48,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ProductClass/Id",
            "label" =>  "商品規格ID",
        ],
        [
            "id"    =>  49,
            "sort_no"=> 49,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ProductName",
            "label" =>  "商品名",
        ],
        [
            "id"    =>  50,
            "sort_no"=> 50,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ProductCode",
            "label" =>  "商品コード",
        ],
        [
            "id"    =>  51,
            "sort_no"=> 51,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ClassName1",
            "label" =>  "規格名1",
        ],
        [
            "id"    =>  52,
            "sort_no"=> 52,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ClassName2",
            "label" =>  "規格名2",
        ],
        [
            "id"    =>  53,
            "sort_no"=> 53,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ClassCategoryName1",
            "label" =>  "規格分類名1",
        ],
        [
            "id"    =>  54,
            "sort_no"=> 54,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "ClassCategoryName2",
            "label" =>  "規格分類名2",
        ],
        [
            "id"    =>  55,
            "sort_no"=> 55,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "Price",
            "label" =>  "価格",
        ],
        [
            "id"    =>  56,
            "sort_no"=> 56,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "Quantity",
            "label" =>  "個数",
        ],
        [
            "id"    =>  57,
            "sort_no"=> 57,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "TaxRate",
            "label" =>  "税率",
        ],
        [
            "id"    =>  58,
            "sort_no"=> 58,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "TaxRuleId",
            "label" =>  "税率ルール(ID)",
        ],
        [
            "id"    =>  59,
            "sort_no"=> 59,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "OrderItemType/Id",
            "label" =>  "明細区分(ID)",
        ],
        [
            "id"    =>  60,
            "sort_no"=> 60,
            "type"  =>  "field",
            "entity"=>  "OrderItem",
            "field" =>  "OrderItemType/Name",
            "label" =>  "明細区分(名称)",
        ],
        [
            "id"    =>  61,
            "sort_no"=> 61,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Id",
            "label" =>  "出荷ID",
        ],
        [
            "id"    =>  62,
            "sort_no"=> 62,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Name01",
            "label" =>  "配送先_お名前(姓)",
        ],
        [
            "id"    =>  63,
            "sort_no"=> 63,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Name02",
            "label" =>  "配送先_お名前(名)",
        ],
        [
            "id"    =>  64,
            "sort_no"=> 64,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Kana01",
            "label" =>  "配送先_お名前(セイ)",
        ],
        [
            "id"    =>  65,
            "sort_no"=> 65,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Kana02",
            "label" =>  "配送先_お名前(メイ)",
        ],
        [
            "id"    =>  66,
            "sort_no"=> 66,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "CompanyName",
            "label" =>  "配送先_会社名",
        ],
        [
            "id"    =>  67,
            "sort_no"=> 67,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "PostalCode",
            "label" =>  "配送先_郵便番号",
        ],
        [
            "id"    =>  68,
            "sort_no"=> 68,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Pref/Id",
            "label" =>  "配送先_都道府県(ID)",
        ],
        [
            "id"    =>  69,
            "sort_no"=> 69,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Pref/Name",
            "label" =>  "配送先_都道府県(名称)",
        ],
        [
            "id"    =>  70,
            "sort_no"=> 70,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Addr01",
            "label" =>  "配送先_住所1",
        ],
        [
            "id"    =>  71,
            "sort_no"=> 71,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Addr02",
            "label" =>  "配送先_住所2",
        ],
        [
            "id"    =>  72,
            "sort_no"=> 72,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "PhoneNumber",
            "label" =>  "配送先_TEL",
        ],
        [
            "id"    =>  73,
            "sort_no"=> 73,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Delivery/Id",
            "label" =>  "配送業者(ID)",
        ],
        [
            "id"    =>  74,
            "sort_no"=> 74,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "ShippingDeliveryName",
            "label" =>  "配送業者(名称)",
        ],
        // [
        //     "type"  =>  "field",
        //     "entity"=>  "Shipping",
        //     "field" =>  "DeliveryTime/Id",
        //     "label" =>  "お届け時間ID",
        // ],
        [
            "id"    =>  75,
            "sort_no"=> 75,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "ShippingDeliveryTime",
            "label" =>  "お届け時間(名称)",
        ],
        [
            "id"    =>  76,
            "sort_no"=> 76,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "ShippingDeliveryDate",
            "label" =>  "お届け希望日",
        ],
        // [
        //     "type"  =>  "field",
        //     "entity"=>  "Shipping",
        //     "field" =>  "DeliveryFee/Id",
        //     "label" =>  "送料ID",
        // ],
        // [
        //     "type"  =>  "field",
        //     "entity"=>  "Shipping",
        //     "field" =>  "ShippingDeliveryFee",
        //     "label" =>  "送料",
        // ],
        [
            "id"    =>  77,
            "sort_no"=> 77,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "ShippingDate",
            "label" =>  "発送日",
        ],
        [
            "id"    =>  78,
            "sort_no"=> 78,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "TrackingNumber",
            "label" =>  "出荷伝票番号",
        ],
        [
            "id"    =>  79,
            "sort_no"=> 79,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "Note",
            "label" =>  "配達用メモ",
        ],
        [
            "id"    =>  80,
            "sort_no"=> 80,
            "type"  =>  "field",
            "entity"=>  "Shipping",
            "field" =>  "MailSendDate",
            "label" =>  "出荷メール送信日",
        ],
        [
            "id"    =>  82,
            "sort_no"=> 82,
            "type"  =>  "field",
            "entity"=>  "Order",
            "field" =>  "Customer/Id",
            "label" =>  "会員ID",
        ],
    ];
}