<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailRuPostmasterBundle\Tests\Unit\Integration;

use MauticPlugin\MauticMailRuPostmasterBundle\Integration\MailRuPostmasterIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class MailRuPostmasterIntegrationTest extends TestCase
{
    public function testDescriptionIsTranslated(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('mailru.postmaster.description')
            ->willReturn('Translated description');

        $integration = (new \ReflectionClass(MailRuPostmasterIntegration::class))->newInstanceWithoutConstructor();
        $property    = new \ReflectionProperty($integration, 'translator');
        $property->setValue($integration, $translator);

        self::assertSame('Translated description', $integration->getDescription());
    }

    public function testCanonicalFormContainsValidatedTokenTextarea(): void
    {
        $integration = (new \ReflectionClass(MailRuPostmasterIntegration::class))->newInstanceWithoutConstructor();
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
        $builder = $formFactory->createBuilder();
        self::assertInstanceOf(FormBuilder::class, $builder);

        $integration->appendToForm($builder, [], 'keys');
        $form = $builder->getForm();

        self::assertTrue($form->has(MailRuPostmasterIntegration::TOKEN_JSON_FIELD));
        self::assertFalse($form->has(MailRuPostmasterIntegration::RETENTION_DAYS_FIELD));
        self::assertFalse($form->has(MailRuPostmasterIntegration::FULL_SYNC_WEEKDAY_FIELD));
        self::assertFalse($form->has(MailRuPostmasterIntegration::FULL_SYNC_TIME_FIELD));

        $form->submit([
            MailRuPostmasterIntegration::TOKEN_JSON_FIELD => 'not-json',
        ]);
        self::assertGreaterThan(0, $form->get(MailRuPostmasterIntegration::TOKEN_JSON_FIELD)->getErrors(true)->count());
    }

    public function testCustomNotesUseCanonicalTemplate(): void
    {
        $integration = (new \ReflectionClass(MailRuPostmasterIntegration::class))->newInstanceWithoutConstructor();
        $notes       = $integration->getFormNotes('custom');

        self::assertSame('@MauticMailRuPostmaster/Integration/form.html.twig', $notes['template']);
        self::assertStringStartsWith('https://o2.mail.ru/', $notes['parameters']['token_url']);
    }
}
