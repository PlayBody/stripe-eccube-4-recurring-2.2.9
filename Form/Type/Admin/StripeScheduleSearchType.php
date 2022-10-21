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
use Symfony\Component\Validator\Constraints as Assert;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Eccube\Common\EccubeConfig;

class StripeScheduleSearchType extends AbstractType
{    
    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    public function __construct(EccubeConfig $eccubeConfig) 
    {
        $this->eccubeConfig = $eccubeConfig;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder            
            ->add('multi', TextType::class, [
                'label' => 'admin.order.multi_search_label',
                'required' => false,
                'constraints' => [
                    new Assert\Length(['max' => $this->eccubeConfig['eccube_stext_len']]),
                ],
            ])
            ->add('schedule_status', ChoiceType::class, [
                'choices'   =>  [
                    StripeRecOrder::REC_STATUS_SCHEDULED,
                    StripeRecOrder::REC_STATUS_SCHEDULED_CANCELED,
                ],
                'required'  => false,
                'expanded'  =>  true,
                'multiple'  =>  true
            ]);
    }
}