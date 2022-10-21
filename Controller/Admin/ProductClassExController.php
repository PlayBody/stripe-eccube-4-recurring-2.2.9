<?php

namespace Plugin\StripeRec\Controller\Admin;

use Eccube\Controller\Admin\Product\ProductClassController;
use Symfony\Component\HttpFoundation\Request;
use Eccube\Util\CacheUtil;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductStock;
use Eccube\Entity\Product;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\ClassCategoryRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TaxRuleRepository;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Doctrine\ORM\Query;

class ProductClassExController extends ProductClassController{

    protected $container;
    protected $err_msg = "";
    protected $rec_service;

    const LOG_IF = "ProductClassExController---";

    /**
     * for save before submitted product class price
     */
    protected $before_pc_prices = [];

    /**
     * ProductClassController constructor.
     *
     * @param ProductClassRepository $productClassRepository
     * @param ClassCategoryRepository $classCategoryRepository
     */
    public function __construct(
        ProductRepository $productRepository,
        ProductClassRepository $productClassRepository,
        ClassCategoryRepository $classCategoryRepository,
        BaseInfoRepository $baseInfoRepository,
        TaxRuleRepository $taxRuleRepository,
        ContainerInterface $container
    ) {
        $this->container = $container;
        parent::__construct($productRepository, $productClassRepository, $classCategoryRepository, $baseInfoRepository, $taxRuleRepository);        
        $this->rec_service = $this->container->get("plg_stripe_rec.service.recurring_service");
    }

    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/product/product/class/{id}", requirements={"id" = "\d+"}, name="admin_product_product_class")
     * @Template("@StripeRec/admin/product_class_edit.twig")
     */
    public function index(Request $request, $id, CacheUtil $cacheUtil)
    {
        $Product = $this->findProduct($id);
        if (!$Product) {
            throw new NotFoundHttpException();
        }

        $ClassName1 = null;
        $ClassName2 = null;

        if ($Product->hasProductClass()) {
            // 規格ありの商品は編集画面を表示する.
            $ProductClasses = $Product->getProductClasses()
                ->filter(function ($pc) {
                    return $pc->getClassCategory1() !== null;
                });
            $this->saveBeforePrices($ProductClasses);

            // 設定されている規格名1, 2を取得(商品規格の規格分類には必ず同じ値がセットされている)
            $FirstProductClass = $ProductClasses->first();
            $ClassName1 = $FirstProductClass->getClassCategory1()->getClassName();
            $ClassCategory2 = $FirstProductClass->getClassCategory2();
            $ClassName2 = $ClassCategory2 ? $ClassCategory2->getClassName() : null;

            // 規格名1/2から組み合わせを生成し, DBから取得した商品規格とマージする.
            $ProductClasses = $this->mergeProductClasses(
                $this->createProductClasses($ClassName1, $ClassName2),
                $ProductClasses);

            // 組み合わせのフォームを生成する.
            $form = $this->createMatrixForm($ProductClasses, $ClassName1, $ClassName2,
                ['product_classes_exist' => true]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // フォームではtokenを無効化しているのでここで確認する.
                $this->isTokenValid();
                
                $this->saveProductClasses($Product, $form['product_classes']->getData());
                if(empty($this->err_msg)){
                    $this->addSuccess('admin.common.save_complete', 'admin');
                }


                $cacheUtil->clearDoctrineCache();

                if ($request->get('return_product_list')) {
                    return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId(), 'return_product_list' => true]);
                }

                return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
            }
        } else {
            // 規格なし商品
            $form = $this->createMatrixForm();
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // フォームではtokenを無効化しているのでここで確認する.
                $this->isTokenValid();

                // 登録,更新ボタンが押下されたかどうか.
                $isSave = $form['save']->isClicked();

                // 規格名1/2から商品規格の組み合わせを生成する.
                $ClassName1 = $form['class_name1']->getData();
                $ClassName2 = $form['class_name2']->getData();
                $ProductClasses = $this->createProductClasses($ClassName1, $ClassName2);

                // 組み合わせのフォームを生成する.
                // class_name1, class_name2が取得できるのがsubmit後のため, フォームを再生成して組み合わせ部分を構築している
                // submit後だと, フォーム項目の追加やデータ変更が許可されないため.
                $form = $this->createMatrixForm($ProductClasses, $ClassName1, $ClassName2,
                    ['product_classes_exist' => true]);

                // 登録ボタン押下時
                if ($isSave) {
                    $form->handleRequest($request);
                    if ($form->isSubmitted() && $form->isValid()) {       
                        $this->saveProductClasses($Product, $form['product_classes']->getData());

                        if(empty($this->err_msg)){
                            $this->addSuccess('admin.common.save_complete', 'admin');
                        }

                        $cacheUtil->clearDoctrineCache();

                        if ($request->get('return_product_list')) {
                            return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId(), 'return_product_list' => true]);
                        }

                        return $this->redirectToRoute('admin_product_product_class', ['id' => $Product->getId()]);
                    }
                }
            }
        }

        return [
            'Product' => $Product,
            'form' => $form->createView(),
            'clearForm' => $this->createForm(FormType::class)->createView(),
            'ClassName1' => $ClassName1,
            'ClassName2' => $ClassName2,
            'return_product_list' => $request->get('return_product_list') ? true : false,
        ];
    }

    
    /**
     * 商品規格を登録, 更新する.
     *
     * @param Product $Product
     * @param array|ProductClass[] $ProductClasses
     */
    protected function saveProductClasses(Product $Product, $ProductClasses = [])
    {        
        $i = 0;
        foreach ($ProductClasses as $pc) {            
            $i++;
            // 新規登録時、チェックを入れていなければ更新しない            
            if (!$pc->getId() && !$pc->isVisible()) {
                continue;
            }                        
                        
            $stripe_register_flg = false;
            // 無効から有効にした場合は, 過去の登録情報を検索.
            if (!$pc->getId()) {
                /** @var ProductClass $ExistsProductClass */
                $ExistsProductClass = $this->productClassRepository->findOneBy([
                    'Product' => $Product,
                    'ClassCategory1' => $pc->getClassCategory1(),
                    'ClassCategory2' => $pc->getClassCategory2(),
                ]);

                
                // 過去の登録情報があればその情報を復旧する.
                if ($ExistsProductClass) {
                    $stripe_register_flg = $this->checkPriceChange($pc, $ExistsProductClass);
                    $ExistsProductClass->copyProperties($pc, [
                        'id',
                        'price01_inc_tax',
                        'price02_inc_tax',
                        'create_date',
                        'update_date',
                        'Creator',
                    ]);
                    $pc = $ExistsProductClass;
                }else{
                    $stripe_register_flg = $this->checkPriceChange($pc);
                }
            }else{                
                // $ExistsProductClass = $this->productClassRepository->findOneBy([
                //     'id'    =>  $pc->getId()
                // ]);
                // if ($ExistsProductClass) {
                //     $stripe_register_flg = $this->checkPriceChange($pc, $ExistsProductClass);
                // }else{
                    $stripe_register_flg = $this->checkPriceChange($pc);
                // }
            }

            // 更新時, チェックを外した場合はPOST内容を破棄してvisibleのみ更新する.
            if ($pc->getId() && !$pc->isVisible()) {
                $this->entityManager->refresh($pc);
                $pc->setVisible(false);
                continue;
            }
            $pc->setProduct($Product);            
            
            if($stripe_register_flg){     
                if($stripe_register_flg === 'update'){                    
                    $pc_new = $this->updateProductClass($pc);
                    if(empty($pc_new)){
                        $this->addError("stripe_rec.admin.stripe_price.update_err", 'admin');
                        $this->err_msg = true;
                    }else{
                        $pc = $pc_new;
                    }
                }else{                    
                    $pc_new = $this->registerProductClass($pc, $pc->getRegisterFlg());
                    if(empty($pc_new)){
                        $this->addError("stripe_rec.admin.stripe_price.register_err", 'admin');
                    }else{
                        $pc = $pc_new;
                    }
                }
            }            
            $this->entityManager->persist($pc);  
            $this->entityManager->flush();

            // 在庫の更新
            $ProductStock = $pc->getProductStock();
            if (!$ProductStock) {
                $ProductStock = new ProductStock();
                $ProductStock->setProductClass($pc);
                $this->entityManager->persist($ProductStock);
            }
            $ProductStock->setStock($pc->isStockUnlimited() ? null : $pc->getStock());

            if ($this->baseInfoRepository->get()->isOptionProductTaxRule()) {
                $rate = $pc->getTaxRate();
                $TaxRule = $pc->getTaxRule();
                if (is_numeric($rate)) {
                    if ($TaxRule) {
                        $TaxRule->setTaxRate($rate);
                    } else {
                        // 現在の税率設定の計算方法を設定する
                        $TaxRule = $this->taxRuleRepository->newTaxRule();
                        $TaxRule->setProduct($Product);
                        $TaxRule->setProductClass($pc);
                        $TaxRule->setTaxRate($rate);
                        $TaxRule->setApplyDate(new \DateTime());
                        $this->entityManager->persist($TaxRule);
                    }
                } else {
                    if ($TaxRule) {
                        $this->taxRuleRepository->delete($TaxRule);
                        $pc->setTaxRule(null);
                    }
                }
            }            
        }

        // デフォルト規格を非表示にする.
        $DefaultProductClass = $this->productClassRepository->findOneBy([
            'Product' => $Product,
            'ClassCategory1' => null,
            'ClassCategory2' => null,
        ]);
        if($DefaultProductClass->isVisible()){
            $DefaultProductClass->setVisible(false);
            $this->unregisterStripeProdClass(new Request, $DefaultProductClass->getId());
            $this->entityManager->flush();
        }

        return true;
    }

    
    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/product/product/{id}/stripe_register", requirements={"id" = "\d+"}, name="stripe_rec_product_stripe_register")     
     */
    public function registerProduct($id){
        
        $Product = $this->findProduct($id);

        if (!$Product) {
            return $this->json(['result' => false]);
        }
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
        $res = $stripe_service->registerProduct($Product);
        
            return $this->json(['result'    => $res ]);
        // }else{
        //     $Product->setStripeProdId($res->id);
        //     $this->entityManager->persist($Product);
        //     $this->entityManager->flush();
        //     return $this->json(['result' => true]);
        // }
    }

    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/plugin/stripeRec/prod-class/{id}/stripe_unregister", requirements={"id" = "\d+"}, name="stripe_rec_prodclass_unregister")     
     */
    public function unregisterStripeProdClass(Request $request, $id)
    {
        $prod_class = $this->entityManager->getRepository(ProductClass::class)->findOneBy(["id" => $id]);
        if(empty($prod_class)){
            return $this->redirectToRoute("admin_product");            
        }

        $Product = $prod_class->getProduct();

        $price_id = $prod_class->getStripePriceId();
        if(empty($price_id)){
            return $this->redirectToRoute("admin_product_product_class", ['id' => $Product->getId()]);
        }
        $rec_orders = $this->entityManager->getRepository(StripeRecOrder::class)->getByPriceId($price_id);
        
        $res = true;
        foreach($rec_orders as $rec_order){            
            $res = $this->rec_service->cancelRecurring($rec_order);
            if($res == true){
                $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_CANCELED);
                $this->entityManager->persist($rec_order);
                $this->entityManager->flush();
                $this->addSuccess(trans("stripe_rec.admin.product_class.cancel_success") . " id=" . $rec_order->getSubscriptionId() ? $rec_order->getSubscriptionId() : "None", 'admin');
            }else{
                $res = false;
                $this->addError(trans("stripe_rec.admin.product_class.cancel_failed") . " id=" . $rec_order->getSubscriptionId() ? $rec_order->getSubscriptionId() : "None", 'admin');
            }
        }

        if ($res === false){
            return $this->redirectToRoute("admin_product_product_class", ['id' => $Product->getId()]);
        }
        $schedules = $this->entityManager->getRepository(StripeRecOrder::class)->getByBundledCodeNotStarting($prod_class->getCode());
        foreach($schedules as $schedule){
            $res = $this->rec_service->cancelRecurring($schedule);
            if(empty($res)){
                $this->addError($this->rec_service->getErrMsg());
            }
        break;
        }

        $prod_class->setStripePriceId(null);
        $prod_class->setInterval(null);
        $this->entityManager->persist($prod_class);
        $this->entityManager->flush();

        $this->addSuccess("stripe_rec.admin.product_class.unregister_success", 'admin');
        
        if($Product->hasProductClass()){
            return $this->redirectToRoute("admin_product_product_class", ['id' => $Product->getId()]);
        }else{
            return $this->redirectToRoute("admin_product_product_edit", ['id' => $Product->getId()]);
        }
    }

    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/plugin/stripeRec/product/{id}/stripe_unregister", requirements={"id" = "\d+"}, name="stripe_rec_prod_unregister")     
     */
    public function unregisterStripeProduct(Request $request, $id)
    {
        // 
        $Product = $this->findProduct($id);
        if (!$Product || !$Product->isStripeProduct()) {
            return $this->redirectToRoute("admin_product_product_edit", ['id' => $id]);
        }
        $prod_classes = $this->entityManager->getRepository(ProductClass::class)->findBy(['Product' => $Product]);
        $res = true;
        foreach($prod_classes as $prod_class){
            if($prod_class->isRegistered()){
                $price_id = $prod_class->getStripePriceId();
                if(empty($price_id)){
                    return $this->redirectToRoute("admin_product_product_class", ['id' => $Product->getId()]);
                }
                $rec_orders = $this->entityManager->getRepository(StripeRecOrder::class)->getByPriceId($price_id);                
                foreach($rec_orders as $rec_order){
                    $res = $this->rec_service->cancelRecurring($rec_order);
                    if($res == true){
                        $rec_order->setRecStatus(StripeRecOrder::REC_STATUS_CANCELED);
                        $this->entityManager->persist($rec_order);                        
                        $this->addSuccess(trans("stripe_rec.admin.product_class.cancel_success") . " id=" . $price_id, 'admin');
                    }else{
                        $res = false;
                        $this->addError(trans("stripe_rec.admin.product_class.cancel_failed") . " id=" . $price_id, 'admin');
                    }
                }
                // Cancel schedule
                $schedules = $this->entityManager->getRepository(StripeRecOrder::class)->getByBundledCodeNotStarting($prod_class->getCode());
                foreach($schedules as $schedule){
                    $res = $this->rec_service->cancelRecurring($schedule);
                    if(empty($res)){
                        $this->addError($this->rec_service->getErrMsg());
                    }
                }
                //---finalize-----
                $prod_class->setStripePriceId(null);
                $prod_class->setInterval(null);
                $this->entityManager->persist($prod_class);
                $this->entityManager->flush();
            }
            
        }
        if ($res === false){            
            return $this->redirectToRoute("admin_product_product_class", ['id' => $Product->getId()]);
        }
        $Product->setStripeProdId(null);
        $this->entityManager->persist($Product);
        $this->entityManager->flush();
        return $this->redirectToRoute("admin_product_product_edit", ['id' => $id]);
    }

    public function registerProductClass($prod_class, $interval){
        
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');        
        return $stripe_service->registerPrice($prod_class, $interval);
    }    
    public function updateProductClass($prod_class){
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');

        $price_id = $prod_class->getStripePriceId();
        $include_schedule = true;
        $rec_orders = $this->entityManager->getRepository(StripeRecOrder::class)->getByPriceId($price_id, $include_schedule)->toArray();         
        $this->entityManager->persist($prod_class);
        $this->entityManager->flush($prod_class);
        
        $new_pc = $stripe_service->updatePrice($prod_class);
        if(empty($new_pc)){
            log_info("ProductClassExController---update price empty---");
            return false;
        }
        log_info("ProductClassExController---update price success---");
        foreach($rec_orders as $rec_order){
            log_info("ProductClassExController---update rec order---" . $rec_order->getId());
            if(empty($rec_order->getSubscriptionId())){
                if($rec_order->isScheduled()){
                    $this->updateSchedule($price_id, $prod_class, $new_pc, $rec_order);
                }
                continue;
            }
            $res = $stripe_service->updateSubscription($rec_order->getSubscriptionId(), $new_pc->getStripePriceId(), $price_id);
            if(empty($res)){
                log_info("ProductClassExController---update subscription failed---");
                continue;
            }
            $rec_order->setSubscriptionId($res);            
            $this->entityManager->persist($rec_order);
        }
        // $this->updateScheduleByBundleProductClass($price_id, $prod_class, $new_pc);
        return $new_pc;
    }

    private function updateSchedule($old_price_id, $old_pc, $new_class, $schedule){
        if(!$schedule->isScheduled()){
            return;
        }
        $schedule_id = $schedule->getScheduleId();
        // echo $schedule_id . "<br>";
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');        
        $stripe_schedule = $stripe_service->retrieveNotStartingSchedule($schedule_id);

        if($stripe_schedule){
            $new_phases = [];
            $phases = $stripe_schedule->phases;
            $flg = true;
            log_info(self::LOG_IF . $stripe_schedule);

            foreach($phases as $k => $phase){
                $items = [];
                $plans = $phase->plans;                    
                foreach($plans as $ki => $plan){
                    $item = [];         
                    if($plan->price === $old_price_id){
                        log_info(self::LOG_IF . "old pc coincident . " . $old_price_id);
                        $item = [
                            'price' => $new_class->getStripePriceId(),
                            'quantity'  =>  $plan->quantity
                        ];
                    }else{
                        $item = [
                            'price' =>  $plan->price,
                            'quantity'  =>  $plan->quantity
                        ];
                    }
                    $items[] = $item;
                }
                if($flg){
                    $new_phases[] = [
                        'items'         =>  $items,                        
                        'proration_behavior' => 'none', 
                        'start_date'    =>  $phase->start_date,
                        'end_date'      =>  $phase->end_date
                    ];    
                    $flg = false;
                }else{
                    $new_phases[] = [
                        'plans'         =>  $items,                        
                        'proration_behavior' => 'none', 
                    ];
                }
            }
            $resp = $stripe_service->updateSchedule($schedule_id, [
                'phases'    =>  $new_phases,
                'end_behavior' =>  'release',                    
            ]);
            log_info(self::LOG_IF . __FUNCTION__ . "---update schedule response");
            log_info($resp);
        }
    }
    private function updateScheduleByBundleProductClass($old_price_id, $old_class, $new_class){
        $schedules = $this->entityManager->getRepository(StripeRecOrder::class)->getByBundledCodeNotStarting($old_class->getCode());
        $stripe_service = $this->container->get('plg_stripe_rec.service.stripe_service');
        foreach($schedules as $schedule){
            // $schedule = $schedules[0];            
            $this->updateSchedule($old_price_id, $old_class, $new_class, $schedule);
            // print_r($new_phases);
        }
    }
    public function checkPriceChange($new_pc, $old_pc = null){
        $register_flg = $new_pc->getRegisterFlg();        

        $interval_arr = [
            'day', 'month', 'week','year'
        ];
        if($old_pc && $old_pc->isRegistered()){
            if($new_pc->getPrice02() != $old_pc->getPrice02()){
                return 'update';
            }
        }
        if($new_pc->isRegistered()){
            // $connection = $this->entityManager->getConnection();
            // $statement = $connection->prepare('select price02 from dtb_product_class where id = :id and 1=1');
            // $statement->bindValue('id', $new_pc->getId());
            // $statement->execute();
            // $pcs = $statement->fetchAll();
            // // $pcs = $this->entityManager->createQuery("select price02 from {ProductClass} where id = {$new_pc->getId()};")
            // //     ->setHint(Query::HINT_REFRESH, true)
            // //     ->getResult();
            // $pcs[0]['price02'];
            
            // if(empty($this->before_pc_prices[$new_pc->getId()])){
            //     $before_price = 0;
            // }else{
            //     $before_price = $this->before_pc_prices[$new_pc->getId()];
            // }
            if(!empty($this->before_pc_prices[$new_pc->getId()])){                
                if($new_pc->getPrice02() != $this->before_pc_prices[$new_pc->getId()]){                    
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
    private function saveBeforePrices($ProductClasses){
        $this->before_pc_prices = [];
        foreach($ProductClasses as $pc){
            $this->before_pc_prices[$pc->getId()] = $pc->getPrice02();
        }
    }
}