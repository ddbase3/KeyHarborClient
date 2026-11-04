<?php declare(strict_types=1);

namespace KeyHarborClient\Test\Support;

interface ITestMicroservice {

	public function getRecords(string $category, int $limit = 5): array;

	public function getRecord(int $id): ?array;

	public function getEnabled(): bool;
}
