<?php

namespace Plugin\StripeRec\Form\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Form\Type\Admin\ProductClassEditType;
use Eccube\Form\Type\PriceType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Eccube\Entity\ProductClass;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;

class ProductClassExtension extends AbstractTypeExtension
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager){
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options){
        $builder->add('register_flg', ChoiceType::class, [
            'required' => false,
            // 'label'     =>  trans('stripe_recurring.admin.product_class.register_flg')
            'choices' => [
                '指定なし' => 'none',
                '日次' => 'day',
                // '３ヶ月ごと' =>  'quarter',
                // '６ヶ月ごと' =>  'semiannual',
                '週次' => 'week',
                '月次' => 'month',
                '年次' => 'year'
            ]
        ])
            ->add('bundle_product', TextType::class, [
                'required' => false,
            ])
            ->add('bundle_required', CheckboxType::class, [
                'label' => 'stripe_recurring.mypage.schedule.bundle_required',
                'required' => false,
            ])
            ->add('initial_price', PriceType::class, [
                'required' => false,
            ])->add('first_cycle_free', CheckboxType::class, [
                'label' => 'stripe_recurring.admin.product.first_cycle_free',
                'required' => false,
            ]);
        // $this->setPriceChange($builder);
        $this->setInitialPriceBasedOnFirstCycleFree($builder);
        $this->validate($builder);
    }

    /**
     * {@inheritdoc}
     */
    public function getExtendedType()
    {
        return ProductClassEditType::class;
    }

    /**
     * Return the class of the type being extended.
     */
    public static function getExtendedTypes(): iterable
    {
        return [ProductClassEditType::class];
    }

    protected function validate(FormBuilderInterface $builder){
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $bundle_product_code = $form['bundle_product']->getData();
            if ($bundle_product_code) {
                $bundle_product = $this->entityManager->getRepository(ProductClass::class)->findOneBy(['code' => $bundle_product_code]);
                if (empty($bundle_product)) {
                    $form['bundle_product']->addError(new FormError(trans('stripe_recurring.admin.product_class.bundle_product.no_product_code')));
                }
            }
        });
    }
    // protected function setPriceChange(FormBuilderInterface $builder){
    //     $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
    //         $form = $event->getForm();

    //         $data = $event->getData();     
    //         if (!$data instanceof ProductClass) {
    //             return;
    //         }       
    //         $id = $data->getId();
    //         if(empty($id)){
    //             return;
    //         }
    //         $connection = $this->entityManager->getConnection();
    //         $statement = $connection->prepare('select price02 from dtb_product_class where id = :id');
    //         $statement->bindValue('id', $data->getId());
    //         $statement->execute();
    //         $pcs = $statement->fetchAll();
    //     });
    // }
    // /**
    //  * 各行の登録チェックボックスの制御.
    //  *
    //  * @param FormBuilderInterface $builder
    //  */
    // protected function setRecId(FormBuilderInterface $builder)
    // {

    //     $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
    //         $data = $event->getData();            
    //         if (!$data instanceof ProductClass) {
    //             return;
    //         }
    //         if ($data->getId() && $data->getRecurringId()) {
    //             $form = $event->getForm();
    //             $form['register_flg']->setData($data->getRegisterFlg());
    //             if($data->getRegisterFlg()){
    //                 $options = $form['register_flg']->getOptions();
    //                 $options['attr']['disabled'] = true;
    //                 $builder->add('register_flg', CheckboxType::class, $options);
    //             }
    //         }
    //     });

    //     $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
    //         $form = $event->getForm();

    //         $data = $event->getData();
    //         $register_flg = $form['register_flg']->getData();
    //         if(!empty($register_flg)){
    //             $data->setRegisterFlg($register_flg);
    //         }            
    //     });
    // }

    protected function setInitialPriceBasedOnFirstCycleFree(FormBuilderInterface $builder)
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();

            $data = $event->getData();
            if (!$data instanceof ProductClass) {
                return;
            }
            $first_cycle_free = $form['first_cycle_free']->getData();
            if (!empty($first_cycle_free)) {
                $data->setInitialPrice(0);
            }
        });
    }

}