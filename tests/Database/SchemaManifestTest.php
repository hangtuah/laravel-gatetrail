<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use PHPUnit\Framework\TestCase;

final class SchemaManifestTest extends TestCase
{
    public function test_creation_and_rollback_orders_are_dependency_safe(): void
    {
        $manifest = SchemaManifest::gateTrail();
        $creationOrder = $manifest->creationOrder();

        foreach ($manifest->tables() as $table) {
            foreach ($table->dependencies as $dependency) {
                $this->assertLessThan(
                    array_search($table->key, $creationOrder, true),
                    array_search($dependency, $creationOrder, true),
                    sprintf(
                        'Expected [%s] before [%s].',
                        $dependency,
                        $table->key,
                    ),
                );
            }
        }

        $this->assertSame(
            array_reverse($creationOrder),
            $manifest->rollbackOrder(),
        );
    }

    public function test_every_manifest_identifier_is_portable(): void
    {
        $validator = new IdentifierValidator;
        $manifest = SchemaManifest::gateTrail();

        foreach ($manifest->tables() as $table) {
            foreach ($table->constraints as $constraint) {
                $validator->validate($constraint->name, 'constraint');
            }

            foreach ($table->indexes as $index) {
                $validator->validate($index->name, 'index');
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_foreign_keys_never_cascade(): void
    {
        foreach (SchemaManifest::gateTrail()->tables() as $table) {
            foreach ($table->constraints as $constraint) {
                if ($constraint->type === ConstraintType::Foreign) {
                    $this->assertFalse($constraint->cascade);
                }
            }
        }
    }

    public function test_each_table_has_unique_column_names(): void
    {
        foreach (SchemaManifest::gateTrail()->tables() as $table) {
            $columns = $table->columnNames();

            $this->assertSame(
                $columns,
                array_values(array_unique($columns)),
                sprintf(
                    'Manifest table [%s] contains duplicate columns.',
                    $table->key,
                ),
            );
        }
    }
}
