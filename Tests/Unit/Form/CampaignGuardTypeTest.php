<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Form;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\MauticMailRuPostmasterBundle\Form\Type\CampaignGuardType;
use MauticPlugin\MauticMailRuPostmasterBundle\Service\EmailDomainProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class CampaignGuardTypeTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', 'mailru_test_');
        }

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE %semails (id INTEGER, from_address VARCHAR(255))',
            MAUTIC_TABLE_PREFIX,
        ));
    }

    public function testConditionFormBuildsWithSavedDomainAndHistoryFields(): void
    {
        $this->connection->insert(MAUTIC_TABLE_PREFIX.'emails', [
            'id' => 398,
            'from_address' => 'no-reply@personal-alex.online',
        ]);

        $form = $this->formFactory()->create(CampaignGuardType::class, [
            'domain' => 'personal-alex.online',
            'probably_spam_threshold' => 10,
            'probably_spam_history_days' => 0,
            'spam_threshold' => 5,
            'spam_history_days' => 0,
        ], ['mode' => 'condition']);

        self::assertSame([
            'domain',
            'probably_spam_threshold',
            'probably_spam_history_days',
            'spam_threshold',
            'spam_history_days',
        ], array_keys($form->all()));
        self::assertSame('personal-alex.online', $form->get('domain')->getData());
    }

    public function testConditionFormIgnoresMalformedAndEmptySenderAddresses(): void
    {
        $this->connection->insert(MAUTIC_TABLE_PREFIX.'emails', [
            'id' => 1,
            'from_address' => 'not-an-email',
        ]);
        $this->connection->insert(MAUTIC_TABLE_PREFIX.'emails', [
            'id' => 2,
            'from_address' => '',
        ]);

        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->expects(self::once())
            ->method('get')
            ->with('mailer_from_email')
            ->willReturn('no-reply@alex-personal.ru');

        $provider = new EmailDomainProvider($this->connection, $parameters);
        $form = Forms::createFormFactoryBuilder()
            ->addType(new CampaignGuardType($provider))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->create(CampaignGuardType::class, [
                'domain' => 'alex-personal.ru',
                'probably_spam_threshold' => 10,
                'probably_spam_history_days' => 0,
                'spam_threshold' => 5,
                'spam_history_days' => 0,
            ], ['mode' => 'condition']);

        self::assertSame('alex-personal.ru', $form->get('domain')->getData());
    }

    private function formFactory(): FormFactoryInterface
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('no-reply@alex-personal.ru');

        return Forms::createFormFactoryBuilder()
            ->addType(new CampaignGuardType(new EmailDomainProvider($this->connection, $parameters)))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
