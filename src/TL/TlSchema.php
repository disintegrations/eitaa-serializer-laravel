<?php

namespace Disintegrations\EitaaSerializer\TL;

use InvalidArgumentException;
use RuntimeException;

class TlSchema
{
    private array $schema;

    private array $methodsByName = [];

    private array $methodsById = [];

    private array $constructorsById = [];

    private array $constructorsByPredicate = [];

    private array $constructorsByType = [];

    public function __construct(?array $schema = null)
    {
        $this->schema = $schema ?? self::loadDefaultSchema();
        $this->indexSchema();
    }

    public static function fromFile(string $path): self
    {
        return new self(self::readSchemaFile($path));
    }

    public function method(string $schemaName, string $method): array
    {
        $this->assertSchemaName($schemaName);

        if (! isset($this->methodsByName[$schemaName][$method])) {
            throw new InvalidArgumentException("No TL method [{$method}] found in {$schemaName} schema.");
        }

        return $this->methodsByName[$schemaName][$method];
    }

    public function constructorByPredicate(string $schemaName, string $predicate): array
    {
        $constructor = $this->findConstructorByPredicate($schemaName, $predicate);

        if ($constructor === null) {
            throw new InvalidArgumentException("No TL predicate [{$predicate}] found in {$schemaName} schema.");
        }

        return $constructor;
    }

    public function findConstructorByPredicate(string $schemaName, string $predicate): ?array
    {
        $this->assertSchemaName($schemaName);

        return $this->constructorsByPredicate[$schemaName][$predicate] ?? null;
    }

    public function constructorByType(string $schemaName, string $type): ?array
    {
        $this->assertSchemaName($schemaName);

        return $this->constructorsByType[$schemaName][$type] ?? null;
    }

    public function constructorById(string $schemaName, int $id): ?array
    {
        $this->assertSchemaName($schemaName);

        return $this->constructorsById[$schemaName][$id] ?? null;
    }

    public function methodById(string $schemaName, int $id): ?array
    {
        $this->assertSchemaName($schemaName);

        return $this->methodsById[$schemaName][$id] ?? null;
    }

    private static function loadDefaultSchema(): array
    {
        $path = function_exists('config') ? config('eitaa.schema_path') : null;

        if (! is_string($path) || $path === '') {
            $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'eitaa'.DIRECTORY_SEPARATOR.'schema.json';
        }

        return self::readSchemaFile($path);
    }

    private static function readSchemaFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Eitaa TL schema file was not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read Eitaa TL schema file: {$path}");
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function indexSchema(): void
    {
        foreach (['MTProto', 'API'] as $schemaName) {
            $this->methodsByName[$schemaName] = [];
            $this->methodsById[$schemaName] = [];
            $this->constructorsById[$schemaName] = [];
            $this->constructorsByPredicate[$schemaName] = [];
            $this->constructorsByType[$schemaName] = [];

            foreach ($this->schema[$schemaName]['methods'] ?? [] as $method) {
                $this->methodsByName[$schemaName][$method['method']] = $method;
                $this->methodsById[$schemaName][(int) $method['id']] = $method;
            }

            foreach ($this->schema[$schemaName]['constructors'] ?? [] as $constructor) {
                $this->constructorsById[$schemaName][(int) $constructor['id']] = $constructor;

                $predicate = $constructor['predicate'] ?? $constructor['method'] ?? null;
                if (is_string($predicate) && $predicate !== '') {
                    $this->constructorsByPredicate[$schemaName][$predicate] = $constructor;
                }

                if (! isset($this->constructorsByType[$schemaName][$constructor['type']])) {
                    $this->constructorsByType[$schemaName][$constructor['type']] = $constructor;
                }
            }
        }
    }

    private function assertSchemaName(string $schemaName): void
    {
        if (! in_array($schemaName, ['MTProto', 'API'], true)) {
            throw new InvalidArgumentException("Invalid TL schema name [{$schemaName}].");
        }
    }
}

