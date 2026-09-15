<?php

namespace Mustasj\CraftMcp\tools;

use craft\elements\Category;
use Mustasj\CraftMcp\helpers\SiteHelper;

/**
 * MCP-verktøy for kategorier.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class CategoryTools
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Lister kategoriene i en gruppe.
     *
     * @param string $group Kategorigruppe («hovedkategori», «merke» eller «vanskelighetsgrad»)
     * @param string $site Språk («nb» eller «en»)
     * @return array
     */
    public function listCategories(
        string $group,
        string $site = 'nb',
    ): array {
        $categories = Category::find()
            ->group($group)
            ->site(SiteHelper::resolve($site)->handle)
            ->all();

        return [
            'group' => $group,
            'categories' => array_map(static fn(Category $category) => [
                'id' => $category->id,
                'title' => $category->title,
                'slug' => $category->slug,
            ], $categories),
        ];
    }
}
