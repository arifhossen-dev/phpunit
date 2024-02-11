<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\MockObject;

use PHPUnit\Framework\Attributes\DisableReturnValueGenerationForTestDoubles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use PHPUnit\TestFixture\MockObject\ExtendableClass;

#[Group('test-doubles')]
#[Group('test-doubles/creation')]
#[Group('test-doubles/mock-object')]
#[Medium]
#[TestDox('createPartialMock()')]
#[DisableReturnValueGenerationForTestDoubles]
final class CreatePartialMockWithDisabledReturnValueGenerationTest extends TestCase
{
    public function testReturnValueGenerationCanBeDisabledWithAttribute(): void
    {
        $double = $this->createPartialMock(ExtendableClass::class, ['doSomething']);

        $this->expectException(ReturnValueNotConfiguredException::class);

        $double->doSomething();
    }
}
