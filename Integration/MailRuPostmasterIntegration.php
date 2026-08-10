<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MauticMailRuPostmasterBundle\Api\TokenPayload;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
    public const DEFAULT_RETENTION_DAYS = 30;
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
        return self::DEFAULT_RETENTION_DAYS;
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

    public function savePluginSettings(
        bool $enabled,
        string $tokenJson,
        int $retentionDays,
        int $fullSyncWeekday,
        string $fullSyncTime,
    ): void {
        $keys = $this->keys;
        $keys[self::TOKEN_JSON_FIELD] = trim($tokenJson);
        $keys[self::RETENTION_DAYS_FIELD] = self::DEFAULT_RETENTION_DAYS;
        $keys[self::FULL_SYNC_WEEKDAY_FIELD] = $fullSyncWeekday >= 0 && $fullSyncWeekday <= 6
            ? $fullSyncWeekday
            : self::DEFAULT_FULL_SYNC_WEEKDAY;
        $keys[self::FULL_SYNC_TIME_FIELD] = 1 === preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $fullSyncTime)
            ? $fullSyncTime
            : self::DEFAULT_FULL_SYNC_TIME;

        if ($enabled || '' !== $keys[self::TOKEN_JSON_FIELD]) {
            TokenPayload::fromJson($keys[self::TOKEN_JSON_FIELD]);
        }

        $this->settings->setIsPublished($enabled);
        $this->encryptAndSetApiKeys($keys, $this->settings);
        $this->persistIntegrationSettings();
        $this->setIntegrationSettings($this->settings);
    }
}
