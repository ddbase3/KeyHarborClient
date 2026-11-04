<?php declare(strict_types=1);

namespace KeyHarborClient;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;

/**
 * Exposes the reusable KeyHarbor microservice client as a BASE3 plugin.
 */
final class KeyHarborClientPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string {
		return 'keyharborclientplugin';
	}

	public function init() {
		$this->container->set(self::getName(), $this, IContainer::SHARED);
	}
}
