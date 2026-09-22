<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PatchesRegions;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoutesGenerator extends BaseGenerator
{
    use PatchesRegions;

    /** Region name wrapping delegation/action routes — see addDelegationRoute()/addActionRoute(). */
    private const CUSTOM_ROUTES_REGION = 'custom-routes';

    /**
     * Region name for hand-written routes that must survive --force
     * verbatim (engine v3.5.17). custom-routes is rebuilt from module.json
     * on every run; anything in it that a developer hand-edited so it no
     * longer matches what module.json currently generates is moved here
     * (with a warning) instead of silently overwritten. NJIWA's Messages
     * console, global Logs page and API-key issue path all had
     * hand-written routes living directly inside custom-routes, believing
     * it was protected — it was not, and one --force would have 404'd all
     * of them. See PatchesRegions and the class docblock on buildContent().
     */
    private const HAND_ROUTES_REGION = 'hand-routes';

    protected array $features;
    protected array $delegations;
    protected array $actions;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features strictly from new payload: features.backend.
        // createSplash / editSplash are opt-in — only included when constants are declared.
        $this->features = [];
        $backendFeatures = $config['features']['backend'] ?? [];
        $hasSplash = !empty($config['constants']);
        foreach (['list', 'create', 'view', 'edit', 'delete', 'createSplash', 'editSplash', 'deleteCheck'] as $featureName) {
            // Skip splash features unless constants are declared
            if (in_array($featureName, ['createSplash', 'editSplash']) && !$hasSplash) {
                continue;
            }
            if (isset($backendFeatures[$featureName]) || ($featureName === 'deleteCheck' && isset($backendFeatures['delete']))) {
                $this->features[$featureName] = [true];
            }
        }

        // Normalized, not raw: the bulk make:module path (this constructor)
        // previously read $config['delegations'] straight off module.json,
        // so a delegation operation with no explicit endpoint.method fell
        // through to this file's own stale inline default in
        // generateDelegationRoutes() instead of DelegationConfigNormalizer::
        // getOperationDefaults()' per-op GET/PUT/DELETE/POST match — every
        // edit/delete route was silently registered as POST. The incremental
        // make:delegation path (addDelegationRoute()) was already unaffected
        // because MakeDelegation.php normalizes before calling it.
        $this->delegations = DelegationConfigNormalizer::normalizeAll($config['delegations'] ?? [], $config);
        $this->actions = $config['actions'] ?? [];
    }

    public function generate(): bool
    {
        $filePath = "{$this->modulePath}/Routes/api.php";

        // Half-present markers (Design rule 6, engine v3.5.17): a hand-edit
        // that deleted one of a region's two marker lines leaves the file in
        // a state neither writeFile() nor the region logic below can safely
        // interpret -- regenerating could duplicate or truncate content.
        // Only checked when we are actually about to read/patch the
        // existing file (force + it exists); a plain non-force run never
        // reaches writeFile()'s own skip-if-exists branch anyway.
        $existingContent = null;
        if ($this->force && is_file($filePath)) {
            $existingContent = file_get_contents($filePath);
            foreach ([self::CUSTOM_ROUTES_REGION, self::HAND_ROUTES_REGION] as $region) {
                if ($this->regionMarkerCount($existingContent, $region) === 1) {
                    PathManager::reportIssue("{$filePath}: region {$region} has only one marker; not regenerating this file");

                    return false;
                }
            }
        }

        return $this->writeFile($filePath, $this->buildContent($existingContent));
    }

    /**
     * Render this module's complete Routes/api.php as a string, without writing it.
     *
     * Split out of generate() so the route surface can be READ rather than
     * re-derived. ApiContractGenerator needs the same URLs the backend actually
     * registers, and every previous attempt to describe them from config alone drifts:
     * `features.backend.*.endpoint` in module.json already disagrees with reality
     * (Statuses declares `PUT /statuses`; this generator emits
     * `PUT /statuses/{uuid}/edit`). Parsing this generator's own output means the
     * contract cannot describe a route the backend does not serve. Called with no
     * argument (ApiContractGenerator's usage), this always returns exactly what a
     * fresh file would contain -- an empty hand-routes region included -- since there
     * is no existing file to read hand-written routes from.
     *
     * $existingContent (engine v3.5.17): the file's current bytes, when regenerating
     * an existing module with --force. When given, implements the hand-routes design:
     * anything in the existing custom-routes region that no longer matches what
     * module.json currently generates (and carries no comment) is migrated into
     * hand-routes with a warning; anything already in hand-routes -- migrated or
     * hand-written -- wins over a freshly generated route with the same verb+path,
     * or (for a delegation/action route only, never a standard CRUD route) the same
     * controller handler. See the class docblock above HAND_ROUTES_REGION and
     * PatchesRegions for the token-level primitives this builds on.
     */
    public function buildContent(?string $existingContent = null): string
    {
        $content = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse {$this->getNamespace()}\\{$this->moduleName}Controller;\n\n//Add Routes here\n\n";

        // Standard feature routes -- one block per feature, kept separate so a hand
        // override colliding with just ONE feature's route never disturbs the rest.
        $standardBlocks = [];
        $processedFeatures = [];
        foreach ($this->features as $feature => $enabled) {
            if (in_array($feature, $processedFeatures, true)) {
                continue;
            }
            $processedFeatures[] = $feature;
            $standardBlocks[] = $this->generateFeatureRoute($feature);
        }

        // Export/import routes, as one block (matches today's exact bytes when
        // nothing about them collides with a hand-written route).
        //
        // No appended "s": $routePath is already the full kebab-cased
        // module name (module names in this project are already plural
        // — Warehouses, Locations, Users), matching every other route
        // this generator emits (e.g. the list route itself: "/{$routePath}/list",
        // Features/list/route.stub). A stray literal "s" here used to
        // register e.g. "/warehousess/list/export" (double s) while the
        // module's own list route was the correct "/warehouses/list" —
        // silently never noticed because nothing had ever turned
        // export/import on for a real module before this was fixed.
        $exportImportBlock = '';
        $listConfig = $this->config['features']['backend']['list'] ?? null;
        if (!empty($listConfig)) {
            $routePath = Str::kebab($this->moduleName);
            $ctrl = "{$this->moduleName}Controller";
            if (!empty($listConfig['export'])) {
                $exportImportBlock .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.list'])->get('/{$routePath}/list/export', [{$ctrl}::class, 'export{$this->moduleName}']);\n\n";
            }
            if (!empty($listConfig['import'])) {
                $exportImportBlock .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->get('/{$routePath}/import/template', [{$ctrl}::class, 'importTemplate{$this->moduleName}']);\n";
                $exportImportBlock .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->post('/{$routePath}/import', [{$ctrl}::class, 'import{$this->moduleName}']);\n\n";
            }
        }

        // Delegation and action routes: what has always lived inside
        // custom-routes, wrapped unconditionally (even when empty) so every
        // freshly generated module already carries the markers
        // make:delegation/make:action need to append a route later via
        // addDelegationRoute()/addActionRoute() — see PatchesRegions. A
        // module generated by an older engine version that predates this
        // region self-heals the markers in on first incremental use instead.
        $customBlock = '';
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customBlock .= $this->generateDelegationRoutes($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customBlock .= $this->generateActionRoutes($actionKey, $action);
        }

        // --- engine v3.5.17: hand-routes migration + hand-wins filtering ---

        $freshSignatures = array_map(
            fn (string $chunk): string => $this->codeSignature($chunk),
            array_merge(
                $this->splitPhpStatements(implode('', $standardBlocks)),
                $this->splitPhpStatements($exportImportBlock),
                $this->splitPhpStatements($customBlock),
            ),
        );

        $existingHandInner = $existingContent !== null ? $this->extractRegion($existingContent, self::HAND_ROUTES_REGION) : null;
        $existingCustomInner = $existingContent !== null ? $this->extractRegion($existingContent, self::CUSTOM_ROUTES_REGION) : null;

        $issues = [];
        $migratedChunks = [];
        if ($existingCustomInner !== null && trim($existingCustomInner) !== '') {
            [$migratedChunks, $migrationWarning] = $this->migrateRouteChunksToHand($existingCustomInner, $freshSignatures);
            if ($migrationWarning !== null) {
                $issues[] = $migrationWarning;
            }
        }

        $handInnerParts = array_values(array_filter(
            array_merge(
                [$this->normalizeRegionText($existingHandInner ?? '')],
                array_map(fn (string $chunk): string => $this->normalizeRegionText($chunk), $migratedChunks),
            ),
            static fn (string $part): bool => $part !== '',
        ));
        $handInner = implode("\n", $handInnerParts);

        [$handByPathVerb, $handByHandler] = $this->indexHandRoutes($handInner);

        [$standardOut, $standardIssues] = $this->filterRouteBlocks($standardBlocks, 'standard', $handByPathVerb, $handByHandler);
        [$exportImportOut, $exportImportIssues] = $this->filterRouteBlock($exportImportBlock, 'exportimport', $handByPathVerb, $handByHandler);
        [$customOut, $customIssues] = $this->filterRouteBlock($customBlock, 'custom', $handByPathVerb, $handByHandler);
        $issues = array_merge($issues, $standardIssues, $exportImportIssues, $customIssues);

        foreach ($issues as $issue) {
            PathManager::reportIssue($issue);
        }

        foreach ($standardOut as $block) {
            $content .= $block . "\n\n";
        }

        $content .= $exportImportOut;

        $customTrimmed = trim($customOut);
        $content .= '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":start]\n"
            . ($customTrimmed !== '' ? $customTrimmed . "\n" : '')
            . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":end]\n"
            . $this->renderRegion(self::HAND_ROUTES_REGION, $handInner, '') . "\n\n";

        // Add Activity History route
        $routePath = Str::kebab($this->moduleName);
        // shelui-engine fork: was hardcoded {uuid} regardless of has_uuid -- see the
        // [[routeKeyParam]] default in BaseGenerator::replacePlaceholders().
        $routeKeyParam = ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id';
        $content .= "Route::middleware(['auth:sanctum'])->get('/{$routePath}/{{$routeKeyParam}}/activity', [{$this->moduleName}Controller::class, 'activityHistory']);\n";

        return $content;
    }

    /**
     * Split an existing custom-routes region's inner text into statement
     * chunks and decide, per chunk, whether module.json still generates it
     * (engine v3.5.17, Design rule 2). A chunk that is uncommented AND
     * whose codeSignature() matches one of today's freshly generated
     * chunks needs no action -- the fresh render below already reproduces
     * it byte-for-byte (modulo formatting). Everything else -- a hand-edit
     * that changed a handler/path so it no longer matches, or a chunk the
     * developer bothered to comment -- is migrated into hand-routes so
     * --force never silently discards it, with one combined warning naming
     * every migrated line.
     *
     * @param list<string> $freshSignatures
     * @return array{0: list<string>, 1: ?string}
     */
    private function migrateRouteChunksToHand(string $existingCustomInner, array $freshSignatures): array
    {
        $migrated = [];
        $labels = [];

        foreach ($this->splitPhpStatements($existingCustomInner) as $chunk) {
            if (!$this->hasComment($chunk) && in_array($this->codeSignature($chunk), $freshSignatures, true)) {
                continue;
            }

            $migrated[] = $chunk;
            $labels[] = $this->routeChunkLabel($chunk);
        }

        if (empty($migrated)) {
            return [[], null];
        }

        $filePath = "{$this->modulePath}/Routes/api.php";
        $warning = "{$filePath}: moved into hand-routes (differs from what module.json generates now): "
            . implode(', ', $labels)
            . '. If one is a stale copy of a delegation/action you removed or changed in module.json, delete it from hand-routes so the change applies.';

        return [$migrated, $warning];
    }

    /**
     * Index the hand-routes region's routes by "VERB path" and by handler,
     * for the hand-wins filter below. Building this once per buildContent()
     * call (rather than per generated chunk) keeps the filter O(n) instead
     * of re-splitting/re-parsing the hand region for every candidate route.
     *
     * @return array{0: array<string, array{parsed: array{verb: string, path: string, handler: string}, signature: string}>, 1: array<string, array{parsed: array{verb: string, path: string, handler: string}, signature: string}>}
     */
    private function indexHandRoutes(string $handInner): array
    {
        $byPathVerb = [];
        $byHandler = [];

        if ($handInner === '') {
            return [$byPathVerb, $byHandler];
        }

        foreach ($this->splitPhpStatements($handInner) as $chunk) {
            $parsed = $this->parseRouteSignature($chunk);
            if ($parsed === null) {
                continue;
            }

            $entry = ['parsed' => $parsed, 'signature' => $this->codeSignature($chunk)];
            $byPathVerb[$parsed['verb'] . ' ' . $parsed['path']] = $entry;
            $byHandler[$parsed['handler']] = $entry;
        }

        return [$byPathVerb, $byHandler];
    }

    /**
     * Apply the hand-wins filter (Design rule 3) to every one of several
     * same-origin blocks (the standard feature routes), dropping a block
     * from the output entirely once every one of its routes was omitted.
     *
     * @param list<string> $blocks
     * @return array{0: list<string>, 1: list<string>}
     */
    private function filterRouteBlocks(array $blocks, string $origin, array $handByPathVerb, array $handByHandler): array
    {
        $out = [];
        $issues = [];

        foreach ($blocks as $block) {
            [$filtered, $blockIssues] = $this->filterRouteBlock($block, $origin, $handByPathVerb, $handByHandler);
            if (trim($filtered) !== '') {
                $out[] = $filtered;
            }
            $issues = array_merge($issues, $blockIssues);
        }

        return [$out, $issues];
    }

    /**
     * Apply the hand-wins filter (Design rule 3) to one block of generated
     * route statements. When nothing in the block collides with a
     * hand-routes entry, the block is returned byte-identical to what was
     * passed in -- this is what keeps a --force run of an untouched module
     * producing exactly today's output (Design rule 5, parity). Only when
     * at least one chunk is omitted does the block get rebuilt from its
     * surviving chunks (Emission bytes: normalize()d, joined by "\n").
     *
     * $origin controls whether the handler-match half of hand-wins applies:
     * only a 'custom' (delegation/action) route may be shadowed by a hand
     * route with a different path but the same handler (the ApiKeys shape)
     * — a standard CRUD route is only ever omitted by an exact verb+path
     * match, so a hand-written alias pointing at, say, the standard
     * listWidgets handler can never delete the real list route.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function filterRouteBlock(string $block, string $origin, array $handByPathVerb, array $handByHandler): array
    {
        if (trim($block) === '') {
            return ['', []];
        }

        $chunks = $this->splitPhpStatements($block);
        $survivors = [];
        $issues = [];
        $omittedAny = false;

        foreach ($chunks as $chunk) {
            [$omitted, $warning] = $this->evaluateRouteHandWins($chunk, $origin, $handByPathVerb, $handByHandler);
            if ($omitted) {
                $omittedAny = true;
                if ($warning !== null) {
                    $issues[] = $warning;
                }
                continue;
            }
            $survivors[] = $chunk;
        }

        if (!$omittedAny) {
            return [$block, []];
        }

        $rejoined = implode("\n", array_map(fn (string $chunk): string => $this->normalizeRegionText($chunk), $survivors));

        return [$rejoined, $issues];
    }

    /**
     * Whether one freshly-generated route chunk is shadowed by a
     * hand-routes entry, and the warning to emit if so (null when the hand
     * copy is byte-for-byte the same route — silently redundant, not worth
     * warning about every run).
     *
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateRouteHandWins(string $chunk, string $origin, array $handByPathVerb, array $handByHandler): array
    {
        $parsed = $this->parseRouteSignature($chunk);
        if ($parsed === null) {
            return [false, null];
        }

        $match = $handByPathVerb[$parsed['verb'] . ' ' . $parsed['path']] ?? null;
        if ($match === null && $origin === 'custom') {
            $match = $handByHandler[$parsed['handler']] ?? null;
        }
        if ($match === null) {
            return [false, null];
        }

        if ($this->codeSignature($chunk) === $match['signature']) {
            return [true, null]; // identical copy -- silent
        }

        $filePath = "{$this->modulePath}/Routes/api.php";
        $handParsed = $match['parsed'];
        $warning = "{$filePath}: hand-routes owns {$handParsed['verb']} {$handParsed['path']} -> {$handParsed['handler']}; "
            . "omitted generated {$parsed['verb']} {$parsed['path']} -> {$parsed['handler']}. "
            . 'Delete the hand-routes copy to use the module.json version.';

        return [true, $warning];
    }

    /**
     * Parse a route-registration chunk's verb, path and controller handler
     * via codeSignature() (so an inline comment inside the chunk never
     * confuses the match) rather than the raw source text. A chunk this
     * regex does not match — a `Route::group()`, a closure handler, a
     * multi-route helper — is simply never used for hand-wins matching or
     * indexing; it is kept verbatim wherever it already lives.
     *
     * @return ?array{verb: string, path: string, handler: string}
     */
    private function parseRouteSignature(string $chunk): ?array
    {
        $signature = $this->codeSignature($chunk);

        if (!preg_match(
            '~(?:->|::)\s*(get|post|put|patch|delete|options|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[[^\]]*?[\'"](\w+)[\'"]\s*\]~i',
            $signature,
            $m,
        )) {
            return null;
        }

        return ['verb' => strtoupper($m[1]), 'path' => $m[2], 'handler' => $m[3]];
    }

    /** Label used in a "moved into hand-routes" warning — see routeLabel format in the plan design. */
    private function routeChunkLabel(string $chunk): string
    {
        $parsed = $this->parseRouteSignature($chunk);
        if ($parsed !== null) {
            return "{$parsed['verb']} {$parsed['path']} -> {$parsed['handler']}";
        }

        $signature = $this->codeSignature($chunk);

        return strlen($signature) > 60 ? substr($signature, 0, 60) : $signature;
    }

    /** normalize($s) from the hand-region Emission bytes spec — strips leading blank lines and trailing whitespace. */
    private function normalizeRegionText(string $s): string
    {
        return rtrim((string) preg_replace('/\A(?:[ \t]*\n)+/', '', $s));
    }

    /**
     * Append one delegation's route(s) to an already-generated module's
     * Routes/api.php, inside the custom-routes region generate() wraps in
     * markers. Additive and idempotent: never touches anything outside the
     * region, never overwrites an existing route, and re-running for the
     * same delegation is a no-op rather than a duplicate.
     *
     * Self-heals a Routes/api.php generated before this region existed —
     * routes files are a flat statement list, so appending an empty region
     * pair at end-of-file is always a safe anchor.
     *
     * @throws \RuntimeException if the module was never generated (no
     *         Routes/api.php to patch).
     */
    public function addDelegationRoute(string $delegationKey, array $delegation): bool
    {
        return $this->addCustomRoute($this->generateDelegationRoutes($delegationKey, $delegation));
    }

    /** @see addDelegationRoute() — identical contract, for actions[] instead. */
    public function addActionRoute(string $actionKey, array $action): bool
    {
        return $this->addCustomRoute($this->generateActionRoutes($actionKey, $action));
    }

    private function addCustomRoute(string $snippet): bool
    {
        // Per-line, not whole-snippet: generateDelegationRoutes()/
        // generateActionRoutes() return one line per enabled operation, and
        // re-running make:delegation/make:action after a delegation/action
        // gains a newly-enabled operation must add only the new line(s) —
        // a whole-snippet match would miss that case (existing has 2 of the
        // now-3 lines, so it never matches) and duplicate the 2 already there.
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($snippet)))));
        if (empty($lines)) {
            return false; // no operations enabled — nothing to route
        }

        $filePath = "{$this->modulePath}/Routes/api.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add a route to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomRoutesRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_ROUTES_REGION) ?? '';

        // Design rule 4 (engine v3.5.17): a line hand-routes already owns —
        // by verb+path, or (delegation/action routes only) by handler — is
        // never appended to custom-routes either. Without this, an
        // incremental make:delegation/make:action after a --force already
        // moved a drifted copy into hand-routes would add the fresh line
        // right back into custom-routes, defeating the whole point of the
        // migration: the route would be registered twice, and Laravel
        // keeps only the last one, so whichever renders second silently wins.
        $handInner = $this->getRegionContent($filePath, self::HAND_ROUTES_REGION) ?? '';
        [$handByPathVerb, $handByHandler] = $this->indexHandRoutes($handInner);

        $newLines = array_filter($lines, function (string $line) use ($existing, $handByPathVerb, $handByHandler): bool {
            if (str_contains($existing, $line)) {
                return false;
            }

            $parsed = $this->parseRouteSignature($line);
            if ($parsed !== null) {
                if (isset($handByPathVerb[$parsed['verb'] . ' ' . $parsed['path']])) {
                    return false;
                }
                if (isset($handByHandler[$parsed['handler']])) {
                    return false;
                }
            }

            return true;
        });
        if (empty($newLines)) {
            return false; // every route in this snippet is already present, or already owned by hand-routes
        }

        $toAppend = implode("\n", $newLines);
        $newContent = trim($existing) === '' ? $toAppend : rtrim($existing) . "\n" . $toAppend;

        return $this->patchRegion($filePath, self::CUSTOM_ROUTES_REGION, rtrim($newContent));
    }

    /**
     * Insert an empty custom-routes region at the end of a Routes/api.php
     * that predates this feature. No-op if the markers already exist.
     */
    private function ensureCustomRoutesRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_ROUTES_REGION) !== null) {
            return;
        }

        $fileContent = rtrim(file_get_contents($filePath));
        $fileContent .= "\n\n" . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":start]\n"
            . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":end]\n";
        file_put_contents($filePath, $fileContent);
    }

    protected function generateFeatureRoute(string $feature): string
    {
        $routeContent = $this->getTemplateContent("Features/{$feature}/route", 'backend');

        $backendConfig = $this->config['features']['backend'][$feature] ?? [];
        
        // Handle deleteCheck correctly
        $routeContent = $this->getTemplateContent("Features/{$feature}/route", 'backend');

        $endpointConfig = $backendConfig['endpoint'] ?? [];

        // Route path should be the module name in kebab case
        $routePath = Str::kebab($this->moduleName);
        $endpointPath = $endpointConfig['path'] ?? "/{$routePath}";

        // For view/edit, if endpoint path already contains {uuid}, don't append it again
        // The template will append /{uuid}/view or /{uuid}/edit
        // So we need to remove {uuid} from endpoint path if it exists
        // if (in_array($feature, ['view', 'edit']) && strpos($endpointPath, '{uuid}') !== false) {
        //     $endpointPath = str_replace('/{uuid}', '', $endpointPath);
        //     $endpointPath = str_replace('{uuid}', '', $endpointPath);
        // }

        if (in_array($feature, ['deleteCheck', 'delete']) ){
            $feature = 'delete';
            $endpointConfig['permission'] = "{$this->moduleName}.{$feature}";
        }

        $routeReplacements = [
            '[[endpointMethod]]' => strtolower($endpointConfig['method'] ?? 'get'),
            '[[endpointPath]]' => $endpointPath,
            '[[endpointPermission]]' => $endpointConfig['permission'] ?? "{$this->moduleName}.{$feature}",
            '[[ModuleName]]' => $this->moduleName,
            '[[moduleName]]' => strtolower($this->moduleName),
        ];

        return $this->replacePlaceholders($routeContent, $routeReplacements);
    }

    protected function generateDelegationRoutes(string $delegationKey, array $delegation): string
    {
        $routes = '';
        $moduleRoute = Str::kebab($this->moduleName);
        $delegationName = $delegation['name'] ?? $delegationKey;
        $delegationRoute = Str::kebab($delegationName);
        $delegationStudly = Str::studly($delegationName);
        // shelui-engine fork: was hardcoded 'uuid' regardless of has_uuid --
        // a has_uuid: false module's delegation routes (e.g. a related-record
        // tab on a legacy-repointed module) 404'd against a parent {uuid}
        // segment the route never actually receives. Mirrors the
        // [[routeKeyParam]] default every standard CRUD route already uses
        // (BaseGenerator::replacePlaceholders()); an explicit parentKey
        // override in module.json still always wins.
        $parentKey = $delegation['parentKey'] ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');
        $operations = $delegation['operations'] ?? [];
        // Permissions reuse the RELATED module's own permission (e.g.
        // "StockMovements.edit"), not a delegation-specific one — see
        // DelegationConfigNormalizer::resolveOperationPermission()'s
        // docblock. Falls back to $delegationStudly only if relatedModule
        // was somehow never normalized (shouldn't happen in practice).
        $relatedModuleName = $delegation['relatedModule']['name'] ?? $delegationStudly;

        // Track method+path combos to detect and warn about collisions
        $registeredRoutes = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $opConfig = $operations[$op];
            $endpoint = $opConfig['endpoint'] ?? [];
            // Matches DelegationConfigNormalizer::getOperationDefaults() exactly —
            // native EditForm/DeleteForm send PUT/DELETE unconditionally, so a
            // caller reaching this method with un-normalized config (endpoint.method
            // unset) must fall back to the same per-op default, not a blanket POST.
            $method = strtolower($endpoint['method'] ?? match ($op) {
                'list', 'view' => 'get',
                'edit' => 'put',
                'delete' => 'delete',
                default => 'post',
            });
            $permission = DelegationConfigNormalizer::resolveOperationPermission(
                $relatedModuleName,
                $op,
                $endpoint
            );

            // Build path: /{module}/{parentKey}/{delegation}/{op} or /{module}/{parentKey}/{delegation}/{itemUuid}/{op}
            if (!empty($endpoint['path'])) {
                $path = $endpoint['path'];
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
            } elseif (in_array($op, ['edit', 'view', 'delete'])) {
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{itemUuid}/{$op}";
            } else {
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{$op}";
            }

            // Warn and skip if another operation already registered the same method+path
            $routeKey = strtoupper($method) . ' ' . $path;
            if (isset($registeredRoutes[$routeKey])) {
                Log::warning("Duplicate delegation route skipped: {$routeKey} — operation '{$op}' has the same method and path as '{$registeredRoutes[$routeKey]}'. Give each operation a unique endpoint path.", [
                    'module' => $this->moduleName,
                    'delegation' => $delegationKey,
                    'operation' => $op,
                ]);
                continue;
            }
            $registeredRoutes[$routeKey] = $op;

            $methodName = $op . $delegationStudly;
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
        }

        // deleteCheck piggybacks on delete being enabled, and reuses delete's own
        // resolved permission — same convention native deleteCheck already uses
        // relative to native delete (see generateRouteContent() above), not a
        // separate deleteCheck permission nothing seeds.
        if (!empty($operations['delete']['enabled'])) {
            $deleteEndpoint = $operations['delete']['endpoint'] ?? [];
            $deletePermission = DelegationConfigNormalizer::resolveOperationPermission(
                $relatedModuleName,
                'delete',
                $deleteEndpoint
            );
            $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{itemUuid}/delete/check";
            $routeKey = 'GET ' . $path;
            if (!isset($registeredRoutes[$routeKey])) {
                $registeredRoutes[$routeKey] = 'deleteCheck';
                $methodName = 'deleteCheck' . $delegationStudly;
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$deletePermission}'])->get('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
        }

        // export/bulk-action/import: nested under list's own backend config
        // (operations.list.backend.{export,bulk_actions,import}), not
        // top-level operation keys — mirrors the standalone module's own
        // "Generate export/import routes if enabled" block above, just
        // delegation-scoped. Export reuses list's own resolved permission
        // (same convention the standalone module's export uses, reusing
        // .list rather than a dedicated permission); bulk-action/import each
        // get their own, matching how standalone already gives those two
        // their own .bulkAction/.import permissions distinct from .list.
        $listOp = $operations['list'] ?? [];
        if (!empty($listOp['enabled'])) {
            $listBackend = $listOp['backend'] ?? [];

            if (!empty($listBackend['export'])) {
                $exportPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'list',
                    $listOp['endpoint'] ?? []
                );
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/list/export";
                $routeKey = 'GET ' . $path;
                if (!isset($registeredRoutes[$routeKey])) {
                    $registeredRoutes[$routeKey] = 'export';
                    $methodName = 'export' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$exportPermission}'])->get('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }

            if (!empty($listBackend['bulk_actions'])) {
                $bulkPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'bulkAction',
                    []
                );
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/bulk-action";
                $routeKey = 'POST ' . $path;
                if (!isset($registeredRoutes[$routeKey])) {
                    $registeredRoutes[$routeKey] = 'bulkAction';
                    $methodName = 'bulkAction' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$bulkPermission}'])->post('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }

            if (!empty($listBackend['import'])) {
                $importPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'import',
                    []
                );
                $templatePath = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/import/template";
                $templateRouteKey = 'GET ' . $templatePath;
                if (!isset($registeredRoutes[$templateRouteKey])) {
                    $registeredRoutes[$templateRouteKey] = 'importTemplate';
                    $methodName = 'importTemplate' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$importPermission}'])->get('{$templatePath}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }

                $importPath = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/import";
                $importRouteKey = 'POST ' . $importPath;
                if (!isset($registeredRoutes[$importRouteKey])) {
                    $registeredRoutes[$importRouteKey] = 'import';
                    $methodName = 'import' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$importPermission}'])->post('{$importPath}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }
        }

        return $routes;
    }

    protected function generateActionRoutes(string $actionKey, array $action): string
    {
        $routes = '';
        $moduleRoute = Str::kebab($this->moduleName);
        $actionName = $action['name'] ?? $actionKey;
        $actionRoute = Str::kebab($actionName);
        $actionStudly = Str::studly($actionName);
        $urlParams = $action['urlParams'] ?? [];
        $operations = $action['operations'] ?? [];

        $urlParamsPath = '';
        if (!empty($urlParams)) {
            $urlParamsPath = '/' . implode('/', array_map(fn($p) => "{{$p}}", $urlParams));
        }

        // Track method+path combos to detect and warn about collisions
        $registeredRoutes = [];

        // Same base ControllerGenerator::generateActionMethods() resolves for its non-splash
        // methods — see plans/038: the splash route used to derive its handler name from
        // $actionStudly alone, ignoring serviceName/methodName, and diverged from whatever
        // name the controller actually declared.
        $serviceNameRaw = $this->resolveActionServiceNameRaw($actionKey, $action);
        $baseMethod = $this->resolveActionBaseMethod($actionKey, $action);

        // Opt-in splash for this action: GET {module}/{uuid}/{action}/splash. Takes the uuid because
        // an action always operates on an existing row and its option lists usually depend on that
        // row's state — unlike create's splash, which has no record yet. Permission is the action's
        // own, so anyone who may run it may load its form.
        if (!empty($action['splash'])) {
            // shelui-engine fork: was hardcoded {uuid} -- see the parentKey
            // fix above in generateDelegationRoutes() for the identical bug.
            $splashRouteKeyParam = ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id';
            $splashPermission = "{$this->moduleName}." . lcfirst($actionStudly);
            $splashMethod = lcfirst($baseMethod);
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$splashPermission}'])"
                . "->get('/{$moduleRoute}/{{$splashRouteKeyParam}}/{$actionRoute}/splash', "
                . "[{$this->moduleName}Controller::class, '{$splashMethod}Splash']);\n";
        }

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $opConfig = $operations[$op];
            $endpoint = $opConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
            // MUST match SeederGenerator, which is what actually creates the
            // permission row, and the frontend's hasPermission() check. This
            // previously defaulted to "{Module}.{StudlyName}.{op}" while the
            // seeder created "{Module}.{name}.execute" — different case AND a
            // different final segment, so the permission the route demanded was
            // never created and every default-configured action 403'd forever.
            // The canonical form is "{Module}.{actionName}", the same shape as
            // the CRUD permissions (Users.create) and the hand-written
            // Users.resendInvitation the UI already checks — which means
            // lcfirst(), not the raw StudlyCase $actionName ("ForceResetPassword"):
            // every other permission in this codebase is camelCase after the
            // dot, and this comment's own claim to match Users.resendInvitation
            // was false until this fix (found + fixed 2026-08-06, alongside the
            // identical bug in SeederGenerator::generatePermissions() and
            // ViewModalGenerator::buildActionReplacements() — all three built the
            // same string independently, so they stayed self-consistent with each
            // other, but jointly violated the app's own naming convention).
            //
            // !empty(), not ?? — ActionConfigNormalizer always sets
            // permission to '' (never null), so ?? never actually falls
            // back: every action route shipped with permission:'', an empty
            // permission gate rather than the canonical form above.
            $permission = !empty($endpoint['permission']) ? $endpoint['permission'] : "{$this->moduleName}." . lcfirst($actionName);

            if (!empty($endpoint['path'])) {
                $path = $endpoint['path'];
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
            } else {
                $path = "/{$moduleRoute}/{$actionRoute}{$urlParamsPath}/{$op}";
            }

            // Warn and skip if another operation already registered the same method+path
            $routeKey = strtoupper($method) . ' ' . $path;
            if (isset($registeredRoutes[$routeKey])) {
                Log::warning("Duplicate action route skipped: {$routeKey} — operation '{$op}' has the same method and path as '{$registeredRoutes[$routeKey]}'. Give each operation a unique endpoint path.", [
                    'module' => $this->moduleName,
                    'action' => $actionKey,
                    'operation' => $op,
                ]);
                continue;
            }
            $registeredRoutes[$routeKey] = $op;

            $methodName = $op === 'list' ? lcfirst($baseMethod) : $op . ucfirst($baseMethod);

            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
        }

        return $routes;
    }

    /** @deprecated Use generateDelegationRoutes or generateActionRoutes */
    protected function generateCustomFeatureRoutes(string $featureKey, array $customFeature): string
    {
        $routes = '';
        $featureName = strtolower($customFeature['name'] ?? $featureKey);
        $moduleNameLower = strtolower($this->moduleName);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        
        // Bare endpoint: Support multiple operations (list, create, view, edit, delete)
        if ($displayType === 'bare-endpoint') {
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            $urlParams = $customFeature['urlParams'] ?? [];
            
            // Generate routes for each enabled operation
            foreach (['list', 'create', 'view', 'edit', 'delete'] as $op) {
                $featureConfig = $backendFeatures[$op] ?? null;
                if (!$featureConfig || !($featureConfig['enabled'] ?? false)) continue;
                
                $endpoint = $featureConfig['endpoint'] ?? [];
                $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.{$op}";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix from the path
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Build path with URL params
                $pathParts = [];
                if (!empty($urlParams)) {
                    foreach ($urlParams as $param) {
                        $pathParts[] = "{{$param}}";
                    }
                }
                
                // Add item UUID for view/edit/delete operations
                if (in_array($op, ['view', 'edit', 'delete'])) {
                    $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                    $pathParts[] = "{{$itemKey}}";
                }
                
                // Construct full path with operation suffix
                $path = $basePath;
                if (!empty($pathParts)) {
                    $path .= '/' . implode('/', $pathParts);
                }
                $path .= '/' . $op;
                
                // Generate method name
                $methodName = $customFeature['methodName'] ?? '';
                if (empty($methodName)) {
                    // Default method naming: operation + FeatureName (e.g., listExportData, createExportData)
                    $methodName = $op . ucfirst($customFeature['name'] ?? $featureKey);
                } elseif ($op !== 'list') {
                    // For non-list operations, append operation name if methodName is provided
                    $methodName = $op . ucfirst($methodName);
                }
                
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
            return $routes;
        }
        
        // Tab action: Nested routes with parent context
        if ($displayType === 'tab-action') {
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            
            foreach (['list', 'create', 'view', 'edit', 'delete'] as $op) {
                $featureConfig = $backendFeatures[$op] ?? null;
                if (!$featureConfig || !($featureConfig['enabled'] ?? false)) continue;
                
                $endpoint = $featureConfig['endpoint'] ?? [];
                $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.{$op}";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix from the path (e.g., /list, /create, etc.)
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Build the path with operation suffix
                if (in_array($op, ['view', 'edit', 'delete'])) {
                    // For view/edit/delete, need to add the related UUID parameter before the operation
                    $relatedUuidParam = ($customFeature['name'] ?? $featureKey) . '_id';
                    // Check if path already has a parameter after the feature name
                    if (!preg_match('/\/\{[^}]+\}(\/|$)/', $basePath)) {
                        // Add the related UUID parameter
                        $basePath .= '/{' . $relatedUuidParam . '}';
                    }
                    $path = $basePath . '/' . $op;
                } else {
                    // For list and create, just append the operation
                    $path = $basePath . '/' . $op;
                }
                
                $methodName = $op . ucfirst($customFeature['name'] ?? $featureKey);
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
            
            // Generate splash route for tab-action if create is enabled
            $createConfig = $backendFeatures['create'] ?? null;
            if ($createConfig && ($createConfig['enabled'] ?? false)) {
                $endpoint = $createConfig['endpoint'] ?? [];
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.create";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix and ensure we have the base path
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Splash route: {basePath}/create/splash
                $splashMethodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->get('{$basePath}/create/splash', [{$this->moduleName}Controller::class, '{$splashMethodName}']);\n";
            }
            
            return $routes;
        }
        
        // Header action: Use endpoint path from config if available
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $createConfig = $backendFeatures['create'] ?? null;
        if ($createConfig && ($createConfig['enabled'] ?? false)) {
            $endpoint = $createConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? 'post');
            $featureNameLower = strtolower($customFeature['name'] ?? $featureKey);
            $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{uuid}/{$featureNameLower}";
            $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}";
            
            // Ensure path has leading slash
            if (!str_starts_with($basePath, '/')) {
                $basePath = '/' . $basePath;
            }
            
            // Remove any existing operation suffix from the path
            $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
            $basePath = rtrim($basePath, '/');
            
            // Append /create to the path
            $path = $basePath . '/create';
            
            $methodName = $customFeature['methodName'] ?? 'handle' . ucfirst($customFeature['name'] ?? $featureKey);
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            
            // Generate splash route for header-action if create is enabled
            // Splash route: {basePath}/create/splash (e.g., /test-products/{uuid}/quick-actions/create/splash)
            $splashPermission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}";
            $splashMethodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$splashPermission}'])->get('{$basePath}/create/splash', [{$this->moduleName}Controller::class, '{$splashMethodName}']);\n";
        } else {
            // Fallback to default route with /create suffix
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.{$featureName}'])->post('/{$moduleNameLower}/{uuid}/{$featureName}/create', [{$this->moduleName}Controller::class, 'handle" . ucfirst($customFeature['name'] ?? $featureKey) . "']);\n";
        }
        
        return $routes;
    }
}

