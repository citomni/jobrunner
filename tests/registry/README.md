# Registry suite

Isolated checks for the cfg reads in `src/` against `Registry::CFG_COMMON`. No Composer, no kernel doubles, no database, no environment variables.

```
php tests/registry/run.php
```

Expected: `5 passed, 0 failed`

The suite loads `src/Boot/Registry.php` and scans `src/` with the tokenizer, so commented-out code is ignored. It follows `->cfg->...` chains, also through a local variable assigned from one in the same function, with or without a `??` fallback. `CFG_HTTP` and `CFG_CLI` are empty, so `CFG_COMMON` is the baseline for every class in `src/`.

The package owns a path when its root is in `CFG_COMMON`, which holds only `jobrunner`.

## Cases

- The scan finds known reads, so a change in the code style cannot make it pass blind.
- Cfg is read with property syntax. Array syntax on a Cfg node (`$this->app->cfg['jobrunner']`) fails, because the scan cannot follow it.
- Package-owned cfg is read without fallbacks: no `??`, `isset()`, `empty()` or `?->` on such a read, directly or through a local variable.
- Every package-owned path read in `src/` exists in `CFG_COMMON`.
- Cfg the package does not own, `locale.timezone`, is read with a fallback.

## Not covered

- Reads from arrays: `JobRegistry` reads the handler map from the `toArray()` result of `jobrunner.handlers`.
- A chain ends at a method call (`->toArray()`) and at a dynamic property name. A named function starts a new scope; a closure body keeps the enclosing one. Variables are not followed across functions or through arrays, and a cfg node held in a property is not followed at all.
- A `??` after a closing parenthesis (`($cfg->tables) ?? []`) is not seen, so that read counts as unguarded. `?:` is no guard, since the read still throws on a missing key.
- Every `->cfg` property counts as App cfg.
