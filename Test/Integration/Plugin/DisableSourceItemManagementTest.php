<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Plugin;

use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use PHPUnit\Framework\TestCase;

#[DbIsolation(true)]
class DisableSourceItemManagementTest extends TestCase
{
    private ?IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->isSourceItemManagementAllowed = $objectManager->get(
            IsSourceItemManagementAllowedForProductTypeInterface::class
        );
    }

    public function testSourceItemManagementDisabledForAggregateType(): void
    {
        $isAllowed = $this->isSourceItemManagementAllowed->execute(Aggregate::TYPE_CODE);

        $this->assertFalse(
            $isAllowed,
            'Source item management should be disabled for aggregate products'
        );
    }

    public function testSourceItemManagementAllowedForSimpleType(): void
    {
        $isAllowed = $this->isSourceItemManagementAllowed->execute('simple');

        $this->assertTrue(
            $isAllowed,
            'Source item management should be allowed for simple products'
        );
    }

    public function testSourceItemManagementAllowedForVirtualType(): void
    {
        $isAllowed = $this->isSourceItemManagementAllowed->execute('virtual');

        $this->assertTrue(
            $isAllowed,
            'Source item management should be allowed for virtual products'
        );
    }
}
