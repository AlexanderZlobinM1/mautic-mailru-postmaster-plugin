<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Form\Type;

use Mautic\EmailBundle\Form\Type\EmailListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<mixed>
 */
final class CampaignGuardType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed>        $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailListType::class, [
            'label'      => 'mailru.postmaster.guard.email',
            'label_attr' => ['class' => 'control-label'],
            'multiple'   => false,
            'required'   => true,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mailru.postmaster.guard.email.help',
            ],
            'constraints' => [new NotBlank()],
        ]);

        $this->addThreshold($builder, 'probably_spam_threshold', 'mailru.postmaster.guard.probably_spam_threshold');
        $this->addThreshold($builder, 'spam_threshold', 'mailru.postmaster.guard.spam_threshold');
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    private function addThreshold(FormBuilderInterface $builder, string $name, string $label): void
    {
        $builder->add($name, NumberType::class, [
            'label'      => $label,
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'scale'      => 4,
            'html5'      => true,
            'attr'       => [
                'class'    => 'form-control',
                'min'      => 0,
                'max'      => 100,
                'step'     => '0.01',
                'postaddon'=> '%',
            ],
            'constraints' => [
                new NotBlank(),
                new Range(min: 0, max: 100),
            ],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'mailru_postmaster_campaign_guard';
    }
}
