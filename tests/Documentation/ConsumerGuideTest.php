<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Documentation;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Contracts\PublicApiSurfaceTest;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Keeps `README.md` and `docs/CONSUMER_GUIDE.md` honest.
 *
 * ADR 0014 increment 8 writes consumer documentation against a corrected
 * surface, and section 16 forbids listing an unimplemented API as available.
 * Prose is free to change; these promises are not:
 *
 * 1. every GateTrail class the documents name exists;
 * 2. no explicitly unavailable API is presented outside its own
 *    "not implemented" section;
 * 3. examples depend on contracts, never on `Default*` concretes;
 * 4. examples never name a Tier 4 `@internal` symbol;
 * 5. every named argument matches a real reflected parameter list;
 * 6. every PHP example parses;
 * 7. the documented first flow actually runs.
 */
final class ConsumerGuideTest extends TestCase
{
    private const README = 'README.md';

    private const GUIDE = 'docs/CONSUMER_GUIDE.md';

    private const PUBLIC_API = 'docs/PUBLIC_API.md';

    /**
     * Tokens for surfaces ADR 0014 section 16 requires to stay labelled as
     * future work. Each may appear only inside the document's own
     * unimplemented section.
     *
     * @var list<string>
     */
    private const UNAVAILABLE_TOKENS = [
        'UnauthorizedMutation',
        'gatetrail:doctor',
        'gatetrail:cache-clear',
        'wildcard',
        'Wildcard',
        'hierarchy',
        'tenant',
        'Tenant',
        'cross-request',
        'administration UI',
        'Administration UI',
    ];

    /** @var array<string, string> */
    private const UNAVAILABLE_SECTION = [
        self::README => '## Not yet implemented',
        self::GUIDE => '## 20. Current limitations and unimplemented features',
    ];

    /** @var list<class-string> */
    private const DOCUMENTED_CONTRACTS = [
        AssignmentManager::class,
        RoleManager::class,
        PermissionManager::class,
        PermissionResolver::class,
        RoleResolver::class,
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield self::README => [self::README];
        yield self::GUIDE => [self::GUIDE];
    }

    #[DataProvider('documents')]
    public function test_every_named_gatetrail_symbol_exists(string $document): void
    {
        $names = $this->referencedSymbols($this->read($document));

        $this->assertNotSame([], $names, $document.' names no GateTrail symbol.');

        foreach ($names as $name) {
            $this->assertTrue(
                class_exists($name)
                    || interface_exists($name)
                    || trait_exists($name)
                    || enum_exists($name),
                sprintf('%s presents %s, which does not exist.', $document, $name),
            );
        }
    }

    #[DataProvider('documents')]
    public function test_unavailable_api_is_confined_to_the_unimplemented_section(
        string $document,
    ): void {
        $contents = $this->read($document);
        $heading = self::UNAVAILABLE_SECTION[$document];
        $start = strpos($contents, $heading);

        $this->assertIsInt(
            $start,
            sprintf('%s must carry the section %s.', $document, $heading),
        );

        $end = strpos($contents, "\n## ", $start + 1);
        $end = $end === false ? strlen($contents) : $end;
        $outside = substr($contents, 0, $start).substr($contents, $end);

        foreach (self::UNAVAILABLE_TOKENS as $token) {
            $this->assertStringNotContainsString(
                $token,
                $outside,
                sprintf(
                    '%s mentions the unimplemented surface "%s" outside %s.',
                    $document,
                    $token,
                    $heading,
                ),
            );
        }
    }

    #[DataProvider('documents')]
    public function test_examples_use_contracts_rather_than_default_concretes(
        string $document,
    ): void {
        $examples = implode("\n", $this->phpExamples($this->read($document)));

        foreach ([
            'DefaultAssignmentManager',
            'DefaultRoleManager',
            'DefaultPermissionManager',
            'DefaultPermissionResolver',
            'DefaultRoleResolver',
        ] as $concrete) {
            $this->assertStringNotContainsString(
                $concrete,
                $examples,
                sprintf('%s builds an example on the concrete %s.', $document, $concrete),
            );
        }

        $used = array_filter(
            self::DOCUMENTED_CONTRACTS,
            static fn (string $contract): bool => str_contains(
                $examples,
                (string) strrchr($contract, '\\'),
            ),
        );

        $this->assertNotSame(
            [],
            $used,
            $document.' must show at least one public contract in an example.',
        );
    }

    #[DataProvider('documents')]
    public function test_examples_never_depend_on_internal_symbols(string $document): void
    {
        $examples = implode("\n", $this->phpExamples($this->read($document)));

        foreach ($this->internalShortNames() as $symbol => $short) {
            $this->assertSame(
                0,
                preg_match('/\b'.preg_quote($short, '/').'\b/', $examples),
                sprintf(
                    '%s uses the @internal symbol %s in an example.',
                    $document,
                    $symbol,
                ),
            );
        }
    }

    public function test_internal_example_guard_covers_every_tier_four_symbol(): void
    {
        $this->assertCount(181, $this->internalShortNames());
    }

    #[DataProvider('documents')]
    public function test_every_named_argument_matches_reflection(string $document): void
    {
        $calls = $this->namedArgumentCalls($this->read($document));
        $signatures = $this->publicSignatures();
        $checked = 0;

        foreach ($calls as [$method, $arguments]) {
            if (! isset($signatures[$method])) {
                continue;
            }

            $checked++;
            $this->assertTrue(
                $this->matchesAnySignature($signatures[$method], $arguments),
                sprintf(
                    '%s calls %s(%s), which matches no accepted signature.',
                    $document,
                    $method,
                    implode(', ', $arguments),
                ),
            );
        }

        $this->assertGreaterThan(
            0,
            $checked,
            $document.' must show at least one named-argument public call.',
        );
    }

    #[DataProvider('documents')]
    public function test_every_php_example_parses(string $document): void
    {
        $examples = $this->phpExamples($this->read($document));

        $this->assertNotSame([], $examples, $document.' shows no PHP example.');

        foreach ($examples as $index => $example) {
            $this->assertTrue(
                $this->parses($this->asFile($example))
                    || $this->parses($this->asClassBody($example)),
                sprintf(
                    "%s example #%d does not parse:\n%s",
                    $document,
                    $index + 1,
                    $example,
                ),
            );
        }
    }

    public function test_the_documents_link_to_each_other_and_to_the_public_api(): void
    {
        $readme = $this->read(self::README);

        foreach ([
            'docs/CONSUMER_GUIDE.md',
            'docs/PUBLIC_API.md',
            'PACKAGE_SPEC.md',
            'ROADMAP.md',
            'CHANGELOG.md',
            'LICENSE',
        ] as $target) {
            $this->assertStringContainsString(
                '('.$target.')',
                $readme,
                'README.md must link to '.$target.'.',
            );
            $this->assertFileExists($this->path($target));
        }

        $guide = $this->read(self::GUIDE);

        $this->assertStringContainsString('(PUBLIC_API.md)', $guide);
        $this->assertStringContainsString(
            '(decisions/0014-pre-v1-public-api-boundary.md)',
            $guide,
        );
        $this->assertFileExists($this->path('docs/PUBLIC_API.md'));
        $this->assertFileExists(
            $this->path('docs/decisions/0014-pre-v1-public-api-boundary.md'),
        );
    }

    public function test_consumer_documents_state_the_frozen_pre_v1_status(): void
    {
        foreach ([self::README, self::GUIDE, self::PUBLIC_API] as $document) {
            $contents = $this->read($document);

            $this->assertMatchesRegularExpression(
                '/pre-v1 (?:public\s+)?API is (?:\*\*)?frozen/i',
                $contents,
                $document.' must state that the documented pre-v1 public API is frozen.',
            );
            $this->assertStringContainsString(
                '@internal',
                $contents,
                $document.' must warn that @internal symbols are not consumer API.',
            );
        }
    }

    public function test_the_documented_first_flow_runs_against_the_package_test_environment(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        $user = TestSubject::query()->create(['name' => 'Reviewer']);
        $actor = SystemActor::named('access-bootstrap');

        $role = $this->app->make(RoleManager::class)->create(
            name: 'content-reviewer',
            guard: 'web',
            actor: $actor,
            reason: 'Create the document review role',
            description: 'Reviews and approves submitted documents',
        );

        $permission = $this->app->make(PermissionManager::class)->create(
            name: 'documents.approve',
            guard: 'web',
            actor: $actor,
            reason: 'Create the document approval permission',
        );

        $this->assertSame(MutationOutcome::Created, $role->outcome);
        $this->assertSame(MutationOutcome::Created, $permission->outcome);

        $assignments = $this->app->make(AssignmentManager::class);

        $assignments->grantPermissionToRole(
            role: 'content-reviewer',
            permission: 'documents.approve',
            actor: $actor,
            reason: 'Reviewers approve submitted documents',
        );

        $assignment = $assignments->assignRole(
            subject: $user,
            role: 'content-reviewer',
            actor: $actor,
            reason: 'Joined the document review rota',
        );

        $this->assertSame(MutationOutcome::Created, $assignment->outcome);
        $this->assertTrue($assignment->changed());

        $this->assertTrue(
            $this->app->make(PermissionResolver::class)->allows(
                subject: $user,
                permission: 'documents.approve',
            ),
        );
        $this->assertTrue(
            $this->app->make(RoleResolver::class)->has(
                subject: $user,
                role: 'content-reviewer',
            ),
        );
        $this->assertSame(
            ['documents.approve'],
            $this->app->make(PermissionResolver::class)
                ->effectivePermissions(subject: $user)
                ->map(static fn (object $permission): string => (string) $permission->name)
                ->all(),
        );

        $key = $assignment->resource->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        $assignments->revokeRole(
            assignment: AssignmentReference::id($key),
            actor: $actor,
            reason: 'Left the document review rota',
        );

        $this->app->forgetScopedInstances();

        $this->assertFalse(
            $this->app->make(PermissionResolver::class)->allows(
                subject: $user,
                permission: 'documents.approve',
            ),
        );
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));

        $this->assertIsString($contents, $relative.' must be readable.');

        return $contents;
    }

    /**
     * Fully qualified GateTrail names, plus the sub-namespace short forms the
     * prose uses, such as `Contracts\GateTrailEvent`.
     *
     * @return list<class-string>
     */
    private function referencedSymbols(string $contents): array
    {
        preg_match_all(
            '/HangTuah(?:\\\\{1,2}GateTrail)(?:\\\\{1,2}[A-Za-z][A-Za-z0-9]*)+/',
            $contents,
            $qualified,
        );
        preg_match_all(
            '/(?<![A-Za-z0-9\\\\])(?:Contracts|Events|Exceptions|Models|Access|Concerns|Audit|Mutations|Guards|Database)(?:\\\\{1,2}[A-Za-z][A-Za-z0-9]*)+/',
            $contents,
            $relative,
        );

        $names = [];

        foreach ($qualified[0] as $name) {
            $names[] = str_replace('\\\\', '\\', $name);
        }

        foreach ($relative[0] as $name) {
            $names[] = 'HangTuah\\GateTrail\\'.str_replace('\\\\', '\\', $name);
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function phpExamples(string $contents): array
    {
        preg_match_all('/^```php\n(.*?)^```$/ms', $contents, $blocks);

        /** @var list<string> $examples */
        $examples = $blocks[1];

        return $examples;
    }

    private function parses(string $code): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'gatetrail-doc-');

        if ($file === false) {
            $this->fail('A temporary file is required to lint a documentation example.');
        }

        file_put_contents($file, $code);
        exec(
            sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)),
            $output,
            $status,
        );
        unlink($file);

        return $status === 0;
    }

    /**
     * A method-body example is valid PHP inside a class, so it is re-linted
     * that way before being rejected.
     */
    private function asClassBody(string $example): string
    {
        $imports = [];
        $body = [];

        foreach (explode("\n", $example) as $line) {
            if (preg_match('/^use [A-Za-z\\\\]+(?: as [A-Za-z]+)?;$/', $line) === 1) {
                $imports[] = $line;

                continue;
            }

            $body[] = $line;
        }

        return "<?php\n\n".implode("\n", $imports)
            ."\n\nfinal class GateTrailDocumentationExample\n{\n"
            .implode("\n", $body)."\n}\n";
    }

    private function asFile(string $example): string
    {
        return "<?php\n\n".$example;
    }

    /**
     * Every multi-line call written in the documented named-argument style,
     * as `[method, [argument names in written order]]`.
     *
     * @return list<array{string, list<string>}>
     */
    private function namedArgumentCalls(string $contents): array
    {
        $calls = [];

        foreach ($this->phpExamples($contents) as $example) {
            $lines = explode("\n", $example);

            foreach ($lines as $index => $line) {
                if (preg_match('/(?:->|::)([A-Za-z][A-Za-z0-9]*)\($/', $line, $match) !== 1) {
                    continue;
                }

                $arguments = $this->argumentNames($lines, $index);

                if ($arguments === []) {
                    continue;
                }

                $calls[] = [$match[1], $arguments];
            }
        }

        return $calls;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function argumentNames(array $lines, int $openedAt): array
    {
        $depth = $this->depthDelta($lines[$openedAt]);
        $names = [];
        $index = $openedAt + 1;

        while ($depth > 0 && isset($lines[$index])) {
            if (
                $depth === 1
                && preg_match('/^\s*([a-z][A-Za-z0-9]*):\s/', $lines[$index], $match) === 1
            ) {
                $names[] = $match[1];
            }

            $depth += $this->depthDelta($lines[$index]);
            $index++;
        }

        return $names;
    }

    private function depthDelta(string $line): int
    {
        return substr_count($line, '(') - substr_count($line, ')');
    }

    /**
     * Public method signatures of every Tier 1 symbol, keyed by method name.
     * `create()` exists on two managers, so a name maps to a list.
     *
     * @return array<string, list<array{parameters: list<string>, required: list<string>}>>
     */
    private function publicSignatures(): array
    {
        $signatures = [];

        foreach (PublicApiSurfaceTest::TIER_ONE as $symbol) {
            $reflection = new ReflectionClass($symbol);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $symbol) {
                    continue;
                }

                $parameters = [];
                $required = [];

                foreach ($method->getParameters() as $parameter) {
                    $parameters[] = $parameter->getName();

                    if (! $parameter->isOptional()) {
                        $required[] = $parameter->getName();
                    }
                }

                if ($parameters === []) {
                    continue;
                }

                $signatures[$method->getName()][] = [
                    'parameters' => $parameters,
                    'required' => $required,
                ];
            }
        }

        return $signatures;
    }

    /**
     * @param  list<array{parameters: list<string>, required: list<string>}>  $signatures
     * @param  list<string>  $arguments
     */
    private function matchesAnySignature(array $signatures, array $arguments): bool
    {
        foreach ($signatures as $signature) {
            if ($this->matchesSignature($signature['parameters'], $signature['required'], $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $parameters
     * @param  list<string>  $required
     * @param  list<string>  $arguments
     */
    private function matchesSignature(array $parameters, array $required, array $arguments): bool
    {
        foreach ($required as $name) {
            if (! in_array($name, $arguments, true)) {
                return false;
            }
        }

        $position = 0;

        foreach ($arguments as $argument) {
            $found = array_search($argument, array_slice($parameters, $position), true);

            if ($found === false) {
                return false;
            }

            $position += $found + 1;
        }

        return true;
    }

    /**
     * Short names of every Tier 4 symbol, so an example that reaches for one
     * fails here rather than teaching consumers to depend on it.
     *
     * @return array<class-string, string>
     */
    private function internalShortNames(): array
    {
        $supported = [
            ...PublicApiSurfaceTest::TIER_ONE,
            ...PublicApiSurfaceTest::TIER_TWO,
        ];
        $internal = [];

        foreach ($this->sourceSymbols() as $symbol) {
            if (in_array($symbol, $supported, true)) {
                continue;
            }

            $documentation = (string) (new ReflectionClass($symbol))->getDocComment();

            if (preg_match('/(?:\/\*\*|\*)\s+@internal\b/', $documentation) !== 1) {
                continue;
            }

            $internal[$symbol] = substr((string) strrchr($symbol, '\\'), 1);
        }

        return $internal;
    }

    /**
     * @return list<class-string>
     */
    private function sourceSymbols(): array
    {
        $source = dirname(__DIR__, 2).'/src';
        $symbols = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1, -4);

            /** @var class-string $symbol */
            $symbol = 'HangTuah\\GateTrail\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            $symbols[] = $symbol;
        }

        sort($symbols);

        return $symbols;
    }
}
