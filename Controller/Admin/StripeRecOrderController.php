<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/


namespace Plugin\StripeRec\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\CsvType;
use Eccube\Entity\ExportCsvRow;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Plugin\StripeRec\Service\Admin\ConfigService;
use Plugin\StripeRec\Form\Type\Admin\StripeRecSearchType;
use Plugin\StripeRec\Form\Type\Admin\StripeScheduleSearchType;
use Eccube\Repository\Master\PageMaxRepository;
use Knp\Component\Pager\PaginatorInterface;
use Eccube\Util\FormUtil;
use Plugin\StripeRec\Repository\StripeRecOrderRepository;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Entity\PurchasePoint;
use Eccube\Form\Type\Admin\OrderType;
use Eccube\Entity\Order;
use Eccube\Entity\MailHistory;
use Eccube\Util\CacheUtil;
use Eccube\Event\EventArgs;
use Plugin\StripeRec\Service\Admin\CsvExportService;
use Plugin\StripeRec\Service\PointBundleService;
use Plugin\StripeRec\Entity\RecCsv;
use Plugin\StripeRec\Form\Type\Admin\RecCsvType;
use Plugin\StripeRec\Form\Type\Admin\RecOrderType;
use Plugin\StripeRec\StripeRecEvent;

class StripeRecOrderController extends AbstractController
{
    protected $container;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var StripeRecOrderRepository
     */    
    protected $stripe_rec_repo;
    protected $em;
    protected $rec_service;

    public function __construct(
        ContainerInterface $container,
        PageMaxRepository $pageMaxRepository,
        StripeRecOrderRepository $stripe_rec_repo
    ){
        $this->container = $container;
        $this->pageMaxRepository = $pageMaxRepository;
        $this->stripe_rec_repo = $stripe_rec_repo;
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->rec_service = $container->get("plg_stripe_rec.service.recurring_service");
    }

    /**
     * Recurring Order screen.
     *     
     * @Route("/%eccube_admin_route%/plugin/striperec/order", name="stripe_rec_admin_recorder")
     * @Route("/%eccube_admin_route%/plugin/striperec/order/page/{page_no}", requirements={"page_no" = "\d+"}, name="striperec_order_page")
     * @Template("@StripeRec/admin/rec_order.twig")
     */
    public function index(Request $request, $page_no = null, PaginatorInterface $paginator)
    {
        $builder = $this->formFactory
            ->createBuilder(StripeRecSearchType::class);

        $searchForm = $builder->getForm();

        $page_count = $this->session->get('plugin.striperec.order.pagecount',
            $this->eccubeConfig->get('eccube_default_page_count'));

        $page_count_param = (int) $request->get('page_count');
        $pageMaxis = $this->pageMaxRepository->findAll();

        if ($page_count_param) {
            foreach ($pageMaxis as $pageMax) {
                if ($page_count_param == $pageMax->getName()) {
                    $page_count = $pageMax->getName();
                    $this->session->set('plugin.striperec.order.pagecount', $page_count);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                /**
                 * 検索が実行された場合は, セッションに検索条件を保存する.
                 * ページ番号は最初のページ番号に初期化する.
                 */
                $page_no = 1;
                $searchData = $searchForm->getData();

                // 検索条件, ページ番号をセッションに保持.
                $this->session->set('plugin.striperec.order.search', FormUtil::getViewData($searchForm));
                $this->session->set('plugin.striperec.order.search.page_no', $page_no);
            } else {
                // 検索エラーの際は, 詳細検索枠を開いてエラー表示する.
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $page_count,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                /*
                 * ページ送りの場合または、他画面から戻ってきた場合は, セッションから検索条件を復旧する.
                 */
                if ($page_no) {
                    // ページ送りで遷移した場合.
                    $this->session->set('plugin.striperec.order.search.page_no', (int) $page_no);
                } else {
                    // 他画面から遷移した場合.
                    $page_no = $this->session->get('plugin.striperec.order.search.page_no', 1);
                }
                $viewData = $this->session->get('plugin.striperec.order.search', []);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
            } else {
                /**
                 * 初期表示の場合.
                 */
                $page_no = 1;
                $viewData = [];

                if ($statusId = (int) $request->get('order_status_id')) {
                    $viewData = ['status' => $statusId];
                }

                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

                // セッション中の検索条件, ページ番号を初期化.
                $this->session->set('plugin.striperec.order.search', $viewData);
                $this->session->set('plugin.striperec.order.search.page_no', $page_no);
            }
        }



        $qb = $this->stripe_rec_repo->getQueryBuilderBySearchDataForAdmin($searchData);

        // Exclude scheduled
        $qb->andWhere('ro.rec_status not like :not_schedule')
            ->setParameter('not_schedule', '%' . StripeRecOrder::REC_STATUS_SCHEDULED . '%');

        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $page_count,
            'has_errors' => false
        ];
    }



    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/plugin/striperec/order_detail/{id}", requirements={"id" = "\d+"}, name="stripe_rec_order_detail")
     * @Template("@StripeRec/admin/rec_detail.twig")
     */
    public function detail(Request $request, $id){
        $order_repo = $this->em->getRepository(Order::class);
                
        $rec_order = $this->stripe_rec_repo->find($id);
        
        if(empty($rec_order)){
            throw new NotFoundHttpException();
        }
        $order = $rec_order->getOrder();
        $PaidOrders = $rec_order->getPaidOrders();
        
        $builder = $this->formFactory->createBuilder(RecOrderType::class, $order);

        $original_order = clone $order;

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            
            $order->setDeliveryFeeTotal($original_order->getDeliveryFeeTotal());
            $order->setCharge($original_order->getCharge());
            $order->setUsePoint($original_order->getUsePoint());
            $order->setPayment($original_order->getPayment());
            $order->setDiscount($original_order->getDiscount());
            $this->entityManager->persist($order);
            $Shipping = $form['Shipping']->getData();

            $original_shipping = $original_order->getShippings()->first();
            if ($original_shipping) {
                $Shipping->setDelivery($original_shipping->getDelivery());
            }
            
            $this->entityManager->persist($Shipping);
            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(StripeRecEvent::ADMIN_RECORDER_INDEX_COMPLETE, new EventArgs([
                'Order' => $order,
                'rec_order' => $rec_order
            ], $request));
        }

        
        $rec_service = $this->container->get('plg_stripe_rec.service.recurring_service');
        $details = $rec_service->getPriceDetail($rec_order);
        extract($details);

        $order_ids = array_map(function($Order) { return $Order->getId(); }, $PaidOrders);
        \array_push($order_ids, $order->getId());
        $qb = $this->em->getRepository(MailHistory::class)->createQueryBuilder("mh")
            ->select("mh");
        $qb->where($qb->expr()->in("mh.Order", \implode(",", $order_ids)));

        $MailHistories = $qb->getQuery()->getResult();
        
        return [
            'form'              => $form->createView(),
            'Order'             => $order,
            'rec_order'         => $rec_order,
            'bundle_order_items'=> $bundle_order_items,
            'initial_amount'    => $initial_amount,
            'recurring_amount'  => $recurring_amount,
            'initial_discount'  => $initial_discount,
            'recurring_discount'=> $recurring_discount,
            'MailHistories'     => $MailHistories,
            'id'                => $id,
        ];
    }

    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/plugin/striperec/schedule", name="stripe_rec_admin_schedule")
     * @Route("/%eccube_admin_route%/plugin/striperec/schedule/page/{page_no}", requirements={"page_no" = "\d+"}, name="striperec_schedule_page")
     * @Template("@StripeRec/admin/rec_schedule.twig")
     */
    public function schedule(Request $request, $page_no = null, PaginatorInterface $paginator)
    {
        $builder = $this->formFactory
            ->createBuilder(StripeScheduleSearchType::class);

        $searchForm = $builder->getForm();

        $page_count = $this->session->get('plugin.striperec.schedule.pagecount',
            $this->eccubeConfig->get('eccube_default_page_count'));

        $page_count_param = (int) $request->get('page_count');
        $pageMaxis = $this->pageMaxRepository->findAll();

        if ($page_count_param) {
            foreach ($pageMaxis as $pageMax) {
                if ($page_count_param == $pageMax->getName()) {
                    $page_count = $pageMax->getName();
                    $this->session->set('plugin.striperec.schedule.pagecount', $page_count);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                /**
                 * 検索が実行された場合は, セッションに検索条件を保存する.
                 * ページ番号は最初のページ番号に初期化する.
                 */
                $page_no = 1;
                $searchData = $searchForm->getData();

                // 検索条件, ページ番号をセッションに保持.
                $this->session->set('plugin.striperec.order.search', FormUtil::getViewData($searchForm));
                $this->session->set('plugin.striperec.order.search.page_no', $page_no);
            } else {
                // 検索エラーの際は, 詳細検索枠を開いてエラー表示する.
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $page_count,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                /*
                 * ページ送りの場合または、他画面から戻ってきた場合は, セッションから検索条件を復旧する.
                 */
                if ($page_no) {
                    // ページ送りで遷移した場合.
                    $this->session->set('plugin.striperec.schedule.search.page_no', (int) $page_no);
                } else {
                    // 他画面から遷移した場合.
                    $page_no = $this->session->get('plugin.striperec.schedule.search.page_no', 1);
                }
                $viewData = $this->session->get('plugin.striperec.schedule.search', []);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
            } else {
                /**
                 * 初期表示の場合.
                 */
                $page_no = 1;
                $viewData = [];

                if ($statusId = (int) $request->get('order_status_id')) {
                    $viewData = ['status' => $statusId];
                }

                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

                // セッション中の検索条件, ページ番号を初期化.
                $this->session->set('plugin.striperec.order.search', $viewData);
                $this->session->set('plugin.striperec.order.search.page_no', $page_no);
            }
        }


        $qb = $this->stripe_rec_repo->getScheduleQueryBySearchDataForAdmin($searchData);


        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $page_count,
            'has_errors' => false
        ];
    }
    
    /**
     * Admin rec_order_history
     * @Route("/%eccube_admin_route%/plugin/striperec/order/{id}/stop", name="admin_striperec_order_stop")     
     */
    public function cancelSubscription(Request $request, $id=null){
        
        $rec_order = $this->stripe_rec_repo->findOneBy([
            "id" => $id,              
        ]);
        if(!empty($rec_order)){
            $res = $this->rec_service->cancelRecurring($rec_order);
            if(!empty($res)){
                $err_msg = $this->rec_service->getErrMsg();
                if($err_msg){
                    $this->addError($err_msg);
                }
            }

        }
        if($rec_order->isScheduled()){
            return $this->redirectToRoute("stripe_rec_admin_schedule");
        }else{
            return $this->redirectToRoute("stripe_rec_admin_recorder");
        }

    }
    /**
     * 受注CSVの出力.
     *
     * @Route("/%eccube_admin_route%/plugin/striperec/export", name="admin_striperec_order_export")
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function exportOrder(Request $request, CsvExportService $export_service, PointBundleService $bundle_service)
    {
        $filename = 'recorder_'.(new \DateTime())->format('YmdHis').'.csv';
        $response = $this->exportCsv($request, $filename, $export_service, $bundle_service);

        return $response;
    }

    /**
     * Create manual payment link
     * 
     * @Route("/%eccube_admin_route%/plugin/striperec/pay_link", name="admin_striperec_order_pay_link", methods="POST")
     */
    public function createPayLink(Request $request, StripeRecOrderRepository $recOrderRepository, RouterInterface $router)
    {
        $id = $request->request->get('id');
        if (!$id) {
            throw new NotFoundHttpException();
        }
        $recOrder = $recOrderRepository->find($id);
        
        $now = new \DateTime();
        $stamp = $now->getTimestamp();

        $recOrder->setManualLinkStamp($stamp);
        $this->entityManager->persist($recOrder);
        $this->entityManager->flush();

        $url = $router->generate('plugin_stripe_rec_extra_pay', ['id' => $id, 'stamp' => $stamp], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json(['success' => true, 'url' => $url]);
    }

    /**
     * 商品規格が登録されていなければ新規登録, 登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/plugin/striperec/csv_edit", name="stripe_rec_order_csv_edit")
     * @Route("/%eccube_admin_route%/plugin/striperec/csv_edit/{id}", name="stripe_rec_order_csv_item_edit")
     * @Template("@StripeRec/admin/csv_edit.twig")
     */
    public function csvEdit(Request $request, $id = null)
    {
        $rec_csv_repo = $this->entityManager->getRepository(RecCsv::class);
        $Csvs = $rec_csv_repo->getAll();

        $forms = [];
        foreach($Csvs as $Csv) {
            $forms[$Csv->getId()] = $this->formFactory->createBuilder(RecCsvType::class, $Csv)->getForm();
        }

        if ($request->getMethod() == "POST") {
            $form = $forms[$id];
            $form->handleRequest($request);
            $Csv = $rec_csv_repo->find($id);
            
            $new_label = $form['label']->getData();
            $Csv->setLabel($new_label);
            $this->entityManager->persist($Csv);
            $this->entityManager->flush();
            return $this->redirectToRoute('stripe_rec_order_csv_edit');
        }
        $form_views = [];
        foreach($forms as $k => $v) {
            $form_views[$k] = $v->createView();
        }
        return [
            'Csvs'  =>  $Csvs,
            'forms' =>  $form_views
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/plugin/striperec/csv_move_sort_no", name="stripe_rec_order_csv_edit_sort_no_move", methods={"POST"})
     */
    public function moveSortNo(Request $request, CacheUtil $cacheUtil)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new BadRequestHttpException();
        }
        $rec_csv_repo = $this->entityManager->getRepository(RecCsv::class);
        
        if ($this->isTokenValid()) {
            $sort_nos = $request->request->all();
            foreach($sort_nos as $csv_id => $sort_no) {
                $Csv = $rec_csv_repo->find($csv_id);
                $Csv->setSortNo($sort_no);
                $this->entityManager->persist($Csv);
            }
            $this->entityManager->flush();
            $cacheUtil->clearDoctrineCache();
            return $this->json(['success'   =>  true]);
        }
    }

    public function exportCsv($request, $file_name, $export_service, $bundle_service)
    {
        // タイムアウトを無効にする.
        set_time_limit(0);
        // sql loggerを無効にする.
        $em = $this->entityManager;
        $em->getConfiguration()->setSQLLogger(null);
        $response = new StreamedResponse();
        $export_service->setContainer($this->container);

        $response->setCallback(function () use ($request, $export_service, $bundle_service) {
        //     // ヘッダ行の出力.
            $export_service->exportHeader();

            // 受注データ検索用のクエリビルダを取得.
            $qb = $export_service
                ->getOrderQueryBuilder($request);

            // データ行の出力.
            $export_service->setExportQueryBuilder($qb);
            $export_service->exportData(function ($entity, $export_service) use ($request, $bundle_service) {
                
                $rec_order = $entity;
                if (!$rec_order) return null;
                $rec_order_items = $rec_order->getOrderItems();
                
                // $export_service->fputcsv($export_service->getData(null, $rec_order));
                $Order = $rec_order->getOrder();
                if (!$Order) return null;
                $OrderItems = $Order->getOrderItems();
                $prices = $bundle_service->getPriceSum($rec_order);
                extract($prices);
                foreach ($OrderItems as $OrderItem) {
                    $row_data = $export_service->getRecData($rec_order, $Order, $OrderItem, $initial_amount, $recurring_amount);
                    $export_service->fputcsv($row_data);
                }
            });
        });

        
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$file_name);
        $response->send();

        return $response;
    }
    
}