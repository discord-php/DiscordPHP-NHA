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

    /** The last raw (pre-encode) POST body, for asserting on JSON shape. */
    private mixed $lastPostBody = null;

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
            $this->lastPostBody = $c;
            // Normalise through the wire encoding so assertions see arrays, not
            // the stdClass we cast `args` to (so `{}` is sent instead of `[]`).
            $this->calls[] = ['post', (string) $e, json_decode(json_encode($c), true)];

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

    /**
     * @covers \NHA\Commands
     */
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

    /**
     * @covers \NHA\Commands
     */
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

    /**
     * @covers \NHA\Commands
     */
    public function testBoardDepotHitsTheDepotEndpoint(): void
    {
        $commands = $this->commands(['prices' => ['iron' => ['buy' => 2, 'sell' => 1]]]);

        $commands->board('depot');

        $this->assertSame('get', $this->calls[0][0]);
        $this->assertStringContainsString('depot', $this->calls[0][1]);
    }

    /**
     * @covers \NHA\Commands
     */
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

    /**
     * @covers \NHA\Commands
     */
    public function testDepartSendsDestArg(): void
    {
        $commands = $this->commands();
        $commands->depart(null, 'venus');

        $this->assertSame(['agent' => 999, 'verb' => 'depart', 'args' => ['dest' => 'venus'], 'token' => 'tok'], $this->calls[0][2]);
    }

    /**
     * @covers \NHA\Commands
     */
    public function testSellBuildsResourceAndAmount(): void
    {
        $commands = $this->commands();
        $commands->sell(null, 'iron', 5);

        $this->assertSame('sell', $this->calls[0][2]['verb']);
        $this->assertSame(['resource' => 'iron', 'n' => 5], $this->calls[0][2]['args']);
    }

    /**
     * @covers \NHA\Commands
     */
    public function testAttackOmitsWeaponWhenNull(): void
    {
        $commands = $this->commands();
        $commands->attack(null, 7);

        $this->assertSame(['target' => 7], $this->calls[0][2]['args']);
    }

    /**
     * @covers \NHA\Commands
     */
    public function testConstructMergesShape(): void
    {
        $commands = $this->commands();
        $commands->construct(null, 'box', ['size' => 8, 'height' => 40]);

        $this->assertSame(['shape' => 'box', 'size' => 8, 'height' => 40], $this->calls[0][2]['args']);
    }

    /**
     * @covers \NHA\Commands
     */
    public function testResolveAgentIdFallsBackToDefault(): void
    {
        $this->assertSame(999, $this->commands()->resolveAgentId(null));
        $this->assertSame(7, $this->commands()->resolveAgentId(7));
    }

    /**
     * @covers \NHA\Commands
     */
    public function testHelpDefaultsToTheGeneralGuideWithATopicMenu(): void
    {
        $out = self::render($this->commands()->help(null));

        $this->assertStringContainsString('How to play', $out);
        $this->assertStringContainsString('The loop', $out);
        // The topic select menu is attached (component type 3 = string select).
        $this->assertStringContainsString('"type":3', $out);
        $this->assertStringContainsString('Command reference', $out, 'every category is offered in the menu');
    }

    /**
     * @covers \NHA\Commands
     */
    public function testHelpRendersARequestedCategory(): void
    {
        $out = self::render($this->commands()->help('space'));

        $this->assertStringContainsString('Expansion era', $out);
        $this->assertStringContainsString('ion_thruster', $out);
        $this->assertStringNotContainsString('The loop', $out, 'it should not fall back to the general guide');
    }

    /**
     * @covers \NHA\Commands
     */
    public function testResolveHelpKeyFuzzyMatchesAndFallsBackToGeneral(): void
    {
        $commands = $this->commands();

        $this->assertSame('general', $commands->resolveHelpKey(null));
        $this->assertSame('general', $commands->resolveHelpKey('   '));
        $this->assertSame('combat', $commands->resolveHelpKey('combat'));
        $this->assertSame('economy', $commands->resolveHelpKey('econ'));
        $this->assertSame('gather', $commands->resolveHelpKey('Harvesting'));
        $this->assertSame('general', $commands->resolveHelpKey('nonsense'));
    }

    /**
     * @covers \NHA\Commands
     */
    public function testEveryHelpCategoryRenders(): void
    {
        $commands = $this->commands();

        foreach (Commands::HELP as $slug => [, $title]) {
            $out = self::render($commands->help($slug));
            $this->assertStringContainsString($title, $out, "help('{$slug}') should render its own section");
        }
    }

    /**
     * @covers \NHA\Commands
     */
    public function testNoArgVerbEncodesArgsAsAnEmptyObjectNotArray(): void
    {
        $commands = $this->commands();
        $commands->plant(null);

        // The NHA API rejects `"args": []` with a 422 (it wants a dict).
        $this->assertStringContainsString('"args":{}', json_encode($this->lastPostBody));
        $this->assertStringNotContainsString('"args":[]', json_encode($this->lastPostBody));
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\ActorTrait
     * @covers \NHA\AgentContext
     */
    public function testActorDefaultsToInvokingUsersLinkedAgent(): void
    {
        $commands = $this->commands();
        $this->state->setDiscordUserAgent('42', 5, 'user-42', 'utok');

        $actor = $commands->actor('42');

        $this->assertSame(5, $actor->agentId);
        $this->assertSame('utok', $actor->token);
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\ActorTrait
     * @covers \NHA\AgentContext
     */
    public function testActorBotOverrideUsesDefaultAgentAndAmbientToken(): void
    {
        $actor = $this->commands()->actor('42', 'bot');

        $this->assertSame(999, $actor->agentId);
        $this->assertNull($actor->token, 'bot actor uses the ambient token, not a stored one');
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\ActorTrait
     * @covers \NHA\AgentContext
     */
    public function testActorAcceptsNumericAgentOverride(): void
    {
        $actor = $this->commands()->actor('42', '77');

        $this->assertSame(77, $actor->agentId);
        $this->assertNull($actor->token);
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\ActorTrait
     * @covers \NHA\AgentContext
     */
    public function testActorFallsBackToDefaultAgentWhenCallerHasNoLink(): void
    {
        $actor = $this->commands()->actor('nobody');

        $this->assertSame(999, $actor->agentId);
        $this->assertNull($actor->token);
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\ActorTrait
     * @covers \NHA\AgentContext
     */
    public function testQueueVerbSendsTheActorsOwnTokenNotTheAmbientOne(): void
    {
        $commands = $this->commands();

        $commands->queueVerb(new \NHA\AgentContext(5, 'utok'), 'mine', ['n' => 1]);

        $this->assertSame(
            ['agent' => 5, 'verb' => 'mine', 'args' => ['n' => 1], 'token' => 'utok'],
            $this->calls[0][2],
        );
    }

    /**
     * @covers \NHA\Commands
     */
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

    /** The name that {@see Commands::register()} actually sent to the API. */
    private function registeredName(Commands $commands, ?string $name, ?string $providerId): string
    {
        $err = null;
        $commands->register($name, 40, 150, $providerId)->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });
        $this->assertNull($err, 'register() rejected: ' . ($err?->getMessage() ?? ''));

        return (string) (json_decode(json_encode($this->lastPostBody), true)['name'] ?? '');
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\NHA
     */
    public function testRegisterNeverProducesABareUserDashName(): void
    {
        // The original bug: /nha register with no `name` option and no provider
        // id passed through as `'user-' . null` === 'user-' (agent #142285).
        $name = $this->registeredName($this->commands([], ['agent_id' => 5001, 'token' => 't']), null, null);

        $this->assertNotSame('user-', $name);
        $this->assertNotSame('', $name);
        $this->assertMatchesRegularExpression('/^agent-[0-9a-f]{8}$/', $name);
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\NHA
     */
    public function testRegisterTreatsAnEmptyOrBlankNameAsUnset(): void
    {
        foreach (['', '   '] as $blank) {
            $name = $this->registeredName($this->commands([], ['agent_id' => 5002, 'token' => 't']), $blank, null);
            $this->assertMatchesRegularExpression('/^agent-[0-9a-f]{8}$/', $name, 'blank name ' . var_export($blank, true));
        }
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\NHA
     */
    public function testRegisterWithAProviderIdNamesTheAgentAfterTheUser(): void
    {
        $name = $this->registeredName($this->commands([], ['agent_id' => 5003, 'token' => 't']), null, '116927250145869826');

        $this->assertSame('user-116927250145869826', $name);
    }

    /**
     * @covers \NHA\Commands
     * @covers \NHA\NHA
     */
    public function testRegisterClampsNameToTwentyFourCharacters(): void
    {
        $name = $this->registeredName($this->commands([], ['agent_id' => 5004, 'token' => 't']), str_repeat('x', 60), null);

        $this->assertSame(24, mb_strlen($name));
    }
}
