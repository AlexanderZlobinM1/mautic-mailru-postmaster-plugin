<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @extends AbstractType<array<string, mixed>> */
final class PostmasterConfigType extends AbstractType
{
    public const ALIAS = 'mailru_postmaster_config';
    public const ENABLED = 'mailru_postmaster_enabled';
    public const TOKEN_JSON = 'mailru_postmaster_token_json';
    public const RETENTION_DAYS = 'mailru_postmaster_retention_days';
    public const FULL_SYNC_WEEKDAY = 'mailru_postmaster_full_sync_weekday';
    public const FULL_SYNC_TIME = 'mailru_postmaster_full_sync_time';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = is_array($options['data'] ?? null) ? $options['data'] : [];

        $builder->add(self::ENABLED, YesNoButtonGroupType::class, [
            'label' => 'mailru.postmaster.config.enabled',
            'data'  => (bool) ($data[self::ENABLED] ?? false),
        ]);

        $builder->add(self::TOKEN_JSON, TextareaType::class, [
            'label'      => 'mailru.postmaster.config.token_json',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'data'       => (string) ($data[self::TOKEN_JSON] ?? ''),
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
                    $value = is_string($value) ? trim($value) : '';
                    $root = $context->getRoot();
                    $enabled = $root instanceof FormInterface && $root->has(self::ENABLED)
                        ? (bool) $root->get(self::ENABLED)->getData()
                        : false;
                    if ('' === $value) {
                        if ($enabled) {
                            $context->buildViolation('mailru.postmaster.config.error.token_required')->addViolation();
                        }

                        return;
                    }
                    try {
                        TokenPayload::fromJson($value);
                    } catch (\InvalidArgumentException $exception) {
                        $context->buildViolation($exception->getMessage())->addViolation();
                    }
                }),
            ],
        ]);

        $builder->add(self::RETENTION_DAYS, ChoiceType::class, [
            'label'      => 'mailru.postmaster.config.retention_days',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'data'       => (int) ($data[self::RETENTION_DAYS] ?? 365),
            'choices'    => [
                'mailru.postmaster.config.retention_days.30'  => 30,
                'mailru.postmaster.config.retention_days.90'  => 90,
                'mailru.postmaster.config.retention_days.180' => 180,
                'mailru.postmaster.config.retention_days.365' => 365,
            ],
            'attr' => ['class' => 'form-control', 'tooltip' => 'mailru.postmaster.config.retention_days.help'],
        ]);

        $builder->add(self::FULL_SYNC_WEEKDAY, ChoiceType::class, [
            'label'      => 'mailru.postmaster.config.full_sync_weekday',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'data'       => (int) ($data[self::FULL_SYNC_WEEKDAY] ?? 0),
            'choices'    => [
                'mailru.postmaster.weekday.sunday'    => 0,
                'mailru.postmaster.weekday.monday'    => 1,
                'mailru.postmaster.weekday.tuesday'   => 2,
                'mailru.postmaster.weekday.wednesday' => 3,
                'mailru.postmaster.weekday.thursday'  => 4,
                'mailru.postmaster.weekday.friday'    => 5,
                'mailru.postmaster.weekday.saturday'  => 6,
            ],
            'attr' => ['class' => 'form-control', 'tooltip' => 'mailru.postmaster.config.full_sync_schedule.help'],
        ]);

        $builder->add(self::FULL_SYNC_TIME, TimeType::class, [
            'label'        => 'mailru.postmaster.config.full_sync_time',
            'label_attr'   => ['class' => 'control-label'],
            'required'     => true,
            'input'        => 'string',
            'input_format' => 'H:i',
            'widget'       => 'single_text',
            'with_seconds' => false,
            'data'         => (string) ($data[self::FULL_SYNC_TIME] ?? '03:00'),
            'attr'         => ['class' => 'form-control', 'tooltip' => 'mailru.postmaster.config.full_sync_schedule.help'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return self::ALIAS;
    }
}
