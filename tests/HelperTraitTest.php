<?php

declare(strict_types=1);

namespace Tests\NHA;

use Discord\Builders\MessageBuilder;
use NHA\HelperTrait;
use PHPUnit\Framework\TestCase;

class HelperTraitTest extends TestCase
{
    private function subject(): object
    {
        return new class {
            use HelperTrait;
        };
    }

    public function testCreateBuilderReturnsMessageBuilder(): void
    {
        $builder = $this->subject()::createBuilder();

        $this->assertInstanceOf(MessageBuilder::class, $builder);
    }

    public function testBarRendersFullBarWhenCurrentEqualsMax(): void
    {
        $result = $this->subject()::bar(10, 10, 10);

        $this->assertSame(str_repeat('█', 10).' (10/10)', $result);
    }

    public function testBarRendersEmptyBarWhenCurrentIsZero(): void
    {
        $result = $this->subject()::bar(0, 10, 10);

        $this->assertSame(str_repeat('░', 10).' (0/10)', $result);
    }

    public function testBarClampsCurrentAboveMax(): void
    {
        $result = $this->subject()::bar(15, 10, 4);

        $this->assertSame(str_repeat('█', 4).' (15/10)', $result);
    }

    public function testBarTreatsNonPositiveMaxAsOne(): void
    {
        $result = $this->subject()::bar(0, 0, 4);

        $this->assertSame(str_repeat('░', 4).' (0/1)', $result);
    }
}
