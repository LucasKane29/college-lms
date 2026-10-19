<?php

namespace App\Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class UserContactTest extends TestCase
{
    public function testContactFieldsAreNullableOutsideRegistration(): void
    {
        $user = new User();
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertNull($user->getPhoneNumber());
        self::assertNull($user->getBirthDate());
        foreach (['phoneNumber', 'birthDate'] as $field) {
            self::assertCount(0, $validator->validateProperty($user, $field));
            self::assertCount(1, $validator->validateProperty($user, $field, ['Default', 'registration']));
        }
    }

    public function testPopulatedFieldsRemainValidatedOutsideRegistration(): void
    {
        $user = (new User())->setPhoneNumber('invalid')->setBirthDate(new \DateTimeImmutable('tomorrow'));
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertCount(1, $validator->validateProperty($user, 'phoneNumber'));
        self::assertCount(1, $validator->validateProperty($user, 'birthDate'));
        $user->setPhoneNumber('+380671234567')->setBirthDate(new \DateTimeImmutable('2005-04-15'));
        self::assertCount(0, $validator->validateProperty($user, 'phoneNumber', ['Default', 'registration']));
        self::assertCount(0, $validator->validateProperty($user, 'birthDate', ['Default', 'registration']));
        $user->setPhoneNumber('  ')->setBirthDate(null);
        self::assertNull($user->getPhoneNumber());
        self::assertNull($user->getBirthDate());
    }

    public function testMaskedPhoneIsStoredWithoutFormatting(): void
    {
        $user = (new User())->setPhoneNumber('+380 (67) 123-45-67');
        self::assertSame('+380671234567', $user->getPhoneNumber());
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertCount(0, $validator->validateProperty($user, 'phoneNumber', ['Default', 'registration']));
        $user->setPhoneNumber('+380 (67) 123');
        self::assertCount(1, $validator->validateProperty($user, 'phoneNumber', ['Default', 'registration']));
    }
}
