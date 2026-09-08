<?php

declare(strict_types=1);

use NHA\HelpGuide;

/**
 * The how-to-play guide split out of {@see \NHA\Commands}. Rendering is also
 * exercised through `Commands::help()` in {@see CommandsTest}; this pins the
 * standalone class.
 */
class HelpGuideTest extends NHAUnitTestCase
{
    private function guide(): HelpGuide
    {
        return new HelpGuide(getMockNha());
    }

    public function testResolveKeyFuzzyMatchesAndFallsBackToGeneral(): void
    {
        $this->assertSame('general', HelpGuide::resolveKey(null));
        $this->assertSame('general', HelpGuide::resolveKey('   '));
        $this->assertSame('combat', HelpGuide::resolveKey('combat'));
        $this->assertSame('economy', HelpGuide::resolveKey('econ'));
        $this->assertSame('gather', HelpGuide::resolveKey('Harvesting'));
        $this->assertSame('general', HelpGuide::resolveKey('nonsense'));
    }

    public function testRenderProducesTheRequestedSectionWithATopicMenu(): void
    {
        $out = json_encode($this->guide()->render('space')->jsonSerialize());

        $this->assertStringContainsString('Space & the Expansion era', $out);
        $this->assertStringContainsString('Jump to a topic', $out, 'the topic select menu is attached');
    }

    public function testEverySectionRenders(): void
    {
        $guide = $this->guide();
        foreach (HelpGuide::SECTIONS as $slug => [, $title]) {
            $out = json_encode($guide->render($slug)->jsonSerialize());
            $this->assertStringContainsString($title, $out, "section {$slug} renders its title");
        }
    }
}
