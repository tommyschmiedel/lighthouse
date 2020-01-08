<?php

namespace Nuwave\Lighthouse\WhereConstraints;

use GraphQL\Error\Error;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\AST\PartialParser;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgBuilderDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgManipulator;
use Nuwave\Lighthouse\Support\Contracts\DefinedDirective;

class WhereConstraintsDirective extends BaseDirective implements ArgBuilderDirective, ArgManipulator, DefinedDirective
{
    const NAME = 'whereConstraints';

    /**
     * @var \Nuwave\Lighthouse\WhereConstraints\Operator
     */
    protected $operator;

    /**
     * WhereConstraintsDirective constructor.
     *
     * @param  \Nuwave\Lighthouse\WhereConstraints\Operator  $operator
     * @return void
     */
    public function __construct(Operator $operator)
    {
        $this->operator = $operator;
    }

    /**
     * Name of the directive.
     *
     * @return string
     */
    public function name(): string
    {
        return self::NAME;
    }

    public static function definition(): string
    {
        return /* @lang GraphQL */ <<<'SDL'
"""
Add a dynamically client-controlled WHERE constraint to a fields query.
The argument it is defined on may have any name but **must** be
of the input type `WhereConstraints`.
"""
directive @whereConstraints(
    """
    Restrict the allowed column names to a well-defined list.
    This improves introspection capabilities and security.
    """
    columns: [String!]
) on ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION
SDL;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $builder
     * @param  mixed[]  $whereConstraints
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public function handleBuilder($builder, $whereConstraints, string $boolean = 'and')
    {
        if ($andConnectedConstraints = $whereConstraints['AND'] ?? null) {
            $builder->whereNested(
                function ($builder) use ($andConnectedConstraints): void {
                    foreach ($andConnectedConstraints as $constraint) {
                        $this->handleBuilder($builder, $constraint);
                    }
                }
            );
        }

        if ($orConnectedConstraints = $whereConstraints['OR'] ?? null) {
            $builder->whereNested(
                function ($builder) use ($orConnectedConstraints): void {
                    foreach ($orConnectedConstraints as $constraint) {
                        $this->handleBuilder($builder, $constraint, 'or');
                    }
                },
                'or'
            );
        }

        if ($notConnectedConstraints = $whereConstraints['NOT'] ?? null) {
            $builder->whereNested(
                function ($builder) use ($notConnectedConstraints): void {
                    foreach ($notConnectedConstraints as $constraint) {
                        $this->handleBuilder($builder, $constraint);
                    }
                },
                'not'
            );
        }

        if ($column = $whereConstraints['column'] ?? null) {
            static::assertValidColumnName($column);

            return $this->operator->applyConstraints($builder, $whereConstraints, $boolean);
        }

        return $builder;
    }

    public static function invalidColumnName(string $column): string
    {
        return "Column names may contain only alphanumerics or underscores, and may not begin with a digit, got: $column";
    }

    /**
     * Manipulate the AST.
     *
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @param  \GraphQL\Language\AST\InputValueDefinitionNode  $argDefinition
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $parentField
     * @param  \GraphQL\Language\AST\ObjectTypeDefinitionNode  $parentType
     * @return void
     */
    public function manipulateArgDefinition(
        DocumentAST &$documentAST,
        InputValueDefinitionNode &$argDefinition,
        FieldDefinitionNode &$parentField,
        ObjectTypeDefinitionNode &$parentType
    ): void {
        $allowedColumns = $this->directiveArgValue('columns');
        if (! $allowedColumns) {
            return;
        }

        $restrictedWhereConstraintsName = $this->restrictedWhereConstraintsName($argDefinition, $parentField);
        $argDefinition->type = PartialParser::namedType($restrictedWhereConstraintsName);

        $allowedColumnsEnumName = $this->allowedColumnsEnumName($argDefinition, $parentField);

        $documentAST
            ->setTypeDefinition(
                WhereConstraintsServiceProvider::createWhereConstraintsInputType(
                    $restrictedWhereConstraintsName,
                    "Dynamic WHERE constraints for the `{$argDefinition->name->value}` argument on the query `{$parentField->name->value}`.",
                    $allowedColumnsEnumName
                )
            )
            ->setTypeDefinition(
                $this->createAllowedColumnsEnum($argDefinition, $parentField, $allowedColumns, $allowedColumnsEnumName)
            );
    }

    /**
     * Create the name for the restricted WhereConstraints input.
     *
     * @example FieldNameArgNameWhereConstraints
     *
     * @param  \GraphQL\Language\AST\InputValueDefinitionNode  $argDefinition
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $parentField
     * @return string
     */
    protected function restrictedWhereConstraintsName(InputValueDefinitionNode &$argDefinition, FieldDefinitionNode &$parentField): string
    {
        return Str::studly($parentField->name->value)
            .Str::studly($argDefinition->name->value)
            .'WhereConstraints';
    }

    /**
     * Create the name for the Enum that holds the allowed columns.
     *
     * @example FieldNameArgNameColumn
     *
     * @param  \GraphQL\Language\AST\InputValueDefinitionNode  $argDefinition
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $parentField
     * @return string
     */
    protected function allowedColumnsEnumName(InputValueDefinitionNode &$argDefinition, FieldDefinitionNode &$parentField): string
    {
        return Str::studly($parentField->name->value)
            .Str::studly($argDefinition->name->value)
            .'Column';
    }

    /**
     * Create the Enum that holds the allowed columns.
     *
     * @param  \GraphQL\Language\AST\InputValueDefinitionNode  $argDefinition
     * @param  \GraphQL\Language\AST\FieldDefinitionNode  $parentField
     * @param  string[]  $allowedColumns
     * @param  string  $allowedColumnsEnumName
     * @return \GraphQL\Language\AST\EnumTypeDefinitionNode
     */
    protected function createAllowedColumnsEnum(
        InputValueDefinitionNode &$argDefinition,
        FieldDefinitionNode &$parentField,
        array $allowedColumns,
        string $allowedColumnsEnumName
    ): EnumTypeDefinitionNode {
        $enumValues = array_map(
            function (string $columnName): string {
                return
                    strtoupper(
                        Str::snake($columnName)
                    )
                    .' @enum(value: "'.$columnName.'")';
            },
            $allowedColumns
        );

        $enumDefinition = "\"Allowed column names for the `{$argDefinition->name->value}` argument on the query `{$parentField->name->value}`.\"\n"
            ."enum $allowedColumnsEnumName {\n";
        foreach ($enumValues as $enumValue) {
            $enumDefinition .= "$enumValue\n";
        }
        $enumDefinition .= '}';

        return PartialParser::enumTypeDefinition($enumDefinition);
    }

    /**
     * Ensure the column name is well formed and prevent SQL injection.
     *
     * @param  string  $column
     * @return void
     *
     * @throws \GraphQL\Error\Error
     */
    protected static function assertValidColumnName(string $column): void
    {
        if (! \Safe\preg_match('/^(?![0-9])[A-Za-z0-9_-]*$/', $column)) {
            throw new Error(
                self::invalidColumnName($column)
            );
        }
    }
}
