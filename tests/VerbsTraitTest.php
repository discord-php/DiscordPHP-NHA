<?php

declare(strict_types=1);

use NHA\VerbsTrait;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class VerbsTraitTest extends NHAUnitTestCase
{
    protected function subject(): object
    {
        return new class {
            use VerbsTrait;

            public ?int $agentId = null;
            public ?string $verb = null;
            public array $args = [];

            public function intent(int $agent_id, string $verb, array $args = []): PromiseInterface
            {
                $this->agentId = $agent_id;
                $this->verb = $verb;
                $this->args = $args;

                return resolve(null);
            }
        };
    }

    public function testMoveForwardsDeltaAsArgs(): void
    {
        $subject = $this->subject();
        $subject->move(1, 2, -3);

        $this->assertSame(1, $subject->agentId);
        $this->assertSame('move', $subject->verb);
        $this->assertSame(['dx' => 2, 'dy' => -3], $subject->args);
    }

    public function testSayForwardsText(): void
    {
        $subject = $this->subject();
        $subject->say(1, 'hello');

        $this->assertSame('say', $subject->verb);
        $this->assertSame(['text' => 'hello'], $subject->args);
    }

    public function testContractOmitsNullOptionalArgs(): void
    {
        $subject = $this->subject();
        $subject->contract(1, 10, ['wood' => 5]);

        $this->assertSame('contract', $subject->verb);
        $this->assertSame(['reward' => 10, 'want' => ['wood' => 5]], $subject->args);
    }

    public function testHealWithoutTargetSendsNoArgs(): void
    {
        $subject = $this->subject();
        $subject->heal(1);

        $this->assertSame('heal', $subject->verb);
        $this->assertSame([], $subject->args);
    }

    public function testHealWithTargetSendsTargetArg(): void
    {
        $subject = $this->subject();
        $subject->heal(1, 2);

        $this->assertSame(['target' => 2], $subject->args);
    }

    public function testHealWithItemSendsItemArg(): void
    {
        $subject = $this->subject();
        $subject->heal(1, null, 'medkit');

        $this->assertSame(['item' => 'medkit'], $subject->args);
    }

    public function testDepartSendsDestNotBody(): void
    {
        $subject = $this->subject();
        $subject->depart(1, 'mars');

        $this->assertSame('depart', $subject->verb);
        $this->assertSame(['dest' => 'mars'], $subject->args);
    }

    public function testInvestForwardsModuleAndCredits(): void
    {
        $subject = $this->subject();
        $subject->invest(1, 'truss', 500);

        $this->assertSame('invest', $subject->verb);
        $this->assertSame(['module' => 'truss', 'credits' => 500], $subject->args);
    }

    public function testMineWithResourceIncludesIt(): void
    {
        $subject = $this->subject();
        $subject->mine(1, 3, 'iron');

        $this->assertSame(['n' => 3, 'resource' => 'iron'], $subject->args);
    }

    public function testMineWithoutResourceOmitsIt(): void
    {
        $subject = $this->subject();
        $subject->mine(1, 2);

        $this->assertSame(['n' => 2], $subject->args);
    }

    public function testAttackTargetOnly(): void
    {
        $subject = $this->subject();
        $subject->attack(1, 7);

        $this->assertSame('attack', $subject->verb);
        $this->assertSame(['target' => 7], $subject->args);
    }

    public function testAttackWithWeapon(): void
    {
        $subject = $this->subject();
        $subject->attack(1, 7, 'kinetic_gun');

        $this->assertSame(['target' => 7, 'weapon' => 'kinetic_gun'], $subject->args);
    }

    public function testConstructMergesShapeWithArgs(): void
    {
        $subject = $this->subject();
        $subject->construct(1, 'station', ['module' => 'truss']);

        $this->assertSame('construct', $subject->verb);
        $this->assertSame(['shape' => 'station', 'module' => 'truss'], $subject->args);
    }

    public function testCombineOptionalNameOmittedWhenNull(): void
    {
        $subject = $this->subject();
        $subject->combine(1, ['silicon' => 1, 'copper' => 1]);

        $this->assertSame('combine', $subject->verb);
        $this->assertSame(['ingredients' => ['silicon' => 1, 'copper' => 1]], $subject->args);
    }

    public function testFinalizeWithoutNameSendsNoArgs(): void
    {
        $subject = $this->subject();
        $subject->finalize(1);

        $this->assertSame('finalize', $subject->verb);
        $this->assertSame([], $subject->args);
    }

    public function testMoveToSendsAbsoluteCoords(): void
    {
        $subject = $this->subject();
        $subject->moveTo(1, 30, 118);

        $this->assertSame('move', $subject->verb);
        $this->assertSame(['x' => 30, 'y' => 118], $subject->args);
    }

    public function testStealPartUsesPartArg(): void
    {
        $subject = $this->subject();
        $subject->stealPart(1, 5, 'wing');

        $this->assertSame('steal', $subject->verb);
        $this->assertSame(['from' => 5, 'part' => 'wing'], $subject->args);
    }
}
