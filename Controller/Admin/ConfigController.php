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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Plugin\StripeRec\Form\Type\Admin\StripeRecConfigType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\StripeRec\Entity\LicenseKey;
use Plugin\StripeRec\Repository\LicenseRepository;
use Plugin\StripeRec\Form\Type\Admin\LicenseInputType;
use Eccube\Entity\ProductClass;

use Plugin\StripeRec\Entity\StripeRecOrder;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\Order;
use Eccube\Entity\BaseInfo;
use Eccube\Common\EccubeConfig;
use Plugin\StripeRec\Controller\RecurringHookController;
use Plugin\StripeRec\Service\ConfigService;

// BOC---for test
if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}

use Stripe\Customer as StripeLibCustomer;
use Stripe\PaymentMethod;
use Stripe\Subscription;
use Stripe\Stripe;
use Stripe\SubscriptionSchedule;
// EOC---for test

class ConfigController extends AbstractController
{
    /**
     * @var ContainerInterface
     */
    protected $container;
    protected $util_service;
    protected $licenseRepository;
    protected $config_service;

    /**
     * ConfigController constructor.
     *
     * @param StripeConfigRepository $stripeConfigRepository
     */
    public function __construct(
        ContainerInterface $container,
        LicenseRepository $licenseRepository)
    {
        $this->container = $container;
        $this->util_service = $this->container->get("plg_stripe_recurring.service.util");
        $this->licenseRepository = $licenseRepository;
        $this->config_service = $this->get("plg_stripe_rec.service.admin.plugin.config");
    }


    /**
     * @Route("/%eccube_admin_route%/stripe_rec/config", name="stripe_rec_admin_config")
     * @Template("@StripeRec/admin/stripe_config.twig")
     */
    public function index(Request $request)
    {
        // BOC test area------
        // EOC test area------
        $org_auth = $this->util_service->checkOrgLicense();
        if($org_auth === "unauthed"){
            return $this->redirectToRoute("stripe_rec_org_unauth") ;
        }
        
        $config_data = $this->config_service->getConfig();
        $form = $this->createForm(StripeRecConfigType::class, $config_data);
        $form->handleRequest($request);

        $form_license = $this->createForm(LicenseInputType::class);
        $form_license->handleRequest($request);

        if ($form_license->isSubmitted() && $form_license->isValid()) {
            $key = $form_license->getData();
            $key->setInstance(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"));
            if($this->util_service->requestLicense($key)){
                $this->saveKey($key);
                $this->config_service->enable_plugin();
                $this->addSuccess("stripe_recurring.admin.license.success", "admin");
                return [
                    'form'  => $form->createView(),
                    'form_license'  =>  $form_license->createView(),
                    'licensed'      =>  1
                ];
            }else{
                $this->config_service->disable_plugin();
                $this->addError('stripe_recurring.admin.license.failed', 'admin');
                return [
                    'form'  =>  $form->createView(),
                    'form_license'  =>  $form_license->createView(),
                    'licensed'      =>  1,
                ];
            }
        }


        if ($form->isSubmitted() && $form->isValid()) {
            if($org_auth === "test" || $this->util_service->checkLicense()){
                $this->config_service->enable_plugin();
                $new_data = $form->getData();

                $this->config_service->saveConfig($new_data);

                $this->addSuccess('stripe_payment_gateway.admin.save.success', 'admin');

                return $this->redirectToRoute('stripe_rec_admin_config');
            }else{
                $this->config_service->disable_plugin();
                $this->addError('stripe_recurring.admin.license.failed', 'admin');
                return [
                    'form'  =>  $form->createView(),
                    'form_license'  =>  $form_license->createView(),
                    'licensed'      =>  0,
                ];
            }
        }
        if($org_auth === "test" || $this->util_service->checkLicense()){

            $this->config_service->enable_plugin();

            return [
                'form_license' => $form_license->createView(),
                'form' => $form->createView(),
                'licensed' => 1,
            ];
        }else{
            
            $this->config_service->disable_plugin();
            return [
                'form' => $form->createView(),
                'form_license' => $form_license->createView(),
                'licensed' => 0,
            ];
        }
    }

    public function saveKey($key){
        $key_lic = $this->entityManager->getRepository(LicenseKey::class)->get();
        if(empty($key_lic)){
            $key_lic = new LicenseKey;
        }
        $key_lic->setEmail($key->getEmail());
        $key_lic->setLicenseKey($key->getLicenseKey());
        $key_lic->setKeyType(2);
        $key_lic->setInstance($key->getInstance());
        $this->entityManager->persist($key_lic);
        $this->entityManager->flush();
    }

    /**
     * @Route("/%eccube_admin_route%/stripe_rec/org_error", name="stripe_rec_org_unauth")
     * @Template("@StripeRec/admin/stripe_org_unauth.twig")
     */
    public function org_unauth_error(Request $request)
    {
        return[];
    }
    
}