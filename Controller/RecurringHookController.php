<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Controller;
if( \file_exists(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php')) {
    include_once(dirname(__FILE__).'/../../StripePaymentGateway/vendor/stripe/stripe-php/init.php');
}

use Stripe\Webhook;
use Eccube\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Eccube\Entity\Order;
use Eccube\Service\MailService;
use Eccube\Common\EccubeConfig;

class RecurringHookController extends AbstractController{

    protected $container;
    /**
     * エンティティーマネージャー
     */
    private $em;

    private $rec_order_repo;

    private $config_service;
    private $mail_service;
    private $rec_service;
    private $stripe_service;
    private $invoice_stamp_dir;
    protected $eccubeConfig;

    const LOG_IF = "rmaj111---";


    public function __construct(ContainerInterface $container, MailService $mail_service, EccubeConfig $eccubeConfig){
        $this->container = $container;       
        $this->em = $container->get('doctrine.orm.entity_manager'); 
        $this->rec_order_repo = $this->em->getRepository(StripeRecOrder::class);
        $this->config_service = $container->get("plg_stripe_rec.service.admin.plugin.config");
        $this->mail_service = $mail_service;
        $this->rec_service = $container->get("plg_stripe_rec.service.recurring_service");
        $this->stripe_service = $container->get("plg_stripe_rec.service.stripe_service");

        $this->eccubeConfig = $eccubeConfig;
        $this->invoice_stamp_dir = $this->eccubeConfig->get('kernel.project_dir') . "/var/invoice_stamp";
        if ( !\is_dir($this->invoice_stamp_dir) ) {
          \mkdir($this->invoice_stamp_dir);
        }
    }
    /**
     * @Route("/plugin/StripeRec/webhook", name="plugin_stripe_rec_webhook")
     */
    public function webhook(Request $request){

        $signature = $this->config_service->getSignature();
        if($signature){
            try{
                log_info("=============[webhook sign started] 0============\n");
                log_info("current_sign : $signature");
                $event = Webhook::constructEvent(
                    $request->getContent(), 
                    $request->headers->get('stripe-signature'),
                    $signature, 800
                );
                
                $type = $event['type'];
                $object = $event['data']['object'];
                log_info("webhook_type : $type");
            }catch(Exception $e){
                
                log_error("=============[webhook sign error]============\n" );
                return $this->json(['status' => 'failed']);
            }
        }else{
            log_info("=============[webhook processing without sign]============");
            $data = $request->query->all();
            $type = $data['type'];
            $object = $data['data']['object'];
        }
        log_info("==============[webhook object] $type ======");
        
        switch ($type) {
            case 'invoice.payment_succeeded':
              // log_info('🔔 ' . $type . ' Webhook received! ' . $object);            
              // if ($this->paidDebounce($object)) {
              //   $this->rec_service->invoicePaid($object);
              // }
              break;
            case 'invoice.paid':
              log_info('🔔 ' . $type . ' Webhook received! ' . $object);            
              if ($this->paidDebounce($object)) {
                $this->rec_service->invoicePaid($object);
              }
              break;
            case 'invoice.payment_failed':
              // If the payment fails or the customer does not have a valid payment method,
              // an invoice.payment_failed event is sent, the subscription becomes past_due.
              // Use this webhook to notify your user that their payment has
              // failed and to retrieve new card details.
              log_info('🔔 ' . $type . ' Webhook received! ' . $object);
              $this->rec_service->invoiceFailed($object);
              break;
            case 'invoice.upcoming':
                log_info('🔔 ' . $type . ' Webhook received! ' . $object);
                $this->rec_service->invoiceUpcoming($object);
                break;
            case 'invoice.finalized':
              // If you want to manually send out invoices to your customers
              // or store them locally to reference to avoid hitting Stripe rate limits.
                // log_info('🔔 ' . $type . ' Webhook received! ' . $object);
                // if ($this->paidDebounce($object)) {
                //   $this->rec_service->invoicePaid($object);
                // }
              break;
            case 'customer.subscription.deleted':
              // handle subscription cancelled automatically based
              // upon your subscription settings. Or if the user
              // cancels it.
              log_info('🔔 ' . $type . ' Webhook received! ' . $object);
              $this->rec_service->recurringCanceled($object);
              break;
            case 'customer.subscription.trial_will_end':
              // Send notification to your user that the trial will end
              log_info('🔔 ' . $type . ' Webhook received! ' . $object);
              break;
            case 'customer.subscription.updated':
                log_info('🔔 ' . $type . ' Webhook received! ' . $object);      
                $this->rec_service->subscriptionUpdated($object);
              break;
            // ... handle other event types
            // case 'checkout.session.completed':
            //     log_info('🔔 ' . $type . ' Webhook received! ' . $object);    
            //     $this->rec_service->completeOrder($object);            
            //     break;
            case 'customer.subscription.created':
                log_info('🔔 ' . $type . ' Webhook received! ' . $object);
                $this->rec_service->subscriptionCreated($object);
            break;
            case 'subscription_schedule.canceled':
                log_info('🔔 ' . $type . ' Webhook received! ' . $object);
                $this->rec_service->subscriptionScheduleCanceled($object);
            break;
            default:
              // Unhandled event type
          }
        
        return $this->json(['status' => 'success']);
    }
    
    private function paidDebounce($object) 
    {
      $file = $this->invoice_stamp_dir . "/" . $object->id;
      log_info(self::LOG_IF . $file);
      $now = new \DateTime();
      $now = $now->getTimestamp();
      if (!\file_exists($file)) {
        \file_put_contents($file, $now);
        return true;
      }

      $old = \file_get_contents($file);
      $res = ($now - $old > 1000 * 30 );
      \file_put_contents($file, $now);
      return $res;
    }
}