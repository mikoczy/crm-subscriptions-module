<?php

namespace Crm\SubscriptionsModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Extension\ExtendSameTypeExtension;
use Crm\SubscriptionsModule\Repository\SubscriptionExtensionMethodsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class ExtendSameTypeExtensionTest extends DatabaseTestCase
{
    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var ExtendSameTypeExtension */
    private $extension;

    private $user;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension = $this->inject(ExtendSameTypeExtension::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $userManager = $this->inject(UserManager::class);
        $this->user = $userManager->addNewUser('test@example.com');
    }

    protected function requiredRepositories(): array
    {
        return [
            SubscriptionsRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeNamesRepository::class,
            SubscriptionExtensionMethodsRepository::class,
            UsersRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            SubscriptionTypeNamesSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class
        ];
    }

    private function getSubscriptionType()
    {
        /** @var SubscriptionTypeBuilder $subscriptionTypeBuilder */
        $subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);

        $subscriptionTypeRow = $subscriptionTypeBuilder
            ->createNew()
            ->setNameAndUserLabel(random_int(0, 9999))
            ->setActive(1)
            ->setPrice(1)
            ->setLength(30)
            ->save();

        return $subscriptionTypeRow;
    }

    private function addSubscription(IRow $subscriptionType, DateTime $from, DateTime $to)
    {
        return $this->subscriptionsRepository->add(
            $subscriptionType,
            false,
            true,
            $this->user,
            SubscriptionsRepository::TYPE_REGULAR,
            $from,
            $to
        );
    }

    public function testNoSubscription()
    {
        $subscriptionType = $this->getSubscriptionType();
        $nowDate = DateTime::from('2021-02-01');

        $this->extension->setNow($nowDate);
        $this->subscriptionsRepository->setNow($nowDate);
        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate, $result->getDate());
        $this->assertFalse($result->isExtending());
    }

    public function testActualSubscription()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate, $nowDate->modifyClone('+25 days'));

        $this->extension->setNow($nowDate);
        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate->modifyClone('+25 days'), $result->getDate());
        $this->assertTrue($result->isExtending());
    }

    public function testExpiredSubscription()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-35 days'), $nowDate->modifyClone('-5 days'));

        $this->extension->setNow($nowDate);
        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate, $result->getDate());
        $this->assertFalse($result->isExtending());
    }

    public function testAnotherSubscriptionTypeActual()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-5 days'), $nowDate->modifyClone('+25 days'));

        $this->extension->setNow($nowDate);
        $this->subscriptionsRepository->setNow($nowDate);
        $anotherSubscriptionType = $this->getSubscriptionType();
        $result = $this->extension->getStartTime($this->user, $anotherSubscriptionType);

        $this->assertEquals($nowDate->modifyClone('+25 days'), $result->getDate());
        $this->assertFalse($result->isExtending());
    }

    public function testAnotherSubscriptionTypeExpired()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-35 days'), $nowDate->modifyClone('-5 days'));

        $this->extension->setNow($nowDate);
        $anotherSubscriptionType = $this->getSubscriptionType();
        $result = $this->extension->getStartTime($this->user, $anotherSubscriptionType);

        $this->assertEquals($nowDate, $result->getDate());
        $this->assertFalse($result->isExtending());
    }

    public function testLastActualSubscription()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-5 days'), $nowDate->modifyClone('+25 days'));
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-10 days'), $nowDate->modifyClone('+20 days'));

        $this->extension->setNow($nowDate);
        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate->modifyClone('+25 days'), $result->getDate());
        $this->assertTrue($result->isExtending());
    }

    public function testLastSameTypeSubscription()
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();
        $anotherSubscriptionType = $this->getSubscriptionType();
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-5 days'), $nowDate->modifyClone('+25 days'));
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('+50 days'), $nowDate->modifyClone('+80 days'));
        $this->addSubscription($anotherSubscriptionType, $nowDate->modifyClone('+60 days'), $nowDate->modifyClone('+90 days'));

        $this->extension->setNow($nowDate);
        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate->modifyClone('+80 days'), $result->getDate());
        $this->assertTrue($result->isExtending());
    }

    public function testLifetimeSameTypeSubscription(): void
    {
        $nowDate = DateTime::from('2021-02-01');
        $subscriptionType = $this->getSubscriptionType();

        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-5 days'), $nowDate->modifyClone('+100 years'));

        $this->extension->setNow($nowDate);
        $this->subscriptionsRepository->setNow($nowDate);

        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate, $result->getDate());
        $this->assertFalse($result->isExtending());
    }

    public function testLifetimeSameTypeSubscriptionWithActualNonLifetime(): void
    {
        $nowDate = DateTime::from('2021-02-01');

        $subscriptionType = $this->getSubscriptionType();

        $this->addSubscription($subscriptionType, $nowDate->modifyClone('-5 days'), $nowDate->modifyClone('+25 days'));
        $this->addSubscription($subscriptionType, $nowDate->modifyClone('+25 days'), $nowDate->modifyClone('+100 years'));

        $this->extension->setNow($nowDate);
        $this->subscriptionsRepository->setNow($nowDate);

        $result = $this->extension->getStartTime($this->user, $subscriptionType);

        $this->assertEquals($nowDate->modifyClone('+25 days'), $result->getDate());
        $this->assertTrue($result->isExtending());
    }
}
