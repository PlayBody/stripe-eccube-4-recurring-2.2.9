<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) Subspire. All Rights Reserved.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\StripeRec\Controller\Mypage;


use Symfony\Component\DependencyInjection\ContainerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Knp\Component\Pager\PaginatorInterface;
use Plugin\StripeRec\Repository\StripeRecOrderRepository;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripePaymentGateway\Entity\StripeConfig;
use Plugin\StripePaymentGateway\Entity\StripeCustomer;

class MypageController extends AbstractController
{
    protected $container;
    protected $rec_repo;
    protected $em;
    protected $rec_service;

    public function __construct(
        ContainerInterface $container,
        StripeRecOrderRepository $rec_repo
    ){
        $this->container = $container;
        $this->rec_repo = $rec_repo;
        $this->em = $container->get('doctrine.orm.entity_manager'); 
        $this->rec_service = $this->container->get("plg_stripe_rec.service.recurring_service");
    }

    /**
     * Mypage rec_order_history
     *
     * @Route("/mypage/stripe_rec_history", name="mypage_stripe_rec")
     * @Template("StripeRec/Resource/template/default/Mypage/recurring_tab.twig")
     * @param Request $request
     * @param PaginatorInterface $paginator
     */
    public function index(Request $request, PaginatorInterface $paginator){
        if(!$this->isGranted('ROLE_USER')){
            return $this->redirectToRoute('mypage_login');
        }
        $Customer = $this->getUser();

        if(!$Customer){
            return $this->redirectToRoute('mypage_login');
        }
        
        $qb = $this->rec_repo->getQueryBuilderByCustomer($Customer);
        // exclude scheduled
        $qb->andWhere('ro.rec_status not like :not_schedule')
            ->setParameter('not_schedule', '%' . StripeRecOrder::REC_STATUS_SCHEDULED . '%');

        $pagination = $paginator->paginate(
            $qb,
            $request->get('pageno', 1),
            $this->eccubeConfig['eccube_search_pmax']
        );
        return [
            'pagination'    =>  $pagination
        ];        
    }

    /**
     * Mypage rec_schedule_history
     *
     * @Route("/mypage/stripe_schedule_history", name="mypage_stripe_schedule")
     * @Template("StripeRec/Resource/template/default/Mypage/schedule_tab.twig")
     * @param Request $request
     * @param PaginatorInterface $paginator
     */
    public function schedule_history(Request $request, PaginatorInterface $paginator){
        
        if(!$this->isGranted('ROLE_USER')){
            return $this->redirectToRoute('mypage_login');
        }
        $Customer = $this->getUser();

        if(!$Customer){
            return $this->redirectToRoute('mypage_login');
        }
        
        $qb = $this->rec_repo->getScheduleQueryByCustomer($Customer);
        // exclude scheduled
        

        $pagination = $paginator->paginate(
            $qb,
            $request->get('pageno', 1),
            $this->eccubeConfig['eccube_search_pmax']
        );
        return [
            'pagination'    =>  $pagination
        ];
    }

    /**
     * Mypage rec_order_history
     *
     * @Route("/mypage/rec_history/{id}/stop", name="mypage_stripe_rec_cancel", methods="POST")
     * @Template("StripeRec/Resource/template/default/Mypage/recurring_tab.twig")
     */
    public function cancelSubscription(Request $request, $id=null){
        if(!$this->isGranted('ROLE_USER')){
            return $this->redirectToRoute('mypage_login');
        }
        $Customer = $this->getUser();

        if(!$Customer){
            return $this->redirectToRoute('mypage_login');
        }


        $rec_order = $this->rec_repo->findOneBy([
            "id" => $id,              
            "Customer"  =>  $Customer
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
        if (!empty($request->request->get('cancel_reason'))) {
            $rec_order->setCancelReason($request->request->get('cancel_reason'));
            $this->entityManager->persist($rec_order);
            $this->entityManager->flush();
        }
        if($rec_order->isScheduled()){
            return $this->redirectToRoute('mypage_stripe_schedule');
        }
        return $this->redirectToRoute("mypage_stripe_rec");

    }
    /**
     * Mypage rec_order_history
     * 
     * @Route("/mypage/rec_history/{id}/cancel_confirm", name="mypage_stripe_cancel_confirm")
     * @Template("StripeRec/Resource/template/default/Mypage/recurring_cancel_confirm.twig")
     */
    public function cancelConfirm(Request $request, $id=null){
        if(!$this->isGranted('ROLE_USER')){
            return $this->redirectToRoute('mypage_login');
        }
        $Customer = $this->getUser();

        if(!$Customer){
            return $this->redirectToRoute('mypage_login');
        }

        $rec_order = $this->rec_repo->findOneBy([
            "id" => $id,              
            "Customer"  =>  $Customer
        ]);
        if (empty($rec_order)) {
            return $this->redirectToRoute("mypage_login");
        }
        if($rec_order->getRecStatus() !== StripeRecOrder::REC_STATUS_ACTIVE){

            if(!$rec_order->isScheduled()){
                return $this->redirectToRoute('mypage_stripe_rec');
            }
            $pb_service = $this->container->get('plg_stripe_rec.service.pointbundle_service');
            $state = $pb_service->getScheduleState($rec_order);
            if($state[2] !== StripeRecOrder::SCHEDULE_NOT_STARTED){
                return $this->redirectToRoute('mypage_stripe_rec_cancel');
            }
        }
        
        return [
            'rec_order' => $rec_order,            
        ];
    }

    /**
     * Update Payment Method
     * @Route("/plugin/striperec/{id}/update_method", name="plugin_striperec_update_method")
     * @Template("@StripeRec/default/Shopping/collect_method.twig")
     */
    public function updatePaymentMethod(Request $request, $id)
    {
        if(!$this->isGranted('ROLE_USER')){
            return $this->redirectToRoute('mypage_login');
        }

        $Customer = $this->getUser();

        if(!$Customer){
            return $this->redirectToRoute('mypage_login');
        }

        $rec_order = $this->rec_repo->findOneBy([
            "id" => $id,              
            "Customer"  =>  $Customer
        ]);
        if (empty($rec_order)) {
            throw new NotFoundHttpException();
        }
        $Order = $rec_order->getOrder();
        $stripeConfig = $this->entityManager->getRepository(StripeConfig::class)->getConfigByOrder($Order);
        $checkout_ga_enable = $stripeConfig->checkout_ga_enable;
// dump($request->request->get('payment_method')); die();
        if ($request->getMethod() === "POST" && $request->request->get('payment_method')) {
            $method_id = $request->request->get('payment_method');
            $stripe_service = $this->container->get("plg_stripe_rec.service.stripe_service");

            $StripeCustomer = $this->entityManager->getRepository(StripeCustomer::class)->findOneBy(array('Customer'=>$Customer));

            if (empty($StripeCustomer)) {
                return $this->json([
                    'success'   =>  false,
                    'error'     =>  "customer not registered"
                ]);
            }
            $customer_id = $StripeCustomer->getStripeCustomerId();
            $res = $stripe_service->updatePaymentMethod($customer_id, $method_id);

            if (!$res) {
                return $this->json([
                    'success'   =>  false,
                    'error'     =>  $stripe_service->getErrMsg()
                ]);
            } else {
                if ($rec_order->getRecStatus() != StripeRecOrder::REC_STATUS_CANCELED &&
                    $rec_order->getRecStatus() != StripeRecOrder::SCHEDULE_CANCELED &&
                    $rec_order->getRecStatus() != StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED && 
                    $rec_order->getPaidStatus() == StripeRecOrder::STATUS_PAY_FAILED) {
                        $failed_invoice = $rec_order->getFailedInvoice();
                        if ($failed_invoice) {
                            $res = $stripe_service->payInvoice($failed_invoice);
                            if (!$res) {
                                return $this->json([
                                    'success'   =>  false,
                                    'error'     =>  $stripe_service->getErrMsg()
                                ]);
                            }
                        }
                    }
            }
            
            return $this->json(['success'   =>  true]);
        }

        return compact('stripeConfig', 'rec_order');
    }
}