<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace NHA;

use React\Promise\PromiseInterface;
use NHA\Http\Endpoint;
use NHA\Http\Http;

/**
 * Typed convenience wrappers over `NHA::intent()` for every verb documented
 * by the world. Each method simply forwards to `intent()` and exists so
 * callers (and IDEs/LLMs) get discoverable, self-documenting signatures.
 */
trait VerbsTrait
{
    /**
     * The extended HTTP client used to talk to the NHA world.
     *
     * @var Http
     */
    protected $nha_http;

    /**
     * Submits an intent (action) for an agent. The action is only applied
     * on the next world tick.
     *
     * @param int    $agent_id
     * @param string $verb
     * @param array  $args
     *
     * @return PromiseInterface
     */
    public function intent(int $agent_id, string $verb, array $args = []): PromiseInterface
    {
        $payload = [
            'agent' => $agent_id,
            'verb' => $verb,
            'args' => $args,
        ];

        if (method_exists($this, 'getAgentToken') && $token = $this->getAgentToken()) {
            $payload['token'] = $token;
        }

        return $this->nha_http->post(Endpoint::INTENT, $payload);
    }

    // --- Move & gather -----------------------------------------------

    public function move(int $agent_id, int $dx, int $dy): PromiseInterface
    {
        return $this->intent($agent_id, 'move', ['dx' => $dx, 'dy' => $dy]);
    }

    public function mine(int $agent_id, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'mine', ['n' => $n]);
    }

    public function chop(int $agent_id, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'chop', ['n' => $n]);
    }

    public function gather(int $agent_id, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'gather', ['n' => $n]);
    }

    public function plantTree(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'plant', []);
    }

    // --- Craft & build -------------------------------------------------

    public function combine(int $agent_id, array $ingredients, string $name): PromiseInterface
    {
        return $this->intent($agent_id, 'combine', ['ingredients' => $ingredients, 'name' => $name]);
    }

    public function build(int $agent_id, string $part, array $with): PromiseInterface
    {
        return $this->intent($agent_id, 'build', ['part' => $part, 'with' => $with]);
    }

    public function finalize(int $agent_id, string $name): PromiseInterface
    {
        return $this->intent($agent_id, 'finalize', ['name' => $name]);
    }

    public function construct(int $agent_id, string $shape, mixed $size, mixed $height, mixed $color): PromiseInterface
    {
        return $this->intent($agent_id, 'construct', ['shape' => $shape, 'size' => $size, 'height' => $height, 'color' => $color]);
    }

    public function ride(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'ride', []);
    }

    public function deploy(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'deploy', []);
    }

    // --- Fly -------------------------------------------------------------

    public function launch(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'launch', []);
    }

    public function land(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'land', []);
    }

    public function dock(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'dock', []);
    }

    // --- Trade -----------------------------------------------------------

    public function sell(int $agent_id, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'sell', ['resource' => $resource, 'n' => $n]);
    }

    public function buy(int $agent_id, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'buy', ['resource' => $resource, 'n' => $n]);
    }

    public function order(int $agent_id, string $side, string $resource, int $qty, float $price): PromiseInterface
    {
        return $this->intent($agent_id, 'order', ['side' => $side, 'resource' => $resource, 'qty' => $qty, 'price' => $price]);
    }

    public function cancelOrder(int $agent_id, mixed $order_id): PromiseInterface
    {
        return $this->intent($agent_id, 'cancel', ['order_id' => $order_id]);
    }

    public function trade(int $agent_id, int $to, array $give, array $want): PromiseInterface
    {
        return $this->intent($agent_id, 'trade', ['to' => $to, 'give' => $give, 'want' => $want]);
    }

    public function acceptTrade(int $agent_id, mixed $trade_id): PromiseInterface
    {
        return $this->intent($agent_id, 'accept', ['trade_id' => $trade_id]);
    }

    // --- Contracts & bounties --------------------------------------------

    public function contract(int $agent_id, mixed $reward, array $want, ?int $to = null, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->intent($agent_id, 'contract', array_filter([
            'reward' => $reward,
            'want' => $want,
            'to' => $to,
            'deadline_ticks' => $deadline_ticks,
        ], fn($v) => null !== $v));
    }

    public function fulfill(int $agent_id, mixed $contract_id): PromiseInterface
    {
        return $this->intent($agent_id, 'fulfill', ['contract_id' => $contract_id]);
    }

    public function revoke(int $agent_id, mixed $contract_id): PromiseInterface
    {
        return $this->intent($agent_id, 'revoke', ['contract_id' => $contract_id]);
    }

    public function bounty(int $agent_id, int $target, mixed $reward, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->intent($agent_id, 'bounty', array_filter([
            'target' => $target,
            'reward' => $reward,
            'deadline_ticks' => $deadline_ticks,
        ], fn($v) => null !== $v));
    }

    // --- Heal --------------------------------------------------------------

    public function heal(int $agent_id, ?int $target = null): PromiseInterface
    {
        return $this->intent($agent_id, 'heal', null === $target ? [] : ['target' => $target]);
    }

    // --- Combat --------------------------------------------------------------

    public function attack(int $agent_id, string $weapon, int $target): PromiseInterface
    {
        return $this->intent($agent_id, 'attack', ['weapon' => $weapon, 'target' => $target]);
    }

    public function arm(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'arm', []);
    }

    public function detonate(int $agent_id, mixed $bomb): PromiseInterface
    {
        return $this->intent($agent_id, 'detonate', ['bomb' => $bomb]);
    }

    public function steal(int $agent_id, int $from, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'steal', ['from' => $from, 'resource' => $resource, 'n' => $n]);
    }

    public function collect(int $agent_id, mixed $loot): PromiseInterface
    {
        return $this->intent($agent_id, 'collect', ['loot' => $loot]);
    }

    // --- Diplomacy --------------------------------------------------------

    public function ally(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'ally', ['to' => $to]);
    }

    public function acceptAlly(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'accept_ally', ['to' => $to]);
    }

    public function unally(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'unally', ['to' => $to]);
    }

    public function declareWar(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'declare_war', ['to' => $to]);
    }

    public function makePeace(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'make_peace', ['to' => $to]);
    }

    public function assist(int $agent_id, int $to, array $give): PromiseInterface
    {
        return $this->intent($agent_id, 'assist', ['to' => $to, 'give' => $give]);
    }

    // --- Ancients --------------------------------------------------------

    public function attune(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'attune', []);
    }

    // --- Talk --------------------------------------------------------------

    public function say(int $agent_id, string $text): PromiseInterface
    {
        return $this->intent($agent_id, 'say', ['text' => $text]);
    }

    public function tell(int $agent_id, int $to, string $text): PromiseInterface
    {
        return $this->intent($agent_id, 'tell', ['to' => $to, 'text' => $text]);
    }

    /**
     * Fetches a read-only endpoint and resolves with the decoded JSON body.
     *
     * @param string|object $endpoint
     *
     * @return PromiseInterface
     */
    public function get(string|object $endpoint): PromiseInterface
    {
        return $this->nha_http->get($endpoint);
    }


}
