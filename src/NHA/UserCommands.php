<?php

declare(strict_types=1);

namespace NHA;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/**
 * User-scoped NHA command behavior for Discord interactions.
 */
class UserCommands
{
    public function __construct(private readonly NHA $nha, private readonly StateStore $state) {}

    public function login(string $discord_user_id): PromiseInterface
    {
        if ($identity = $this->state->getDiscordUserAgent($discord_user_id)) {
            return \React\Promise\resolve($this->dashboard($discord_user_id, "Agent #{$identity['agent_id']} is ready."));
        }

        $name = 'discord-' . $discord_user_id;

        return $this->nha->registerAgentIdentity($name)->then(function (array $identity) use ($discord_user_id, $name) {
            $this->state->setDiscordUserAgent($discord_user_id, $identity['agent_id'], $name, $identity['token']);

            return $this->dashboard($discord_user_id, "Registered agent #{$identity['agent_id']}. Your identity is saved.");
        });
    }

    public function start(string $discord_user_id): MessageBuilder
    {
        $identity = $this->state->getDiscordUserAgent($discord_user_id);
        $status = $identity ? "Agent #{$identity['agent_id']} is ready." : 'No agent is linked yet. Use Login to create one.';

        return $this->dashboard($discord_user_id, $status);
    }

    public function observe(string $discord_user_id): PromiseInterface
    {
        $identity = $this->identity($discord_user_id);

        return $this->nha->observe($identity['agent_id'])->then(function ($observation) use ($discord_user_id, $identity) {
            $position = (array) ($observation->getPosition() ?? []);
            $positionText = $position === [] ? 'unknown' : sprintf('(%s, %s)', $position['x'] ?? '?', $position['y'] ?? '?');

            return $this->dashboard(
                $discord_user_id,
                "Agent #{$identity['agent_id']} | HP {$observation->getHp()}/{$observation->getMaxHp()} | Position {$positionText}",
            );
        });
    }

    public function move(string $discord_user_id, int $dx, int $dy): PromiseInterface
    {
        return $this->act($discord_user_id, 'move', ['dx' => $dx, 'dy' => $dy]);
    }

    /**
     * Queues a player-owned NHA intent.
     *
     * @param array<string, mixed> $args
     */
    public function act(string $discord_user_id, string $verb, array $args = []): PromiseInterface
    {
        $identity = $this->identity($discord_user_id);

        return $this->nha->intentWithToken($identity['agent_id'], $identity['token'], $verb, $args)->then(
            fn() => $this->dashboard($discord_user_id, "Queued {$verb} for agent #{$identity['agent_id']}. It will resolve on a later tick."),
        );
    }

    /** @return array{agent_id: int, name: string, token: string} */
    private function identity(string $discord_user_id): array
    {
        return $this->state->getDiscordUserAgent($discord_user_id)
            ?? throw new \RuntimeException('No agent is linked to this Discord account. Use /login first.');
    }

    private function dashboard(string $discord_user_id, string $status): MessageBuilder
    {
        $login = Button::success()->setLabel('Login')->setListener(
            fn(Interaction $interaction) => $this->login((string) $interaction->user->id)->then(fn(MessageBuilder $builder) => $interaction->updateMessage($builder)),
            $this->nha,
        );
        $observe = Button::primary()->setLabel('Observe')->setListener(
            fn(Interaction $interaction) => $this->observe((string) $interaction->user->id)->then(fn(MessageBuilder $builder) => $interaction->updateMessage($builder)),
            $this->nha,
        );
        $mine = Button::secondary()->setLabel('Mine')->setListener(
            fn(Interaction $interaction) => $this->act((string) $interaction->user->id, 'mine', ['n' => 1])->then(fn(MessageBuilder $builder) => $interaction->updateMessage($builder)),
            $this->nha,
        );
        $chop = Button::secondary()->setLabel('Chop')->setListener(
            fn(Interaction $interaction) => $this->act((string) $interaction->user->id, 'chop', ['n' => 1])->then(fn(MessageBuilder $builder) => $interaction->updateMessage($builder)),
            $this->nha,
        );
        $gather = Button::secondary()->setLabel('Gather')->setListener(
            fn(Interaction $interaction) => $this->act((string) $interaction->user->id, 'gather', ['n' => 1])->then(fn(MessageBuilder $builder) => $interaction->updateMessage($builder)),
            $this->nha,
        );

        return NHA::createBuilder()->addComponent(Container::new()->addComponents([
            TextDisplay::new("### NHA Agent\n{$status}\n\nUse /login once. The controls below queue one intent; /observe refreshes state. More actions: /move, /plant, /ride, /launch, /land, /dock, /attune, /say, and /tell."),
            ActionRow::new()->addComponents([$login, $observe, $mine, $chop, $gather]),
        ]));
    }
}