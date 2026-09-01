# Contributing

Contributions are welcome. Changes should remain focused on OPC packaging; WordprocessingML, SpreadsheetML, and PresentationML domain models belong in specialized packages.

## Development setup

```shell
composer install
composer check
```

`composer check` must pass before a pull request is opened. It validates Composer metadata, checks formatting, runs PHPStan at level `max` with strict rules, and executes the PHPUnit suite.

Use `composer format` to apply the project style. Do not introduce PHPStan baselines or ignored errors instead of fixing the underlying type problem.

## Tests

Add focused unit tests for new behavior and an integration test when a change affects serialized OPC packages. Security-sensitive changes should include a negative test proving that malformed input is rejected before expensive processing.

## Public API

Keep ZIP details inside `Internal`. New public APIs should use OPC vocabulary, remain compatible with PHP 8.1+, and be documented in the README. Backward-incompatible changes require an explicit release decision.
