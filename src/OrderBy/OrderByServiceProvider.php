<?php

namespace Nuwave\Lighthouse\OrderBy;

use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Events\ManipulateAST;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;
use Nuwave\Lighthouse\Schema\AST\PartialParser;


class OrderByServiceProvider extends ServiceProvider
{
    const DEFAULT_ORDER_BY_CLAUSE = 'OrderByClause';

    /**
     * Bootstrap any application services.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $dispatcher
     * @return void
     */
    public function boot(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(
            RegisterDirectiveNamespaces::class,
            function (RegisterDirectiveNamespaces $registerDirectiveNamespaces): string {
                return __NAMESPACE__;
            }
        );

        $dispatcher->listen(
            ManipulateAST::class,
            function (ManipulateAST $manipulateAST): void {
                $manipulateAST->documentAST
                    ->setTypeDefinition(
                        PartialParser::enumTypeDefinition(/** @lang GraphQL */ '
                            enum SortOrder {
                                ASC
                                DESC
                            }
                        '
                        )
                    )
                    ->setTypeDefinition(
                        static::createOrderByClauseInput(
                            static::DEFAULT_ORDER_BY_CLAUSE,
                            'Allows ordering a list of records.',
                            'String'
                        )
                    );
            }
        );
    }

    public static function createOrderByClauseInput(string $name, string $description, string $columnType): InputObjectTypeDefinitionNode
    {
        // TODO deprecated remove in v5
        $columnName = config('lighthouse.orderBy');

        return PartialParser::inputObjectTypeDefinition(/** @lang GraphQL */ <<<GRAPHQL
            "$description"
            input $name {
               $columnName: $columnType!
               order: SortOrder!
            }
GRAPHQL
        );
    }
}
