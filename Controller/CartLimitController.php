<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) Subspire. All Rights Reserved.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\StripeRec\Controller;

use Plugin\StripeRec\Service\ConfigService;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\Product;
use Eccube\Entity\ProductClass;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\AddCartType;
use Eccube\Form\Type\Master\ProductListMaxType;
use Eccube\Form\Type\Master\ProductListOrderByType;
use Eccube\Form\Type\SearchProductType;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\CustomerFavoriteProductRepository;
use Eccube\Repository\Master\ProductListMaxRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Service\CartService;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Knp\Component\Pager\Paginator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Eccube\Controller\CartController;

class CartLimitController extends CartController
{
    /**
     * @var PurchaseFlow
     */
    protected $purchaseFlow;

    /**
     * @var CustomerFavoriteProductRepository
     */
    protected $customerFavoriteProductRepository;

    /**
     * @var CartService
     */
    protected $cartService;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var BaseInfo
     */
    protected $baseInfo;

    /**
     * @var AuthenticationUtils
     */
    protected $helper;

    /**
     * @var ProductListMaxRepository
     */
    protected $productListMaxRepository;
    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    private $title = '';

    /**
     * ProductController constructor.
     *
     * @param PurchaseFlow $cartPurchaseFlow
     * @param CustomerFavoriteProductRepository $customerFavoriteProductRepository
     * @param CartService $cartService
     * @param ProductRepository $productRepository
     * @param BaseInfoRepository $baseInfoRepository
     * @param AuthenticationUtils $helper
     * @param ProductListMaxRepository $productListMaxRepository
     */
    public function __construct(
        PurchaseFlow $cartPurchaseFlow,
        CustomerFavoriteProductRepository $customerFavoriteProductRepository,
        CartService $cartService,
        ProductRepository $productRepository,
        BaseInfoRepository $baseInfoRepository,
        AuthenticationUtils $helper,
        ProductListMaxRepository $productListMaxRepository,
        ProductClassRepository $productClassRepository
    ) {
        $this->purchaseFlow = $cartPurchaseFlow;
        $this->customerFavoriteProductRepository = $customerFavoriteProductRepository;
        $this->cartService = $cartService;
        $this->productRepository = $productRepository;
        $this->baseInfo = $baseInfoRepository->get();
        $this->helper = $helper;
        $this->productListMaxRepository = $productListMaxRepository;
        $this->productClassRepository = $productClassRepository;
    }

    
    /**
     * ??????????????????.
     *
     * @Route("/products/add_cart/{id}", name="product_add_cart", methods={"POST"}, requirements={"id" = "\d+"})
     */
    public function addCart(Request $request, Product $Product)
    {
        // ?????????????????????????????????
        $errorMessages = [];
        if (!$this->checkVisibility($Product)) {
            throw new NotFoundHttpException();
        }

        $builder = $this->formFactory->createNamedBuilder(
            '',
            AddCartType::class,
            null,
            [
                'product' => $Product,
                'id_add_product_id' => false,
            ]
        );

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Product' => $Product,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::FRONT_PRODUCT_CART_ADD_INITIALIZE, $event);

        /* @var $form \Symfony\Component\Form\FormInterface */
        $form = $builder->getForm();
        $form->handleRequest($request);

        if (!$form->isValid()) {
            throw new NotFoundHttpException();
        }

        $addCartData = $form->getData();

        log_info(
            '???????????????????????????',
            [
                'product_id' => $Product->getId(),
                'product_class_id' => $addCartData['product_class_id'],
                'quantity' => $addCartData['quantity'],
            ]
        );

        //-----added by devcrazy------
        $valid = true;
        $already_in = false;

        // check multi product option
        $config_service = $this->get("plg_stripe_rec.service.admin.plugin.config");
        $is_multi_product = !$config_service->get(ConfigService::MULTI_PRODUCT);

        if (!$is_multi_product) {
            $prod_class = $this->entityManager->getRepository(ProductClass::class)->findOneBy(['id' => $addCartData['product_class_id']]);
    
            if ($prod_class && $prod_class->isRegistered()){
                $addCartData['quantity'] = 1;
            }
            $res = $this->checkAddCartValidity($addCartData['product_class_id']);            
            if($res){
                $errorMessages[] = $res;
                $valid = false;
            }
        }

        if($valid === true && $already_in === false){
            // $addCartData['quantity'] = 1;
        //================================
            
            // ??????????????????
            $this->cartService->addProduct($addCartData['product_class_id'], $addCartData['quantity']);

            // ??????????????????
            $Carts = $this->cartService->getCarts();
            foreach ($Carts as $Cart) {
                $result = $this->purchaseFlow->validate($Cart, new PurchaseContext($Cart, $this->getUser()));
                // ???????????????????????????????????????????????????????????????????????????.
                if ($result->hasError()) {
                    $this->cartService->removeProduct($addCartData['product_class_id']);
                    foreach ($result->getErrors() as $error) {
                        $errorMessages[] = $error->getMessage();
                    }
                }else{
                    if (!$is_multi_product) {
			            $err_msg = $this->checkCart($Cart);
                        if($err_msg){
                            //$this->cartService->removeProduct($addCartData['product_class_id']);
                            $errorMessages[] = $err_msg;
                        }
                    }
                }
                foreach ($result->getWarning() as $warning) {
                    $errorMessages[] = $warning->getMessage();
                }                
            }

            if(empty($err_msg)){
                $this->cartService->save();
            }
            log_info(
                '???????????????????????????',
                [
                    'product_id' => $Product->getId(),
                    'product_class_id' => $addCartData['product_class_id'],
                    'quantity' => $addCartData['quantity'],
                ]
            );

            $event = new EventArgs(
                [
                    'form' => $form,
                    'Product' => $Product,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::FRONT_PRODUCT_CART_ADD_COMPLETE, $event);
        

            if ($event->getResponse() !== null) {
                return $event->getResponse();
            }
        }

        if ($request->isXmlHttpRequest()) {
            // ajax??????????????????????????????????????????json??????????????????

            // ?????????
            $done = null;
            $messages = [];

            if (empty($errorMessages)) {
                // ???????????????????????????????????????
                $done = true;
                array_push($messages, trans('front.product.add_cart_complete'));
            } else {
                // ????????????????????????????????????
                $done = false;
                $messages = $errorMessages;
            }

            return $this->json(['done' => $done, 'messages' => $messages]);
        } else {
            // ajax???????????????????????????????????????????????????????????????????????????
            foreach ($errorMessages as $errorMessage) {
                $this->addRequestError($errorMessage);
            }

            return $this->redirectToRoute('cart');
        }
    }
    /**
     * ????????????????????????/??????/???????????????.
     *
     * - ??????
     *      - ??????????????????1?????????
     * - ??????
     *      - ??????????????????1?????????
     *      - ?????????0??????????????????????????????????????????
     * - ??????
     *      - ?????????????????????
     *
     * @Route(
     *     path="/cart/{operation}/{productClassId}",
     *     name="cart_handle_item",
     *     methods={"PUT"},
     *     requirements={
     *          "operation": "up|down|remove",
     *          "productClassId": "\d+"
     *     }
     * )
     */
    public function handleCartItem($operation, $productClassId)
    {
        log_info('???????????????????????????', ['operation' => $operation, 'product_class_id' => $productClassId]);

        $this->isTokenValid();

        /** @var ProductClass $ProductClass */
        $ProductClass = $this->productClassRepository->find($productClassId);

        if (is_null($ProductClass)) {
            log_info('???????????????????????????????????????????????????redirect', ['operation' => $operation, 'product_class_id' => $productClassId]);

            return $this->redirectToRoute('cart');
        }

        $config_service = $this->get('plg_stripe_rec.service.admin.plugin.config');
        $is_multi_product = !$config_service->get(ConfigService::MULTI_PRODUCT);

        // ????????????????????????
        switch ($operation) {
            case 'up':
                if($is_multi_product || !$ProductClass->isRegistered()){
                    $this->cartService->addProduct($ProductClass, 1);
                }else{
                    return $this->redirectToRoute('cart');
                }

                break;
            case 'down':
                if($is_multi_product || !$ProductClass->isRegistered()){
                    $this->cartService->addProduct($ProductClass, -1);
                }else{
                    return $this->redirectToRoute('cart');
                }
                break;
            case 'remove':
                $this->cartService->removeProduct($ProductClass);
                break;
        }

        // ???????????????????????????????????????????????????
        $Carts = $this->cartService->getCarts();
        $this->execPurchaseFlow($Carts);

        log_info('???????????????????????????', ['operation' => $operation, 'product_class_id' => $productClassId]);

        return $this->redirectToRoute('cart');
    }

    /**
     * ??????????????????????????????
     *
     * @param  null|array $searchData
     *
     * @return str
     */
    protected function getPageTitle($searchData)
    {
        if (isset($searchData['name']) && !empty($searchData['name'])) {
            return trans('front.product.search_result');
        } elseif (isset($searchData['category_id']) && $searchData['category_id']) {
            return $searchData['category_id']->getName();
        } else {
            return trans('front.product.all_products');
        }
    }

    /**
     * ??????????????????????????????????????????
     *
     * @param Product $Product
     *
     * @return boolean ????????????????????????true
     */
    protected function checkVisibility(Product $Product)
    {
        $is_admin = $this->session->has('_security_admin');

        // ??????????????????????????????????????????????????????????????????????????????????????????.
        if (!$is_admin) {
            // ???????????????????????????????????????????????????????????????.
            // if ($this->BaseInfo->isOptionNostockHidden()) {
            //     if (!$Product->getStockFind()) {
            //         return false;
            //     }
            // }
            // ??????????????????????????????????????????????????????.
            if ($Product->getStatus()->getId() !== ProductStatus::DISPLAY_SHOW) {
                return false;
            }
        }

        return true;
    }

    protected function checkAddCartValidity($prod_class_id){
        
        $prod_class = $this->entityManager->getRepository(ProductClass::class)->findOneBy(['id' => $prod_class_id]);
        if($prod_class && !$prod_class->isRegistered()){
            // return trans("stripe_recurring.cart.add_item.rec_unregistered_item");            
            return null;
        }
        $carts = $this->cartService->getCarts();
        if(!empty($carts)){            
            foreach($carts as $cart){
                $items = $cart->getCartItems();                
                if(!empty($items)){
                    $item = $items[0];
                    $item_class = $item->getProductClass();
                    if(!$item_class->isRegistered()){
                        continue;
                    }
                    // if(($prod_class->getId() == $item_class->getId())){
                        return trans("stripe_recurring.cart.add_item.item_already");
                    // }
                }                
            }
        }
        return null;
    }
    protected function checkCart($cart){
        $items = $cart->getCartItems();                
        if(!empty($items)){
            $item = $items[0];
            $item_class = $item->getProductClass();

            // if( ($prod_class->getId() != $item_class->getId()) && ($prod_class->getInterval() != $item_class->getInterval())){
            //     return trans("stripe_recurring.cart.add_item.rec_interval_unmatched");
            // }
            // if(($prod_class->isRegistered() && $prod_class->getId() == $item_class->getId())){
            //     return trans("stripe_recurring.cart.add_item.item_already");
            // }
            $is_registered = $item_class->isRegistered();
            $interval = $item_class->getInterval();

            foreach($items as $item){
                $it_item_class = $item->getProductClass();
                if($it_item_class){
                    if ($it_item_class->isRegistered() != $is_registered || $it_item_class->getInterval() != $interval){
                        return trans("stripe_recurring.cart.add_item.rec_interval_unmatched");                        
                    }
                }                
            }
        } 
        return null;             
    }
}
