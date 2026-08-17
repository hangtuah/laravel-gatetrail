<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a caller's requested role or permission into a finished question.
 *
 * One component serves both resolvers because the rules are identical and must
 * stay identical; two copies would be two places for the string-is-a-name rule
 * to drift. It is scoped to exactly that job: it resolves nothing, filters
 * nothing, and never touches the database.
 *
 * `null` means **impossible** — no definition row could ever carry this input.
 * The caller denies on it *before* building a snapshot, which is what makes a
 * malformed identifier cost zero queries and keeps it out of every driver
 * binding.
 *
 * Four input shapes, and one rule each.
 *
 * **A bare string is always a name.** `'42'` asks for a definition literally
 * named `42`; `'007'` asks for one named `007`. Nothing about how a string
 * looks makes it an identifier — wrapping it in a reference is how a caller
 * says that. A name is passed through verbatim, so `' reviewer '` stays
 * `' reviewer '` and simply fails to match; only an empty or whitespace-only
 * string is rejected, because definition names are trimmed and non-empty at
 * creation and no row can carry one.
 *
 * **An `int` and a reference are always identifiers**, normalized through the
 * same {@see DefinitionIdentifierNormalizer} the mutation paths use, so
 * `Reference::id('007')`, `Reference::id('7')` and `Reference::id(7)` are one
 * question against an integer-keyed model, and `'007'` stays `'007'` against a
 * string-keyed one.
 *
 * **A definition model is honoured only when GateTrail owns its class** — the
 * `ModelRegistry`-resolved class or a subclass of it. `PermissionContract` and
 * `RoleContract` are empty markers, so any application class can implement
 * one; accepting those by identifier would let an unrelated model whose key
 * happens to collide with an effective definition authorize the caller. Key
 * `1` exists in nearly every table, so that is a real escalation path rather
 * than a theoretical one. Only the persisted key is read — never the name,
 * guard or soft-delete state — so a detached or hand-edited instance cannot
 * steer the decision.
 *
 * **An unrelated object denies; it never throws.**
 * `InvalidModelConfiguration` reports a broken package configuration, not a
 * caller passing the wrong object, and throwing here would let a caller probe
 * which classes are configured.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class RequestedDefinitionNormalizer
{
    public function __construct(
        private DefinitionIdentifierNormalizer $identifiers,
    ) {}

    /**
     * @param  Model  $definition  a bare instance of the configured definition
     *                             model, used for its class and key type only
     */
    public function normalize(
        PermissionContract|RoleContract|PermissionReference|RoleReference|string|int $input,
        Model $definition,
    ): ?RequestedDefinition {
        if (is_int($input)) {
            return $this->identifier($input, $definition);
        }

        if (is_string($input)) {
            return trim($input) === ''
                ? null
                : RequestedDefinition::named($input);
        }

        if ($input instanceof PermissionReference || $input instanceof RoleReference) {
            return $this->identifier($input->id, $definition);
        }

        // The marker interfaces guarantee nothing, so the class check is the
        // only thing standing between a colliding foreign key and a grant.
        if (! $input instanceof Model || ! $input instanceof $definition) {
            return null;
        }

        if ($input->exists !== true) {
            return null;
        }

        $key = $input->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        return $this->identifier($key, $definition);
    }

    /**
     * A rejected identifier is a denial, not an exception. Casting instead
     * would saturate `'99999999999999999999'` onto `PHP_INT_MAX`, which is a
     * real definition's identifier.
     */
    private function identifier(
        int|string $identifier,
        Model $definition,
    ): ?RequestedDefinition {
        $normalized = $this->identifiers->normalize($definition, $identifier);

        return $normalized === null
            ? null
            : RequestedDefinition::identifiedBy($normalized);
    }
}
