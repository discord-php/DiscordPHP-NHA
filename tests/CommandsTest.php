<?php

declare(strict_types=1);

use NHA\Commands;
use NHA\Http\Http;
use NHA\NHA;
use NHA\StateStore;

use function React\Promise\resolve;

class CommandsTest extends NHAUnitTestCase
{
    /** @var list<array{0:string,1:string,2:mixed}> */
    private array $calls = [];

    private string $statePath;

    private StateStore $state;

    protected function setUp(): void
    {
        $this->statePath = sys_get_temp_dir() . '/nha-cmd-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @rmdir(dirname($this->statePath));
    }

    private function commands($getResponse = [], array $intentReply = ['queued_intent' => 42, 'tick' => 5]): Commands
    {
        $this->calls = [];
        $http = $this->getMockBuilder(Http::class)->disableOriginalConstructor()
            ->onlyMethods(['get', 'post'])->getMock();
        $http->method('get')->willReturnCallback(function ($e) use ($getResponse) {
            $this->calls[] = ['get', (string) $e, null];

            return resolve($getResponse);
        });
        $http->method('post')->willReturnCallback(function ($e, $c = null) use ($intentReply) {
            $this->calls[] = ['post', (string) $e, $c];

            return resolve($intentReply);
        });

        $nha = getMockNha();
        (new \ReflectionProperty(NHA::class, 'nha_http'))->setValue($nha, $http);
        $nha->setAgentToken('tok'); // bot.php does this at startup from the stored token

        $this->state = new StateStore($this->statePath);
        $this->state->setDefaultAgent(999, 'tok');

        return new Commands($nha, $this->state);
    }

    private static function render($builder): string
    {
        return json_encode($builder->jsonSerialize());
    }

    public function testQueueVerbSurfacesQueuedIntentId(): void
    {
        $commands = $this->commands();

        $out = null;
        $commands->queueVerb(null, 'mine', ['n' => 3])->then(function ($b) use (&$out) {
            $out = self::render($b);
        });

        $this->assertSame('post', $this->calls[0][0]);
        $this->assertStringContainsString('intent', $this->calls[0][1]);
        $this->assertSame(['agent' => 999, 'verb' => 'mine', 'args' => ['n' => 3], 'token' => 'tok'], $this->calls[0][2]);
        $this->assertStringContainsString('Queued', $out);
        $this->assertStringContainsString('mine', $out);
        $this->assertStringContainsString('intent 42', $out, 'confirmation must expose the queued_intent id');
    }

    public function testIntentStatusRendersOutcome(): void
    {
        $commands = $this->commands(['id' => 42, 'agent' => 999, 'verb' => 'mine', 'status' => 'applied', 'result' => 'mined 3 iron', 'created' => 987654]);

        $out = null;
        $commands->intentStatus(42)->then(function ($b) use (&$out) {
            $out = self::render($b);
        });

        $this->assertStringContainsString('applied', $out);
        $this->assertStringContainsString('mined 3 iron', $out);
        $this->assertStringContainsString('mine', $out);
        // `created` shadows DiscordPHP's Part::$created bool — must show the real tick.
        $this->assertStringContainsString('987654', $out);
        $this->assertStringNotContainsString('tick 1)', $out);
    }

    public function testBoardDepotHitsTheDepotEndpoint(): void
    {
        $commands = $this->commands(['prices' => ['iron' => ['buy' => 2, 'sell' => 1]]]);

        $commands->board('depot');

        $this->assertSame('get', $this->calls[0][0]);
        $this->assertStringContainsString('depot', $this->calls[0][1]);
    }

    public function testBoardRejectsUnknownName(): void
    {
        $commands = $this->commands();

        $err = null;
        $commands->board('nonsense')->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });

        $this->assertInstanceOf(\InvalidArgumentException::class, $err);
        $this->assertStringContainsString('nonsense', $err->getMessage());
    }

    public function testDepartSendsDestArg(): void
    {
        $commands = $this->commands();
        $commands->depart(null, 'venus');

        $this->assertSame(['agent' => 999, 'verb' => 'depart', 'args' => ['dest' => 'venus'], 'token' => 'tok'], $this->calls[0][2]);
    }

    public function testSellBuildsResourceAndAmount(): void
    {
        $commands = $this->commands();
        $commands->sell(null, 'iron', 5);

        $this->assertSame('sell', $this->calls[0][2]['verb']);
        $this->assertSame(['resource' => 'iron', 'n' => 5], $this->calls[0][2]['args']);
    }

    public function testAttackOmitsWeaponWhenNull(): void
    {
        $commands = $this->commands();
        $commands->attack(null, 7);

        $this->assertSame(['target' => 7], $this->calls[0][2]['args']);
    }

    public function testConstructMergesShape(): void
    {
        $commands = $this->commands();
        $commands->construct(null, 'box', ['size' => 8, 'height' => 40]);

        $this->assertSame(['shape' => 'box', 'size' => 8, 'height' => 40], $this->calls[0][2]['args']);
    }

    public function testResolveAgentIdFallsBackToDefault(): void
    {
        $this->assertSame(999, $this->commands()->resolveAgentId(null));
        $this->assertSame(7, $this->commands()->resolveAgentId(7));
    }

    public function testActorDefaultsToInvokingUsersLinkedAgent(): void
    {
        $commands = $this->commands();
        $this->state->setDiscordUserAgent('42', 5, 'user-42', 'utok');

        $actor = $commands->actor('42');

        $this->assertSame(5, $actor->agentId);
        $this->assertSame('utok', $actor->token);
    }

    public function testActorBotOverrideUsesDefaultAgentAndAmbientToken(): void
    {
        $actor = $this->commands()->actor('42', 'bot');

        $this->assertSame(999, $actor->agentId);
        $this->assertNull($actor->token, 'bot actor uses the ambient token, not a stored one');
    }

    public function testActorAcceptsNumericAgentOverride(): void
    {
        $actor = $this->commands()->actor('42', '77');

        $this->assertSame(77, $actor->agentId);
        $this->assertNull($actor->token);
    }

    public function testActorFallsBackToDefaultAgentWhenCallerHasNoLink(): void
    {
        $actor = $this->commands()->actor('nobody');

        $this->assertSame(999, $actor->agentId);
        $this->assertNull($actor->token);
    }

    public function testQueueVerbSendsTheActorsOwnTokenNotTheAmbientOne(): void
    {
        $commands = $this->commands();

        $commands->queueVerb(new \NHA\AgentContext(5, 'utok'), 'mine', ['n' => 1]);

        $this->assertSame(
            ['agent' => 5, 'verb' => 'mine', 'args' => ['n' => 1], 'token' => 'utok'],
            $this->calls[0][2],
        );
    }

    public function testEveryBoardNameMapsToACall(): void
    {
        foreach (Commands::BOARDS as $board) {
            $commands = $this->commands((object) ['deposits' => [], 'ok' => true]);
            $err = null;
            $commands->board($board, ['id' => 1, 'body' => 'mars', 'x' => 1, 'y' => 2])
                ->then(null, function (\Throwable $e) use (&$err) {
                    $err = $e;
                });
            $this->assertNull($err, "board('{$board}') should resolve without error");
            $this->assertNotEmpty($this->calls, "board('{$board}') made no HTTP call");
        }
    }
}
