<?php

declare(strict_types=1);

use NHA\HelperTrait;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;

class HelperTraitTest extends NHAUnitTestCase
{
    protected function subject(): object
    {
        return new class {
            use HelperTrait;
        };
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testCreateBuilderReturnsMessageBuilder(): void
    {
        $builder = $this->subject()::createBuilder();

        $this->assertInstanceOf(MessageBuilder::class, $builder);
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testBarRendersFullBarWhenCurrentEqualsMax(): void
    {
        $result = $this->subject()::bar(10, 10, 10);

        $this->assertSame(str_repeat('█', 10) . ' (10/10)', $result);
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testBarRendersEmptyBarWhenCurrentIsZero(): void
    {
        $result = $this->subject()::bar(0, 10, 10);

        $this->assertSame(str_repeat('░', 10) . ' (0/10)', $result);
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testBarClampsCurrentAboveMax(): void
    {
        $result = $this->subject()::bar(15, 10, 4);

        $this->assertSame(str_repeat('█', 4) . ' (15/10)', $result);
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testBarTreatsNonPositiveMaxAsOne(): void
    {
        $result = $this->subject()::bar(0, 0, 4);

        $this->assertSame(str_repeat('░', 4) . ' (0/1)', $result);
    }

    /**
     * @covers \NHA\HelperTrait
     */
    public function testAttributionComponentsIsASeparatorPlusASubtleRepoAndSponsorLine(): void
    {
        $subject = $this->subject();
        [$separator, $text] = $subject::attributionComponents();

        $this->assertInstanceOf(Separator::class, $separator);
        $this->assertInstanceOf(TextDisplay::class, $text);

        $content = $text->jsonSerialize()['content'] ?? '';
        $this->assertStringStartsWith('-# ', $content, 'rendered as subtle text');
        $this->assertStringContainsString($subject::GITHUB, $content);
        $this->assertStringContainsString($subject::SPONSOR, $content);
    }
}
