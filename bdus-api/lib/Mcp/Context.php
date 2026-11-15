<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

use DB\DBInterface;
use Config\Config;
use Auth\Access;
use Monolog\Logger;

/**
 * What a tool needs to do its work: the application's database and
 * configuration, the access of the current principal and the MCP settings.
 * A tool never learns how the principal was authenticated.
 */
class Context
{
    /**
     * @param array{enabled: bool, write: bool, delete: bool, guide: string} $settings
     */
    public function __construct(
        public readonly DBInterface $db,
        public readonly Config $cfg,
        public readonly Access $access,
        public readonly array $settings,
        public readonly ?Logger $log = null,
        /** Key that signs the validation tokens of this application (see Tools\RecordsValidate). */
        public readonly string $signingKey = '',
    ) {}
}
