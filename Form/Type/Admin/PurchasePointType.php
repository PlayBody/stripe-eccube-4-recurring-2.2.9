<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\StripeRec\Form\Type\Admin;

use Eccube\Form\Type\MasterType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Plugin\StripeRec\Repository\PurchasePointRepository;
use Plugin\StripeRec\Entity\PurchasePoint;

class PurchasePointType extends AbstractType
{
    /**
     * @var PurchasePointRepository
     */
    protected $purchase_point_repo;

    /**
     * @param PurchasePointRepository $purchase_point_repo
     */
    public function __construct(PurchasePointRepository $purchase_point_repo)
    {
        $this->purchase_point_repo = $purchase_point_repo;
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        /** @var PurchasePoint[] $purchase_points */
        $purchase_points = $options['choice_loader']->loadChoiceList()->getChoices();
        foreach ($purchase_points as $purchase_point) {
            $id = $purchase_point->getId();            
            $view->vars['checked'][$id] = $purchase_point->isEnabled();              
        }
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'class' => PurchasePoint::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'purchase_point';
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return MasterType::class;
    }
}
