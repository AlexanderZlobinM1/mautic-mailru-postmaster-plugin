<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Form\Type;

use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<mixed>
 */
final class CampaignGuardType extends AbstractType
{
    public function __construct(private readonly EmailDomainProvider $domainProvider)
    {
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed>        $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $domains = $this->domainProvider->getDomains();
        $choices = [];
        foreach ($domains as $domain) {
            $choices[$domain] = $domain;
        }

        $builder->add('domain', ChoiceType::class, [
            'label'      => 'mailru.postmaster.guard.domain',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => $choices,
            'placeholder'=> 'mailru.postmaster.guard.domain.placeholder',
            'required'   => true,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mailru.postmaster.guard.domain.help',
            ],
            'constraints' => [new NotBlank()],
        ]);

        // Existing nodes created before 0.5.15 stored an email ID. Resolve it
        // only for the edit form; saving the node persists the explicit domain.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)
                || !empty($data['domain'])
                || empty($data['email'])) {
                return;
            }

            $domain = $this->domainProvider->getDomainForEmail((int) $data['email']);
            if (null !== $domain) {
                $data['domain'] = $domain;
                $event->setData($data);
            }
        });

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
