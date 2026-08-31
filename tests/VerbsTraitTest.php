<?php

declare(strict_types=1);

use NHA\VerbsTrait;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class VerbsTraitTest extends NHATestCase
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


}
