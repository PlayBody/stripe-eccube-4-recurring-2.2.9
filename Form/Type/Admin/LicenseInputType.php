<?php

namespace Plugin\StripeRec\Form\Type\Admin;

use Plugin\StripeRec\Entity\LicenseKey;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LicenseInputType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $optinos)
    {
        $builder
            ->add('license_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(array(
                            'message' => trans('stripe_recurring.admin.license.license_key.error.blank')
                        )
                    ),
                    new Assert\Regex(array(
                            'pattern' => '/^[\w-]*$/',
                            'match' => true,
                            'message' => trans('stripe_recurring.admin.license.license_key.error.regex')
                        )
                    )
                ],  
            ])
            ->add('email', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(array(
                            'message' => trans('stripe_recurring.admin.license.email.error.blank')
                        )
                    ),
                    new Assert\Regex(array(
                            'pattern' => '/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})$/i',
                            'match' => true,
                            'message' => trans('stripe_recurring.admin.license.email.error.regex')
                        )
                    )
                ],  
            ]);
    }
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => LicenseKey::class,
        ]);
    }
}