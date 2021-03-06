<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\EggVariable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\ServerRepositoryInterface;

class EnvironmentService
{
    /**
     * @var array
     */
    private $additional = [];

    /**
     * @var \Illuminate\Contracts\Config\Repository
     */
    private $config;

    /**
     * @var \Pterodactyl\Contracts\Repository\ServerRepositoryInterface
     */
    private $repository;

    /**
     * EnvironmentService constructor.
     *
     * @param \Illuminate\Contracts\Config\Repository $config
     * @param \Pterodactyl\Contracts\Repository\ServerRepositoryInterface $repository
     */
    public function __construct(ConfigRepository $config, ServerRepositoryInterface $repository)
    {
        $this->config = $config;
        $this->repository = $repository;
    }

    /**
     * Dynamically configure additional environment variables to be assigned
     * with a specific server.
     *
     * @param string $key
     * @param callable $closure
     */
    public function setEnvironmentKey(string $key, callable $closure)
    {
        $this->additional[$key] = $closure;
    }

    /**
     * Return the dynamically added additional keys.
     *
     * @return array
     */
    public function getEnvironmentKeys(): array
    {
        return $this->additional;
    }

    /**
     * Take all of the environment variables configured for this server and return
     * them in an easy to process format.
     *
     * @param \Pterodactyl\Models\Server $server
     * @return array
     */
    public function handle(Server $server): array
    {
        $variables = $server->variables->toBase()->mapWithKeys(function (EggVariable $variable) {
            return [$variable->env_variable => $variable->server_value ?? $variable->default_value];
        });

        // Process environment variables defined in this file. This is done first
        // in order to allow run-time and config defined variables to take
        // priority over built-in values.
        foreach ($this->getEnvironmentMappings() as $key => $object) {
            $variables->put($key, object_get($server, $object));
        }

        // Process variables set in the configuration file.
        foreach ($this->config->get('pterodactyl.environment_variables', []) as $key => $object) {
            $variables->put(
                $key, is_callable($object) ? call_user_func($object, $server) : object_get($server, $object)
            );
        }

        // Process dynamically included environment variables.
        foreach ($this->additional as $key => $closure) {
            $variables->put($key, call_user_func($closure, $server));
        }

        return $variables->toArray();
    }

    /**
     * Return a mapping of Panel default environment variables.
     *
     * @return array
     */
    private function getEnvironmentMappings(): array
    {
        return [
            'STARTUP' => 'startup',
            'P_SERVER_LOCATION' => 'location.short',
            'P_SERVER_UUID' => 'uuid',
        ];
    }
}
