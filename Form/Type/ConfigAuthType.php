<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Form\Type;

use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @extends AbstractType<mixed>
 */
final class ConfigAuthType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed>        $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('token_json', TextareaType::class, [
            'label'      => 'mailru.postmaster.config.token_json',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'attr'       => [
                'class'        => 'form-control',
                'rows'         => 5,
                'autocomplete' => 'off',
                'spellcheck'   => 'false',
                'placeholder'  => '{"access_token":"...","expires_in":3600,"refresh_token":"..."}',
                'tooltip'      => 'mailru.postmaster.config.token_json.help',
            ],
            'constraints' => [
                new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                    try {
                        TokenPayload::fromJson(is_string($value) ? $value : '');
                    } catch (\InvalidArgumentException $exception) {
                        $context->buildViolation($exception->getMessage())->addViolation();
                    }
                }),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['integration' => null]);
    }
}
