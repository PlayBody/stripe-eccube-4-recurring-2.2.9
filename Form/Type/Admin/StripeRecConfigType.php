<?php
/*
* Plugin Name : StripeRec
*
* Copyright (C) 2020 Subspire. All Rights Reserved.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\StripeRec\Form\Type\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Eccube\Form\Type\ToggleSwitchType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class StripeRecConfigType extends AbstractType
{    
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $date_choices = [];
        for($i = 1; $i <= 28; $i++){
            $date_choices[$i] = $i;
        }
        $builder
            ->add('rec_webhook_sig', TextType::class, [
                'required' => false
            ])
            ->add('purchase_point', PurchasePointType::class, [
                'required' => true,
                'label' => 'stripe_recurring.admin.purchase_point_label',
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('payday_option', ChoiceType::class, [
                'required'  => true,
                'choices'   =>  [
                    '1日から計算' => 'first_date',
                    // 'Mid Date'   => 'mid_date',
                ],
                'label' =>  'stripe_recurring.config.label.payment_date_option',
                'expanded'  =>  true,
                'multiple'  =>  true,
            ])
            ->add('pay_full', ChoiceType::class, [
                'required'  =>  true,
                'choices'   =>  [
                    '全額計算'    =>  1,
                    '日割り計算'   =>  0,
                ],
            ])
            ->add('payment_date', ChoiceType::class, [
                'required'  =>  false,
                'label'     =>  'stripe_recurring.config.label.payment_date',
                'choices'   =>  $date_choices,
                'placeholder' => '指定なし'
            ])
            ->add('coupon_enable', ChoiceType::class, [
                'required'  =>  false,
                'choices'   =>  [
                    'はい'  =>  1,
                    'いいえ'=>  0,
                ]
            ])
            ->add('multi_product', ToggleSwitchType::class)
            ->add('incoming_mail', ToggleSwitchType::class);
    }
}