<?php

declare(strict_types=1);

use NHA\Brain\RejectionClassifier;

class RejectionClassifierTest extends NHAUnitTestCase
{
    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testATimingRaceIsTransientAndNeverGatesARetry(): void
    {
        $this->assertNull(RejectionClassifier::classify('depart', ['dest' => 'deimos'], 'the transfer window is closed — it opens again at tick 1300000'));
        $this->assertNull(RejectionClassifier::classify('combine', [], 'not enough carbon on hand'));
        $this->assertNull(RejectionClassifier::classify('build', ['part' => 'engine'], 'loop detected — cooling down'));
        $this->assertNull(RejectionClassifier::classify('anything', [], ''));
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testAGearlessDepartIsANeedsPartVerdictKeyedToTheDestination(): void
    {
        $hit = RejectionClassifier::classify('depart', ['dest' => 'deimos'], 'this hull has no LANDING GEAR — deimos needs a touchdown');

        $this->assertNotNull($hit);
        $this->assertSame('depart:deimos', $hit['key']);
        $this->assertSame('needs_part', $hit['class']);
        $this->assertSame('', $hit['item']);
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testTheDestinationIsRecoveredFromTheReasonWhenTheFeedCarriesNoArgs(): void
    {
        // The `GET /agent/{id}.recent` feed has no args — only verb + result.
        $hit = RejectionClassifier::classify('depart', [], 'landing gear required before you can depart for phobos');

        $this->assertNotNull($hit);
        $this->assertSame('depart:phobos', $hit['key']);
        $this->assertSame('needs_part', $hit['class']);
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testAThrustToWeightRejectionIsAHullCapabilityCeiling(): void
    {
        $hit = RejectionClassifier::classify('depart', ['dest' => 'venus'], 'thrust/(mass) is 0.72, venus needs 0.90');

        $this->assertNotNull($hit);
        $this->assertSame('depart:venus', $hit['key']);
        $this->assertSame('capability', $hit['class']);
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testAMissingArrivalConsumableIsANeedsItemVerdictNamingTheItem(): void
    {
        $hit = RejectionClassifier::classify('depart', ['dest' => 'venus'], 'you must be carrying an acid_skin for the venus cloud deck');

        $this->assertNotNull($hit);
        $this->assertSame('depart:venus', $hit['key']);
        $this->assertSame('needs_item', $hit['class']);
        $this->assertSame('acid_skin', $hit['item']);
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testABadEnumArgIsKeyedToTheArgumentTheEngineNamed(): void
    {
        $hit = RejectionClassifier::classify('construct', [], 'module must be one of: anchor_truss/cracker/depot/mass_driver');

        $this->assertNotNull($hit);
        $this->assertSame('construct:module', $hit['key']);
        $this->assertSame('needs_enum', $hit['class']);
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testAnUnknownBuildPartIsKeyedToThatPart(): void
    {
        $hit = RejectionClassifier::classify('build', ['part' => 'thruster'], 'unknown part "thruster"');

        $this->assertNotNull($hit);
        $this->assertSame('build:thruster', $hit['key']);
        $this->assertSame('needs_enum', $hit['class']);

        // …but with no part to key on, there is nothing durable to record.
        $this->assertNull(RejectionClassifier::classify('build', [], 'unknown part'));
    }

    /**
     * @covers \NHA\Brain\RejectionClassifier
     */
    public function testADepartRejectionWithNoRecognisableCauseIsNotRecorded(): void
    {
        $this->assertNull(RejectionClassifier::classify('depart', ['dest' => 'deimos'], 'the navigation computer hiccuped'));
        $this->assertNull(RejectionClassifier::classify('depart', ['dest' => 'earth'], 'you are already home'));
    }
}
