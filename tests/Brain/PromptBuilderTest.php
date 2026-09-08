<?php

declare(strict_types=1);

use NHA\Brain\PromptBuilder;
use NHA\Parts\AgentObservation;

/**
 * The digest builder split out of {@see \NHA\Brain\AgentBrain}. The bulk of its
 * behaviour is exercised through `AgentBrain::summarize()` in
 * {@see AgentBrainTest}; this pins the standalone static entry point.
 */
class PromptBuilderTest extends NHAUnitTestCase
{
    /**
     * @covers \NHA\Brain\PromptBuilder
     */
    public function testBuildRendersTheSituationDigestStandalone(): void
    {
        $obs = new AgentObservation(142287, [
            'tick' => 5000,
            'position' => [30, 118],
            'hp' => 90, 'hp_max' => 100,
            'altitude' => 0, 'in_space' => false,
            'inventory' => ['wood' => 3, 'metal' => 40, 'credits' => 12],
            'nearby_deposits' => [['resource' => 'wood', 'x' => 30, 'y' => 118, 'amount' => 22]],
        ]);

        $digest = PromptBuilder::build($obs);

        $this->assertStringContainsString('agent #142287 at tick 5000', $digest);
        $this->assertStringContainsString('HP: 90/100', $digest);
        $this->assertStringContainsString('wood@(30,118) x22', $digest);
        $this->assertStringContainsString('Reply with JSON only.', $digest);
    }

    /**
     * @covers \NHA\Brain\PromptBuilder
     */
    public function testBuildFoldsLastTurnOutcomeAndDropsItsFreeTextReason(): void
    {
        $obs = new AgentObservation(142285, [
            'tick' => 5000, 'position' => [1, 1], 'hp' => 100, 'hp_max' => 100,
            'inventory' => ['credits' => 12],
        ]);
        $last = [
            'verb' => 'chop', 'args' => ['n' => 15],
            'reason' => 'I have 9 wood and am standing on a deposit',
            'tick' => 4990,
            'outcome' => ['status' => 'applied', 'result' => 'chopped 1 wood'],
        ];

        $digest = PromptBuilder::build($obs, $last);

        $this->assertStringContainsString('Last turn: you chose chop {"n":15}', $digest);
        $this->assertStringContainsString('It APPLIED', $digest);
        $this->assertStringNotContainsString('I have 9 wood', $digest);
    }
}
