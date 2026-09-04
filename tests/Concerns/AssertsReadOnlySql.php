<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concerns;

/**
 * The single definition of "this statement does not mutate anything", shared by
 * the PostgreSQL catalog-reader unit proof and the live query-listener proof so
 * the two can never drift apart.
 *
 * Matching is token-aware rather than substring-based. A catalog query
 * legitimately selects `con.confupdtype AS update_action`, and a proof that
 * rejected it for containing the letters `UPDATE` would be a false alarm; one
 * that then loosened into a plain substring search would stop catching
 * `FOR UPDATE`. Every pattern below is therefore anchored to word boundaries.
 */
trait AssertsReadOnlySql
{
    /**
     * Statement verbs and clauses that write, lock, or change session state.
     *
     * @var array<string, string>
     */
    private array $mutatingSql = [
        'INSERT' => '/\bINSERT\b/',
        'UPDATE' => '/\bUPDATE\b/',
        'DELETE' => '/\bDELETE\b/',
        'MERGE' => '/\bMERGE\b/',
        'CREATE' => '/\bCREATE\b/',
        'ALTER' => '/\bALTER\b/',
        'DROP' => '/\bDROP\b/',
        'TRUNCATE' => '/\bTRUNCATE\b/',
        'COMMENT' => '/\bCOMMENT\b/',
        'GRANT' => '/\bGRANT\b/',
        'REVOKE' => '/\bREVOKE\b/',
        'SET' => '/\bSET\b/',
        'RESET' => '/\bRESET\b/',
        'LOCK' => '/\bLOCK\b/',
        'REINDEX' => '/\bREINDEX\b/',
        'VACUUM' => '/\bVACUUM\b/',
        'ANALYZE' => '/\bANALYZE\b/',
        'CLUSTER' => '/\bCLUSTER\b/',
        'REFRESH' => '/\bREFRESH\b/',
        'COPY' => '/\bCOPY\b/',
        'CALL' => '/\bCALL\b/',
        'DO' => '/\bDO\b/',
        'advisory lock' => '/\bPG_(TRY_)?ADVISORY\w*/',
        'sequence write' => '/\b(NEXTVAL|SETVAL)\b/',
        'FOR UPDATE' => '/\bFOR\s+UPDATE\b/',
        'FOR SHARE' => '/\bFOR\s+SHARE\b/',
        'FOR NO KEY UPDATE' => '/\bFOR\s+NO\s+KEY\s+UPDATE\b/',
    ];

    /**
     * Asserts that one statement reads and does nothing else.
     */
    private function assertReadOnlyStatement(string $sql): void
    {
        $normalized = strtoupper(
            trim((string) preg_replace('/\s+/', ' ', $sql)),
        );

        $this->assertStringStartsWith(
            'SELECT ',
            $normalized,
            sprintf('A non-SELECT statement was executed: %s', $sql),
        );

        foreach ($this->mutatingSql as $label => $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, $normalized),
                sprintf(
                    'A statement containing [%s] was executed: %s',
                    $label,
                    $sql,
                ),
            );
        }
    }

    /**
     * @param  list<string>  $statements
     */
    private function assertReadOnlyStatements(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->assertReadOnlyStatement($sql);
        }
    }
}
