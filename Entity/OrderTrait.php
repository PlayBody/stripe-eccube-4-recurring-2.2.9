<?php
/*
* Plugin Name : StripePaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/


namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;
use Plugin\StripeRec\Entity\StripeRecOrder;
/**
 * @EntityExtension("Eccube\Entity\Order")
 */
trait OrderTrait
{
    /**
     * @var StripeRecOrder
     *     
     * @ORM\ManyToOne(targetEntity="Plugin\StripeRec\Entity\StripeRecOrder", inversedBy="Orders")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="rec_order_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     * })
     */
    private $recOrder;

    /**
     * @var string|null
     *
     * @ORM\Column(name="invoice_id", type="string", length=255, nullable=true)
     */
    private $invoice_id;

    //for input after days (virtual)
    private $after_days;

    /**
     * @var boolean
     *
     * @ORM\Column(name="is_initial_rec", type="smallint", options={"default" : 0}, nullable=true)
     */
    private $is_initial_rec;

    /**
     * @var string
     * @ORM\Column(name="manual_link_stamp", type="string", nullable=true)
     */
    private $manual_link_stamp;


    public function getInitialSubTotal(){       
        $order_items = $this->getProductOrderItems();
        $initial_total = 0;        
        foreach($order_items as $order_item){
            $pc = $order_item->getProductClass();            
            if($pc->isInitialPriced()){
                $initial_total += $pc->getInitialPriceIncTax() * $order_item->getQuantity();
            }else{
                $initial_total += $pc->getPrice02IncTax() * $order_item->getQuantity();
            }
        }
        return $initial_total;
    }
    public function getManualLinkStamp()
    {
        return $this->manual_link_stamp;
    }
    public function setManualLinkStamp($manual_link_stamp)
    {
        $this->manual_link_stamp = $manual_link_stamp;
        return $this;
    }
    public function isInitialRec() 
    {
        return ($this->recOrder && $this->is_initial_rec > 0);
    }

    public function setIsInitialRec($is_initial_rec)
    {
        $this->is_initial_rec = (! \is_null($is_initial_rec) && $is_initial_rec > 0 ) ? 1 : 0;
        return $this;
    }

    public function getInvoiceId() 
    {
        return $this->invoice_id;
    }
    public function setInvoiceId($invoice_id)
    {
        $this->invoice_id = $invoice_id;
        return $this;
    }

    public function getAfterDays(){
        // if(empty($this->after_days)){
        //     $interval = new \DateInterval('P2D');
        //     $after_days = new \DateTime();
        //     $after_days->add($interval);
        //     return $after_days;
            
        // }else{
            return $this->after_days;
        // }
    }


    public function setAfterDays($after_days){
        $this->after_days = $after_days;
        return $this;
    }

    public function getRecOrder(){
        return $this->recOrder;
    }
    public function setRecOrder($rec_order){
        $this->recOrder = $rec_order;
        return $this;
    }
    public function isRecurring(){
        return !empty($this->recOrder);
    }
    public function hasStripePriceId(){
        $order_items = $this->getProductOrderItems();
        $product_class = $order_items[0]->getProductClass();
        return $product_class->isRegistered();
    }
    public function getFullKana(){
        return $this->kana01 . $this->kana02;
    }
    public function isInitialPriced(){
        $order_items = $this->getProductOrderItems();
        foreach($order_items as $order_item){
            if($order_item->getProductClass()->isInitialPriced()){
                return true;
            }
        }
        return false;
    }

    public function copy($Order) 
    {
        $this->order_no = $Order->getOrderNo();
        $this->name01 = $Order->getName01();
        $this->name02 = $Order->getName02();
        $this->kana01 = $Order->getKana01();
        $this->kana02 = $Order->getKana02();
        $this->company_name = $Order->getCompanyName();
        $this->email = $Order->getEmail();
        $this->phone_number = $Order->getPhoneNumber();
        $this->postal_code = $Order->getPostalCode();
        $this->addr01 = $Order->getAddr01();
        $this->addr02 = $Order->getAddr02();
        $this->birth = $Order->getBirth();
        $this->subtotal = $Order->getSubtotal();
        $this->discount = $Order->getDiscount();
        $this->delivery_fee_total = $Order->getDeliveryFeeTotal();
        $this->charge = $Order->getCharge();
        $this->tax = $Order->getTax();
        $this->total = $Order->getTotal();
        $this->payment_total = $Order->getPaymentTotal();
        $this->payment_method = $Order->getPaymentMethod();
        $this->note = $Order->getNote();
        $this->create_date = new \DateTime();
        $this->update_date = new \DateTime();
        $this->order_date = $Order->getOrderDate();
        $this->currency_code = $Order->getCurrencyCode();
        $this->Customer = $Order->getCustomer();
        $this->Country = $Order->getCountry();
        $this->Pref = $Order->getPref();
        $this->Sex = $Order->getSex();
        $this->Job = $Order->getJob();
        $this->Payment = $Order->getPayment();
        $this->DeviceType = $Order->getDeviceType();

        // OrderStatus
        // complete_message
        // complete_mail_message
        // payment_date
        // message
    }
}
