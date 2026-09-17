# Copilot Instructions for InternautenB2BOffer

These instructions guide GitHub Copilot when working in this repository. They help keep changes consistent with our project standards, architecture, and delivery workflow.

## Project context

- This is a PrestaShop module with the module folder `InternautenB2BOffer`.
- Target platforms:
  - PrestaShop >= 9.1.4
  - PHP >= 8.3.31
- This repository also serves as a reference example for AI-assisted development.

## Working principles

- Keep changes small, understandable, and purposeful.
- Do not change business logic without clear justification in the PR description or commit message.
- Maintain backward compatibility unless a breaking change is explicitly required.
- Avoid destructive bulk changes such as sweeping formatting updates without a clear benefit.

## Coding standards

- Use `declare(strict_types=1);` in all new PHP files.
- Use clear naming and keep each class or function focused on a single responsibility.
- Prefer explicit types for parameters, return values, and properties where appropriate.
- Keep functions short and easy to test.
- Every new or modified file should include a brief but meaningful file header describing its purpose.
- File-header placement: in PHP files, place it directly below `declare(strict_types=1);`; in other file types, place it at the top of the file.
- The file header should also include a copyright notice for die.internauten.ch GmbH and a brief reference to the MIT license.
- Only add inline comments where the logic would otherwise be difficult to follow.
- Documentation comments and text in Markdown files, including README files, should always be written in English.

Example of a concise, meaningful PHP file header:

```php
// Provides the API endpoints for synchronizing external stock levels.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT
```

## PrestaShop module-specific guidance

- Keep the structure of the `InternautenB2BOffer` module folder consistent.
- Only change hook names, service IDs, configuration keys, and database structures when there is a clear migration strategy.
- Be aware that the module is loaded locally into the PrestaShop environment from `WoWGetPrestaLocal`.
- For validation against the PrestaShop core, the relevant PrestaShop PHP code is located relative to this repository under `../WoWGetPrestaLocal/html`.
- If PrestaShop implementations are needed for analysis, comparison, or compatibility checks, this path should be treated as the primary reference.
- Any user-facing text shown in the back office or on the front end must always go through the translation files instead of being hardcoded in PHP, templates or other source files.
- Keep the translation files for `en`, `de`, `fr`, and `it` aligned whenever new strings or behavior are introduced.
- In the German translation file, use proper German umlauts such as `ä`, `ö`, `ü`, and `ß` instead of ASCII substitutes.

## Quality assurance

- When adding or changing functionality, update the module documentation in `README.md` so new features and behavior are reflected there.
- Keep the module version current whenever a user-visible change, fix, or feature addition is made.
- If a change affects the module behavior, the version should be reviewed and incremented according to the project release convention (`vX.Y.Z`).
- Treat version updates as part of the change when shipping a new feature, fix, or user-facing improvement.

- When changing code, add the most suitable tests or update existing ones.
- If no automated tests are available, document concrete manual test steps.
- Error messages should be clear and useful to developers and must not expose sensitive data.

## Release conventions

- Release tags follow the pattern `vX.Y.Z`.
- Tag creation is done through the script in the `scripts` folder (see README).
- Code changes must not unintentionally break the GitHub Action responsible for releases.

## Copilot behavior in this repository

- Explanations and suggestions should preferably be in German.
- When requirements are unclear, state the assumptions being made before proceeding.
- For larger changes, propose a brief step-by-step plan first, then implement it.
- Keep responses aligned with the repository’s architecture, naming conventions, and module-specific constraints.
