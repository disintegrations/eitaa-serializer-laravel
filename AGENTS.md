# Repository Guidelines

## Project Structure & Module Organization

This package provides a Laravel client for Eitaa TL serialization and gateway requests. `src/` contains the client, service provider, and facade; `src/TL/` contains schema loading, serialization, and deserialization. Classes use the PSR-4 namespace `Disintegrations\EitaaSerializer`.

`config/eitaa.php` defines package defaults, and `resources/eitaa/schema.json` supplies the bundled TL schema. `tests/Unit/` covers local behavior; `tests/Integration/` exercises the live gateway. Shared test setup lives in `tests/Pest.php` and `tests/TestCase.php`. Usage documentation is in `README.md` and `docs/index.html`; reusable skill instructions live in `SKILL.md` and `resources/boost/skills/`.

## Build, Test, and Development Commands

Use PHP 8.3 or newer with JSON and zlib extensions. This is a library, so there is no standalone application server or frontend build.

- `composer install`: install runtime and development dependencies.
- `composer validate --strict`: validate package metadata, matching CI.
- `composer test`: run Pest; live integration tests skip unless enabled.
- `vendor/bin/pest --exclude-group=integration`: run local tests only.
- `EITAA_RUN_INTEGRATION=1 composer test:integration`: call the live gateway's no-auth help methods.
- `find src config tests -name '*.php' -print0 | xargs -0 -n1 php -l`: check PHP syntax.

## Coding Style & Naming Conventions

Follow existing PHP formatting: four-space indentation, class and method braces on separate lines, typed properties, and explicit parameter and return types where practical. Use PascalCase class names matching filenames and camelCase methods and variables. Preserve schema-defined TL predicates and field names exactly. No formatter or static-analysis tool is configured.

## Testing Guidelines

Tests use Pest with Orchestra Testbench. Name files `*Test.php` and write descriptive `it('...')` cases. Add focused regressions for changed encoding, decoding, schema, or gateway behavior; keep unit tests independent of network access. No minimum coverage threshold is configured. CI tests PHP 8.4 and 8.5 and runs live tests when the gateway is reachable.

## Commit & Pull Request Guidelines

History uses short imperative subjects such as `Update README.md` and `Create phpunit.xml.dist`; follow that style. PRs should describe the behavior changed, include validation commands and results, and link relevant issues. Update usage documentation when public APIs or configuration change. Keep tokens and local environment files out of commits.
