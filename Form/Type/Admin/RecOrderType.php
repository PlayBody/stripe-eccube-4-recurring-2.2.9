<?php

namespace Plugin\StripeRec\Form\Type\Admin;

use Plugin\StripeRec\Entity\RecCsv;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Service\OrderStateMachine;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Form\Type\NameType;
use Eccube\Form\Type\KanaType;
use Eccube\Form\DataTransformer;
use Eccube\Form\Type\AddressType;
use Eccube\Form\Type\PhoneNumberType;
use Eccube\Form\Type\PostalType;
use Eccube\Form\Type\PriceType;
use Eccube\Form\Validator\Email;
use Eccube\Form\Type\Admin\ShippingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;


class RecOrderType extends AbstractType
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var OrderStateMachine
     */
    protected $orderStateMachine;

    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * OrderType constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param EccubeConfig $eccubeConfig
     * @param OrderStateMachine $orderStateMachine
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        EccubeConfig $eccubeConfig,
        OrderStateMachine $orderStateMachine,
        OrderStatusRepository $orderStatusRepository
    ) {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
        $this->orderStateMachine = $orderStateMachine;
        $this->orderStatusRepository = $orderStatusRepository;
    }


    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $optinos)
    {
        $builder
            ->add('name', NameType::class, [
                'required' => false,
                'options' => [
                    'constraints' => [
                        new Assert\NotBlank(),
                    ],
                ],
            ])
            ->add('kana', KanaType::class, [
                'required' => false,
                'options' => [
                    'constraints' => [
                        new Assert\NotBlank(),
                    ],
                ],
            ])
            ->add('company_name', TextType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_stext_len'],
                    ]),
                ],
            ])
            ->add('postal_code', PostalType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                ],
                'options' => [
                    'attr' => ['class' => 'p-postal-code'],
                ],
            ])
            ->add('address', AddressType::class, [
                'required' => false,
                'pref_options' => [
                    'constraints' => [
                        new Assert\NotBlank(),
                    ],
                    'attr' => ['class' => 'p-region-id'],
                ],
                'addr01_options' => [
                    'constraints' => [
                        new Assert\NotBlank(),
                        new Assert\Length([
                            'max' => $this->eccubeConfig['eccube_mtext_len'],
                        ]),
                    ],
                    'attr' => ['class' => 'p-locality p-street-address'],
                ],
                'addr02_options' => [
                    'required' => false,
                    'constraints' => [
                        new Assert\NotBlank(),
                        new Assert\Length([
                            'max' => $this->eccubeConfig['eccube_mtext_len'],
                        ]),
                    ],
                    'attr' => ['class' => 'p-extended-address'],
                ],
            ])
            ->add('email', EmailType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Email(['strict' => $this->eccubeConfig['eccube_rfc_email_check']]),
                ],
            ])
            ->add('phone_number', PhoneNumberType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('company_name', TextType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_stext_len'],
                    ]),
                ],
            ])
            ->add('message', TextareaType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_ltext_len'],
                    ]),
                ],
            ])
            ->add('discount', PriceType::class, [
                'required' => false,
            ])
            ->add('delivery_fee_total', PriceType::class, [
                'required' => false,
            ])
            ->add('charge', PriceType::class, [
                'required' => false,
            ])
            ->add('use_point', NumberType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => "/^\d+$/u",
                        'message' => 'form_error.numeric_only',
                    ]),
                ],
            ])
            ->add('note', TextareaType::class, [
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_ltext_len'],
                    ]),
                ],
            ])
            ->add('Payment', EntityType::class, [
                'required' => false,
                'class' => Payment::class,
                'choice_label' => function (Payment $Payment) {
                    return $Payment->isVisible()
                        ? $Payment->getMethod()
                        : $Payment->getMethod().trans('admin.common.hidden_label');
                },
                'placeholder' => false,
                'query_builder' => function ($er) {
                    return $er->createQueryBuilder('p')
                        ->orderBy('p.visible', 'DESC')  // 非表示は下に配置
                        ->addOrderBy('p.sort_no', 'ASC');
                },
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('return_link', HiddenType::class, [
                'mapped' => false,
            ]);

            $builder
                ->add($builder->create('Customer', HiddenType::class)
                    ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                        $this->entityManager,
                        '\Eccube\Entity\Customer'
                    )));
            $builder->addEventListener(FormEvents::POST_SET_DATA, [$this, 'addShippingForm']);
    }

    /**
     * 単一配送時に, Shippingのフォームを追加する.
     * 複数配送時はShippingの編集は行わない.
     *
     * @param FormEvent $event
     */
    public function addShippingForm(FormEvent $event)
    {
        /** @var Order $Order */
        $Order = $event->getData();

        // 複数配送時はShippingの編集は行わない
        if ($Order && $Order->isMultiple()) {
            return;
        }

        $data = $Order ? $Order->getShippings()->first() : null;
        $form = $event->getForm();
        $form->add('Shipping', ShippingType::class, [
            'mapped' => false,
            'data' => $data,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Order::class,
        ]);
    }
}