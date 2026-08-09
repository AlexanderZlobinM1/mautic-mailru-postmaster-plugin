<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @phpstan-ignore class.extendsDeprecatedClass */
final class MailRuPostmasterIntegration extends AbstractIntegration
{
    public const NAME             = 'MailRuPostmaster';
    public const TOKEN_JSON_FIELD = 'token_json';
    public const RETENTION_DAYS_FIELD = 'retention_days';
    public const DEFAULT_RETENTION_DAYS = 365;
    public const FULL_SYNC_WEEKDAY_FIELD = 'full_sync_weekday';
    public const FULL_SYNC_TIME_FIELD = 'full_sync_time';
    public const DEFAULT_FULL_SYNC_WEEKDAY = 0;
    public const DEFAULT_FULL_SYNC_TIME = '03:00';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Mail.ru Postmaster';
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getIcon(): string
    {
        return 'plugins/MauticMailRuPostmasterBundle/Assets/img/postmaster.svg';
    }

    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [];
    }

    /**
     * @param FormBuilder|Form $builder
     * @param array<mixed>     $data
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('keys' !== $formArea) {
            return;
        }

        $builder->add(self::TOKEN_JSON_FIELD, TextareaType::class, [
            'label'      => 'mailru.postmaster.config.token_json',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'data'       => $data[self::TOKEN_JSON_FIELD] ?? '',
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
                    $value       = is_string($value) ? trim($value) : '';
                    $root        = $context->getRoot();
                    $publishForm = $root instanceof FormInterface && $root->has('isPublished')
                        ? (bool) $root->get('isPublished')->getData()
                        : false;

                    if ('' === $value) {
                        if ($publishForm) {
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

        $builder->add(self::RETENTION_DAYS_FIELD, ChoiceType::class, [
            'label'      => 'mailru.postmaster.config.retention_days',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'data'       => (int) ($data[self::RETENTION_DAYS_FIELD] ?? self::DEFAULT_RETENTION_DAYS),
            'choices'    => [
                'mailru.postmaster.config.retention_days.30'  => 30,
                'mailru.postmaster.config.retention_days.90'  => 90,
                'mailru.postmaster.config.retention_days.180' => 180,
                'mailru.postmaster.config.retention_days.365' => 365,
            ],
            'attr' => [
                'class'   => 'form-control',
                'tooltip' => 'mailru.postmaster.config.retention_days.help',
            ],
        ]);

        $builder->add(self::FULL_SYNC_WEEKDAY_FIELD, ChoiceType::class, [
            'label'      => 'mailru.postmaster.config.full_sync_weekday',
            'label_attr' => ['class' => 'control-label'],
            'required'   => true,
            'data'       => (int) ($data[self::FULL_SYNC_WEEKDAY_FIELD] ?? self::DEFAULT_FULL_SYNC_WEEKDAY),
            'choices'    => [
                'mailru.postmaster.weekday.sunday'    => 0,
                'mailru.postmaster.weekday.monday'    => 1,
                'mailru.postmaster.weekday.tuesday'   => 2,
                'mailru.postmaster.weekday.wednesday' => 3,
                'mailru.postmaster.weekday.thursday'  => 4,
                'mailru.postmaster.weekday.friday'    => 5,
                'mailru.postmaster.weekday.saturday'  => 6,
            ],
            'attr' => [
                'class'   => 'form-control',
                'tooltip' => 'mailru.postmaster.config.full_sync_schedule.help',
            ],
        ]);

        $builder->add(self::FULL_SYNC_TIME_FIELD, TimeType::class, [
            'label'        => 'mailru.postmaster.config.full_sync_time',
            'label_attr'   => ['class' => 'control-label'],
            'required'     => true,
            'input'        => 'string',
            'input_format' => 'H:i',
            'widget'       => 'single_text',
            'with_seconds' => false,
            'data'         => (string) ($data[self::FULL_SYNC_TIME_FIELD] ?? self::DEFAULT_FULL_SYNC_TIME),
            'attr'         => [
                'class'   => 'form-control',
                'tooltip' => 'mailru.postmaster.config.full_sync_schedule.help',
            ],
        ]);
    }

    /**
     * @param string $section
     *
     * @return array<mixed>
     */
    public function getFormNotes($section): array
    {
        if ('custom' === $section) {
            return [
                'custom'     => true,
                'template'   => '@MauticMailRuPostmaster/Integration/form.html.twig',
                'parameters' => [
                    'token_url' => 'https://o2.mail.ru/login?client_id=postmaster_api_client&redirect_uri=https%3A%2F%2Fpostmaster.mail.ru%2Fext-api%2Foauth%2F&response_type=code&state=some_state',
                ],
            ];
        }

        return ['', 'info'];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->getIsPublished();
    }

    public function getTokenJson(): string
    {
        return (string) ($this->keys[self::TOKEN_JSON_FIELD] ?? '');
    }

    public function getRetentionDays(): int
    {
        $days = (int) ($this->keys[self::RETENTION_DAYS_FIELD] ?? self::DEFAULT_RETENTION_DAYS);

        return in_array($days, [30, 90, 180, 365], true) ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public function getFullSyncWeekday(): int
    {
        $weekday = (int) ($this->keys[self::FULL_SYNC_WEEKDAY_FIELD] ?? self::DEFAULT_FULL_SYNC_WEEKDAY);

        return $weekday >= 0 && $weekday <= 6 ? $weekday : self::DEFAULT_FULL_SYNC_WEEKDAY;
    }

    public function getFullSyncTime(): string
    {
        $time = (string) ($this->keys[self::FULL_SYNC_TIME_FIELD] ?? self::DEFAULT_FULL_SYNC_TIME);

        return 1 === preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time)
            ? $time
            : self::DEFAULT_FULL_SYNC_TIME;
    }

    public function saveTokenPayload(TokenPayload $payload): void
    {
        $keys                         = $this->keys;
        $keys[self::TOKEN_JSON_FIELD] = $payload->toJson();
        $this->encryptAndSetApiKeys($keys, $this->settings);
        $this->persistIntegrationSettings();
        $this->setIntegrationSettings($this->settings);
    }
}
