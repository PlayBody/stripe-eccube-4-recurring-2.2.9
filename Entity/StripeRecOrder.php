<?php

/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Entity;

use Doctrine\ORM\Mapping as ORM;
use Plugin\StripeRec\Entity\StripeRecOrderItem;
use Eccube\Entity\Customer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Eccube\Entity\Order;

/**
 * StripeRecOrder
 * 
 * @ORM\Table(name="plg_stripe_rec_order")
 * @ORM\Entity(repositoryClass="Plugin\StripeRec\Repository\StripeRecOrderRepository")
 */
class StripeRecOrder{
   
    const STATUS_PAY_UPCOMING = "upcoming";
    const STATUS_PAID = "paid";
    const STATUS_PAY_FAILED = "pay_failed";
    const STATUS_PAY_UNDEFINED = "undefined";

    const REC_STATUS_ACTIVE = "active";
    const REC_STATUS_CANCELED = "canceled";
    const REC_STATUS_PENDING = "pending";
    const REC_STATUS_SCHEDULED = "scheduled";    
    const REC_STATUS_SCHEDULED_CANCELED = "scheduled_canceled";

    const SCHEDULE_NOT_STARTED = "not_started";
    const SCHEDULE_STARTED = "started";
    const SCHEDULE_CANCELED = "canceled";

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", length=11, options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var Order
     *     
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Order")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="order_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     * })
     */
    private $Order;

    /**
     * @var \Doctrine\Common\Collections\Collection
     * 
     * @ORM\OneToMany(targetEntity="Eccube\Entity\Order", mappedBy="recOrder")
     */
    private $Orders;

    /**
     * @var string
     * 
     * @ORM\Column(name="subscription_id", type="text", nullable=true)
     */
    private $subscription_id;

    /**
     * @var string
     * 
     * @ORM\Column(name="create_date", type="datetimetz", nullable=true)
     */
    private $create_date;

    /**
     * @var string
     * @ORM\Column(name="current_period_start", type="datetimetz", nullable=true)
     */
    private $current_period_start;

    /**
     * @var string
     * 
     * @ORM\Column(name="current_period_end", type="datetimetz", nullable=true)
     */
    private $current_period_end;

    /**
     * @var string
     * 
     * @ORM\Column(name="customer_id", type="text", nullable=true)
     */
    private $stripe_customer_id;


    /**
     * @var Customer
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Customer")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="shop_customer_id", referencedColumnName="id", nullable=true)
     * })
     */
    private $Customer;

    

    /**
     * @var string
     * 
     * @ORM\Column(name="rec_status", type="text", nullable=true)
     */
    private $rec_status;

    

    /**
     * @var string
     * @ORM\Column(name="paid_status", type = "text", nullable=true)
     */
    private $paid_status;

    /**
     * @var string
     * 
     * @ORM\Column(name="last_payment_date", type="datetimetz", nullable=true)
     */
    private $last_payment_date;

    /**
     * @var \Doctrine\Common\Collections\Collection|StripeRecOrderItem[]
     *
     * @ORM\OneToMany(targetEntity="Plugin\StripeRec\Entity\StripeRecOrderItem", mappedBy="recOrder", cascade={"persist","remove"})
     */
    private $OrderItems;

    /**
     * @var string
     * @ORM\Column(name="stripe_customer_email", type = "text", nullable=true)
     */
    private $stripe_customer_email;

    
    /**
     * @var string
     * @ORM\Column(name="rec_interval", type="text", nullable=true)
     */
    private $interval;

    /**
     * @var string
     * @ORM\Column(name="last_charge_id", type = "text", nullable=true)
     */
    private $last_charge_id;

    private $invoice_items = [];
    private $invoice_data = "";

    // ========for scheduled=======
    /**
     * @var string
     * 
     * @ORM\Column(name="start_date", type="datetimetz", nullable=true)
     */
    private $start_date;

    /**
     * @var string
     * @ORM\Column(name="bundling", type = "text", nullable=true)
     */
    private $bundling;
    
    /**
     * @var string
     * @ORM\Column(name="schedule_id", type = "text", nullable=true)
     */
    private $schedule_id;

    /**
     * @var string
     * @ORM\Column(name="coupon_id", type="string", nullable=true)
     */
    private $coupon_id;

    
    /**
     * @var string
     * @ORM\Column(name="coupon_discount_str", type="string", nullable=true)
     */
    private $coupon_discount_str;

    /**
     * @var string
     * @ORM\Column(name="coupon_name", type="string", nullable=true)
     */
    private $coupon_name;

    /**
     * @var string
     * @ORM\Column(name="manual_link_stamp", type="string", nullable=true)
     */
    private $manual_link_stamp;

    /**
     * @var string
     * @ORM\Column(name="cancel_reason", type="text", nullable=true)
     */
    private $cancel_reason;

    /**
     * @var string
     * @ORM\Column(name="failed_invoice", type="string", nullable=true)
     */
    private $failed_invoice;

    /**
     * @var string
     * @ORM\Column(name="order_item_id", type="string", nullable=true)
     */
    private $order_item_id;
    
    // temporary 
    private $current_payment_total;

    

    public function __construct()
    {
        $this->Orders = new ArrayCollection();
    }

    public function getFailedInvoice()
    {
        return $this->failed_invoice;
    }
    public function setFailedInvoice($failed_invoice)
    {
        $this->failed_invoice = $failed_invoice;
        return $this;
    }

    public function getCurrentPaymentTotal() 
    {
        return $this->current_payment_total;
    }
    public function setCurrentPaymentTotal($current_payment_total) 
    {
        $this->current_payment_total = $current_payment_total;
        return;
    }

    public function getCancelReason() 
    {
        return $this->cancel_reason;
    }
    public function setCancelReason($cancel_reason)
    {
        $this->cancel_reason = $cancel_reason;
        return $this;
    }

    public function getManualLinkStamp() {
        return $this->manual_link_stamp;
    }
    public function setManualLinkStamp($manual_link_stamp)
    {
        $this->manual_link_stamp = $manual_link_stamp;
        return $this;
    }
    public function getOrders() {
        $criteria = Criteria::create()
            ->orderBy(['payment_date' => Criteria::DESC]);
        return $this->Orders->matching($criteria);
    }
    public function getPaidOrders() {
        $Orders = $this->getOrders();
        $PaidOrders = \array_filter($Orders->toArray(), function($Order) {
            return !empty($Order->getPaymentDate());
        });
        return $PaidOrders;
    }
    
    public function getPaymentCount() 
    {
        return count($this->getPaidOrders());
    }
    
    public function addOrder(Order $Order) {
        $this->Orders[] = $Order;
        return $this;
    }
    public function removeOrder(Order $Order) {
        return $this->Orders->removeElement($Order);
    }

    public function getCouponName(){
        return $this->coupon_name;
    }
    public function setCouponName($coupon_name){
        $this->coupon_name = $coupon_name;
        return $this;
    }

    public function getCouponDiscountStr(){
        return $this->coupon_discount_str;
    }
    public function setCouponDiscountStr($coupon_discount_str){
        $this->coupon_discount_str = $coupon_discount_str;
        return $this;
    }
    
    public function getCouponId(){
        return $this->coupon_id;
    }
    public function setCouponId($coupon_id){
        $this->coupon_id = $coupon_id;
        return $this;
    }

    public function isScheduled(){
        return strpos($this->getRecStatus(), self::REC_STATUS_SCHEDULED) !== false;
        // return !empty($this->schedule_id);
    }
    public function getScheduleId(){
        return $this->schedule_id;
    }
    public function setScheduleId($schedule_id){
        $this->schedule_id = $schedule_id;
        return $this;
    }
    
    
    public function getBundling(){
        return $this->bundling;
    }
    public function setBundling($bundling){
        $this->bundling = $bundling;
        return $this;
    }

    public function getStartDate(){
        return $this->start_date;
    }
    public function setStartDate($start_date){
        $this->start_date = $start_date;
        return $this;
    }
    
    public function getInvoiceData(){
        return $this->invoice_data;
    }
    public function setInvoiceData($invoice_data){
        $this->invoice_data =$invoice_data;
        return $this;
    }

    public function addInvoiceItem($item){
        $this->invoice_items[] = $item;
        return $this;
    }
    public function getInvoiceItems(){
        return $this->invoice_items;
    }

    public function setLastChargeId($last_charge_id){
        $this->last_charge_id = $last_charge_id;
        return $this;
    }
    public function getLastChargeId(){
        return $this->last_charge_id;
    }
    

    public function setSession($Session){
        $this->Session = $Session;
        return $this;
    }
    public function getSession(){
        return $this->Session;
    }


    public function getStripeCustomerEmail(){
        return $this->stripe_customer_email;
    }
    public function setStripeCustomerEmail($stripe_customer_email) {
        $this->stripe_customer_email = $stripe_customer_email;
        return $this;
    }
    public function getStripeCustomerId()
    {
        return $this->stripe_customer_id;
    }
    public function setStripeCustomerId($stripe_customer_id){
        $this->stripe_customer_id = $stripe_customer_id;
        return $this;
    }

    /**
     * Add orderItem.
     *
     * @param \Plugin\StripeRec\Entity\StripeRecOrderItem $recOrderItem
     *
     * @return StripeRecOrder
     */
    public function addOrderItem($recOrderItem)
    {
        $this->OrderItems[] = $recOrderItem;

        return $this;
    }

    public function removeOrderItem(\Plugin\StripeRec\Entity\StripeRecOrderItem $recOrderItem)
    {
        return $this->OrderItems->removeElement($recOrderItem);
    }

    /**
     * Get orderItems.
     *
     * @return \Doctrine\Common\Collections\Collection|StripeRecOrderItem[]
     */
    public function getOrderItems()
    {
        return $this->OrderItems;
    }
    
    public function setInterval($interval){
        $this->interval = $interval;
        return $this;
    }
    public function getInterval(){
        return $this->interval;
    }

    public function getCurrentPeriodStart(){
        return $this->current_period_start;
    }
    public function setCurrentPeriodStart($current_period_start){
        $this->current_period_start = $this->convertDateTime($current_period_start);
        return $this;
    }

    public function getCustomer(){
        return $this->Customer;
    }
    public function setCustomer($Customer){
        $this->Customer = $Customer;
        return $this;
    }

    public function getPaidStatus(){
        return $this->paid_status;
    }
    public function setPaidStatus($paid_status){
        $this->paid_status = $paid_status;
        return $this;
    }

    public function getLastPaymentDate(){
        return $this->last_payment_date;
    }
    public function setLastPaymentDate($last_payment_date){
        $this->last_payment_date = $this->convertDateTime($last_payment_date);
        return $this;
    }

    public function getId(){
        return $this->id;
    }

    public function setOrder($order){
        $this->Order = $order;        
        return $this;
    }
    public function getOrder(){
        return $this->Order;
    }

    public function setSubscriptionId($subscription_id){
        $this->subscription_id = $subscription_id;
        return $this;
    }
    public function getSubscriptionId(){
        return $this->subscription_id;
    }

    public function setCreateDate($create_date){
        $this->create_date = $this->convertDateTime($create_date);
        return $this;
    }
    public function getCreateDate(){
        return $this->create_date;
    }

    public function setCurrentPeriodEnd($current_period_end){
        $this->current_period_end = $this->convertDateTime($current_period_end);
        return $this;
    }
    public function getCurrentPeriodEnd(){
        return $this->current_period_end;
    }


    
    public function getRecStatus(){
        return $this->rec_status;
    }
    public function setRecStatus($rec_status){
        $this->rec_status = $rec_status;
        return $this;
    }
    public function convertDateTime($in){
        if(!($in instanceof \DateTime)){
            $dt1 = new \DateTime();
            $dt1->setTimestamp($in);
            return $dt1;
        }
        return $in;
    }

    public function copyFrom($subscription, $Customer = null){
        $this->setSubscriptionId($subscription->id);

        $created = $subscription->created;
        $dt = new \DateTime();
        $dt->setTimestamp($created);
        $this->setCreateDate($dt);

        $period_end = $subscription->current_period_end;
        $dt1 = new \DateTime();
        $dt1->setTimestamp($period_end);
        $this->setCurrentPeriodEnd($dt1);
        $this->setStripeCustomerId($subscription->customer);
        $this->setRecStatus($subscription->status);
        
        $item = $subscription->items->data[0];
               
        if(!empty($Customer)){
            $this->setCustomer($Customer);
        }
        $this->setInterval($item->plan->interval);
    }

    public function copyFromTempRecOrder($rec_order){
        $this->current_period_start = $rec_order->getCurrentPeriodStart();
        $this->current_period_end = $rec_order->getCurrentPeriodEnd();
        
        $this->paid_status = $rec_order->getPaidStatus();
        $this->last_payment_date = $rec_order->getLastPaymentDate();
        
        $this->interval = $rec_order->getInterval();        
        return $this;
    }
    public function getAmount(){
        $price = 0;
        foreach($this->OrderItems as $rec_order_item){            
            $price += $rec_order_item->getProductClass()->getPrice02IncTax() * $rec_order_item->getQuantity();
        }
        return $price;
    }
    public function getPaidAmount(){
        $price = 0;
        foreach($this->OrderItems as $rec_order_item){            
            if($rec_order_item->getPaidStatus() === StripeRecOrder::STATUS_PAID){
                $price += $rec_order_item->getProductClass()->getPrice02IncTax() * $rec_order_item->getQuantity();
            }
        }
        return $price;
    }
    public function getItemByPriceId($price_id){
        foreach($this->OrderItems as $rec_item){
            if($rec_item->getProductClass()->isRegistered() && $price_id === $rec_item->getProductClass()->getStripePriceId()){
                return $rec_item;
            }
        }
    }
    public function getOrderItemId()
    {
        return $this->order_item_id;
    }
    public function setOrderItemId($order_item_id)
    {
        $this->order_item_id = $order_item_id;
        return $this;
    }
}