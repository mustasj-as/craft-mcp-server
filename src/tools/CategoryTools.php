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
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @return array
     */
    public function listCategories(
        string $group,
        ?string $site = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

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
