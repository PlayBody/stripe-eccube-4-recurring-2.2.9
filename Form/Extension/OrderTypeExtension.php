<?php

namespace Plugin\StripeRec\Form\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Eccube\Entity\ProductClass;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Eccube\Form\Type\Shopping\OrderType;
use Symfony\Component\Validator\Constraints as Assert;


class OrderTypeExtension extends AbstractTypeExtension
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
        $builder->add('after_days', DateType::class, [
                'input' => 'datetime',
                // 'years' => range(date('Y'), date('Y') + 20 ),
                'widget' => 'single_text',
                // 'format' => 'MM/dd/yyyy',                              
                
            ]);
            
        // $this->setPriceChange($builder);
        $this->validate($builder);
    }
    /**
     * {@inheritdoc}
     */
    public function getExtendedType()
    {
        return OrderType::class;
    }

    /**
     * Return the class of the type being extended.
     */
    public static function getExtendedTypes(): iterable
    {
        return [OrderType::class];
    }

    protected function validate(FormBuilderInterface $builder){
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event){
            $form = $event->getForm();
            $after_days = $form->get('after_days')->getData();
            if($after_days){
                $now = new \DateTime();
                
                if($after_days <= $now){
                    $form['after_days']->addError(new FormError(trans('stripe_recurring.schedule.error.select_past_date')));
                }
            }
        });
    }
}