# CMCP orchestration journal

## engine-20260911151414-walleting-3cf49d — iteration 1 baseline

### Scope and repository state

- Target: `Walleting` only; repository mutations must stay inside this repository.
- Remote repository inspected: `smartresponsor/walleting`.
- Current default branch: `task/walleting-ledger-foundation`.
- Product contract: Symfony 8 / PHP 8.4 immutable double-entry PostgreSQL walleting component; owns wallets/accounts, ledger transactions/postings, reservations, funding/withdrawals, instruments, provider events, reconciliation, outbox/inbox, posting health/SLO state, and provider settlement reconciliation.
- Current tree contains no generic CRUD controller implementation (`src/Controller` is placeholder-only), preserving the Cruding ownership boundary.
- Existing release tooling includes Composer validation, PHP-CS-Fixer, PHPStan, PHPUnit and PostgreSQL integration harnesses.

### Reconnaissance read

Target:
- `README.md`
- `composer.json`
- source tree inventory under `src/`
- recent commit history

Mandatory dependency contour:
- Objecting `composer.json` (`objecting/object`, `App\\Objecting\\`)
- Cruding `composer.json` (`cruding/crud`, `App\\Cruding\\`)
- Viewing `composer.json` (`viewing/view`, `App\\Viewing\\`)
- Interfacing `composer.json` (`interfacing/interface`, `App\\Interfacing\\`)

Canonization:
- `README.md`
- `.canonization/Governance/Architecture/Rule/Canon000ComponentPrefixRule.md`
- `.canonization/Governance/Architecture/Rule/Canon008ComposerDependencyIntegrityRule.md`

External maturity baseline:
- Mature ledger products/practices emphasize immutable double entry, atomicity/concurrency control, reconciliation, durable auditability and a single system of record.
- Growth capabilities such as broader provider/connectivity surfaces, programmable transaction DSLs and multi-asset breadth are not RC blockers for this component unless required by an existing contract.

### Target-to-canon mapping

- Canon000: Walleting PHP subjects must use a stable wallet/ledger vocabulary rather than mechanically copying `Walleting` into every type. Existing tree uses wallet, ledger, posting, reservation and provider subjects; any new type in this run must preserve that vocabulary.
- Canon008: a foreign component namespace used by production PHP must have a matching Composer runtime dependency. Conversely, do not invent compile-time dependencies merely because a neighboring repository exists. The target currently declares Objecting; Cruding/Viewing/Interfacing package declarations must be justified by actual production coupling and their host-integration contracts before mutation.
- Host boundary: generic CRUD controllers/routes remain outside Walleting; presentation-shell concerns stay outside the ledger core.

### RC-critical workstream

Harden release acceptance so the repository's documented RC gate cannot omit static analysis that already exists in the repository tooling. Verify the aggregate acceptance path and update factual documentation/journal only where supported by the resulting repository state.

### Growth workstream (non-blocking)

Post-RC evaluation: connector/provider breadth, richer operational views, programmable money-flow authoring and additional multi-asset/product abstractions, while retaining the immutable ledger boundary.

### Material risks / constraints

- Financial-history mutations are high risk; no speculative schema or posting-semantic changes without a failing test or explicit contract gap.
- The execution environment available here can inspect/mutate the GitHub repository but cannot inspect the caller's Windows filesystem symlink state under `D:\\PhpstormProjects\\www`; local path-repository wiring therefore must not be asserted without evidence.
- Do not modify Canonization, Gating, Objecting, Cruding, Viewing, Interfacing or sibling repositories.

### Gates to run/inspect

- `composer validate --no-interaction --strict --check-lock`
- `composer lint`
- PHPStan (`composer stan`)
- `composer test`
- `composer test:integration`
- GitHub commit/check status for the resulting head when available

### Iteration status

Iteration 1 complete: reconnaissance and baseline journal created. Journal creation is not task completion; proceed to a bounded RC hardening implementation and verification.

## Iteration 2 — MATERIAL_IMPLEMENTATION

Status: implemented; continue to verification.

### Canon and runtime findings

- Walleting is canonically a standalone Symfony application for Canon022 detection because both `bin/console` and `config/bundles.php` exist.
- Canon022 therefore requires direct runtime dependencies on `cruding/crud`, `viewing/view`, `interfacing/interface`, `objecting/object`, and `easycorp/easyadmin-bundle`.
- Current `composer.json` declares only `objecting/object` from that platform baseline.
- Canon023 requires local SmartResponsor development packages to use sibling Composer `path` repositories with `options.symlink: true`.
- Canon024 requires a path-independent `composer.prod.json`; Walleting currently has no `composer.prod.json`.
- The missing baseline packages are not already represented in the current `composer.lock`; a lock-consistent Canon022/023 remediation therefore requires a real Composer execution against the shared workspace and must not be hand-edited.

### Material RC hardening completed

- Added the aggregate `composer quality` script.
- `composer quality` runs `cs:check`, `stan`, `lint`, and the PHPUnit unit/test suite in one reproducible non-destructive acceptance path.
- Updated `README.md` so documented RC acceptance now runs `composer validate --no-interaction --strict --check-lock`, `composer quality`, and `composer test:integration`.
- The README now explicitly states that the aggregate quality gate includes PHP-CS-Fixer check mode, PHPStan, Symfony/Doctrine linting, and PHPUnit.
- This closes the Iteration 1 RC-critical gap where static analysis existed in repository tooling but was omitted from the documented release acceptance path.
- No financial schema, posting semantics, provider processing, reconciliation behavior, or foreign component repository was changed.

### Canon029 evidence

- `friendsofphp/php-cs-fixer` is declared in `require-dev`.
- `phpstan/phpstan` is declared in `require-dev`.
- `.php-cs-fixer.php` is repository-visible.
- `phpstan.neon` is repository-visible.
- Composer exposes `cs:check`, `cs:fix`, `stan`, and now the aggregate `quality` path.

### Dependency-topology blocker kept separate

Canon022/023/024 are confirmed RC debt but intentionally not half-patched in this iteration. Editing dependency requirements without regenerating `composer.lock` would make `composer validate --strict --check-lock` fail and would create an internally inconsistent repository. Local sibling symlink topology under `D:\\PhpstormProjects\\www` is also not visible from this execution surface.

Required closure when the real shared Composer workspace is available:

1. Add Cruding, Viewing, Interfacing and EasyAdmin to the development runtime baseline.
2. Add sibling SmartResponsor path repositories with `symlink: true` for locally developed components.
3. Generate/update `composer.lock` through Composer, not by hand.
4. Add a path-independent `composer.prod.json` and its production lock/update workflow.
5. Register only runtime-required bundles according to the repository runtime-scope policy.
6. Run Composer validation plus Gating and the complete Walleting quality/integration suite.

### What we have / what remains

Что имеем? The documented RC gate can no longer silently omit PHPStan or formatting checks; the repository now has one aggregate quality command aligned with Canon029. Canon022/023/024 applicability is proven rather than inferred.

Что осталось? Iteration 3 must verify the actual changed Composer/README surfaces, inspect GitHub status/workflow evidence, run any gates available in the active runtime, and fix findings. The dependency-topology migration remains an explicit RC blocker until a lock-consistent Composer execution can run against the real sibling workspace.
