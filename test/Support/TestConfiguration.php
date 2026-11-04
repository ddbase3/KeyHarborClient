<?php declare(strict_types=1);

namespace KeyHarborClient\Test\Support;

use Base3\Configuration\Api\IConfiguration;

final class TestConfiguration implements IConfiguration {

	public int $reads = 0;

	public function __construct(public string $token = '') {}

	public function get($configuration = '') { return $configuration === 'remote' ? ['token' => $this->token] : []; }
	public function set($data, $configuration = ''): void {}
	public function save(): void {}
	public function getGroup(string $group, array $default = []): array { return $group === 'remote' ? ['token' => $this->token] : $default; }
	public function getValue(string $group, string $key, $default = null) { return $group === 'remote' && $key === 'token' ? $this->token : $default; }
	public function getString(string $group, string $key, string $default = ''): string {
		if ($group !== 'remote' || $key !== 'token') throw new \RuntimeException('Unexpected test configuration key.');
		$this->reads++;
		return $this->token;
	}
	public function getInt(string $group, string $key, int $default = 0): int { return $default; }
	public function getFloat(string $group, string $key, float $default = 0.0): float { return $default; }
	public function getBool(string $group, string $key, bool $default = false): bool { return $default; }
	public function getArray(string $group, string $key, array $default = []): array { return $default; }
	public function hasGroup(string $group): bool { return $group === 'remote'; }
	public function hasValue(string $group, string $key): bool { return $group === 'remote' && $key === 'token'; }
	public function setValue(string $group, string $key, $value): void {}
	public function setGroup(string $group, array $values, bool $merge = true): void {}
	public function setMany(array $data, bool $merge = true): void {}
	public function removeGroup(string $group): void {}
	public function removeValue(string $group, string $key): void {}
	public function isDirty(): bool { return false; }
	public function saveIfDirty(): bool { return true; }
	public function trySave(): bool { return true; }
	public function reload(): void {}
	public function persistValue(string $group, string $key, $value): bool { return true; }
}
