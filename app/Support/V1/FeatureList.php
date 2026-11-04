<?php

namespace App\Support\V1;

use Illuminate\Support\Collection;

/**
 * The listing feature and activity columns, which hold rendered HTML.
 *
 * `land.key_features` is the selection — a comma-separated list where each
 * entry is `<featureId>-<parentId>`, or `<free text>-other<parentId>` for the
 * "other" box under a group. `land.features_list` is a nested `<ul>` built
 * from it and stored. `land.allowed_activities` pairs each rule id with Yes or
 * No, with any free text appended as its own entry, and
 * `land.allowed_activities_text` is again stored HTML.
 *
 * Storing rendered markup in a column is not what this API would choose, but
 * the old web application reads these columns directly — the admin listing
 * view does `echo $model->features_list` and two mail templates do the same —
 * so while both applications share the database, a listing edited through
 * this API has to leave them in the same shape.
 *
 * What is different here is escaping. The old builders interpolated the
 * client's own text into that HTML untouched:
 *
 *     $features_field .= '<li class="...">' . $selectedFeaturesList["other$key"] . '</li>';
 *     $rules_list .= '<li>' . $landRulesList["more"] . '</li>';   // nl2br($rule)
 *
 * Both of those strings come from the request, and the pages that render them
 * do not escape either. So whatever an owner typed into an "other" feature or
 * the extra-rules box was stored as markup and executed in an administrator's
 * browser, and in the property notification emails. Here the free text is
 * escaped before it reaches the markup, which fixes it for the admin pages
 * too, since what they render is what this writes.
 */
class FeatureList
{
    /**
     * The selection as the column stores it.
     *
     * The app posts `key_features` as a map, where an "other" box arrives
     * under a key containing "other" and its value is the typed text. The old
     * helper turned that into `<text>-other<parentId>`; this keeps that, since
     * it is what the column holds for all 16,980 rows.
     *
     * @param  array<string,mixed>  $features
     */
    public static function selection(array $features): string
    {
        $entries = [];

        foreach ($features as $key => $value) {
            $key = (string) $key;

            if (str_contains($key, 'other')) {
                [, $parent] = array_pad(explode('-', $key, 2), 2, '');
                $entries[] = $value.'-other'.$parent;

                continue;
            }

            $entries[] = (string) $value;
        }

        return implode(',', $entries);
    }

    /**
     * The stored `<ul>` for a selection.
     *
     * @param  Collection<int,object>  $keyFeatures  rows from `key_features`
     */
    public static function html(string $selection, Collection $keyFeatures): string
    {
        [$selected, $other] = self::parseSelection($selection);
        $tree = self::tree($keyFeatures);

        $html = '<ul>';

        foreach ($tree as $id => $feature) {
            if (! in_array((string) $id, $selected, true)) {
                continue;
            }

            $html .= '<li class="'.self::slug($feature['feature']).'">'.e($feature['feature']);

            if ($feature['children'] !== []) {
                $html .= '<ul>';

                foreach ($feature['children'] as $childId => $child) {
                    if (in_array((string) $childId, $selected, true)) {
                        $html .= '<li class="'.self::slug($child['feature']).'">'
                            .e($child['feature']).'</li>';
                    }
                }

                // The free text typed into this group's "other" box. Escaped,
                // which is the one thing the old builder did not do.
                if (($other[$id] ?? '') !== '') {
                    $html .= '<li class="'.self::slug($feature['feature'].' Other').'">'
                        .e($other[$id]).'</li>';
                }

                $html .= '</ul>';
            }

            $html .= '</li>';
        }

        return $html.'</ul>';
    }

    /**
     * The stored `<ul>` for the activity rules.
     *
     * @param  Collection<int,object>  $rules  rows from `rules`
     */
    public static function rulesHtml(string $selection, Collection $rules): string
    {
        $answers = [];
        $extra = [];

        foreach (explode(',', $selection) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '-')) {
                [$id, $answer] = array_pad(explode('-', $entry, 2), 2, '');

                if (is_numeric($id)) {
                    $answers[(int) $id] = $answer;

                    continue;
                }
            }

            $extra[] = $entry;
        }

        $html = '<ul>';

        foreach ($rules as $rule) {
            if (array_key_exists((int) $rule->rule_id, $answers)) {
                $html .= '<li>'.e($rule->rule).' - <strong>'
                    .e($answers[(int) $rule->rule_id]).'</strong></li>';
            }
        }

        if ($extra !== []) {
            // nl2br over escaped text, so a line break survives and markup
            // does not. The old version ran nl2br over the raw value.
            $html .= '<li>'.nl2br(e(implode(',', $extra))).'</li>';
        }

        return $html.'</ul>';
    }

    /**
     * The feature ids a selection names, for validating it.
     *
     * @return list<int>
     */
    public static function featureIds(string $selection): array
    {
        [$selected] = self::parseSelection($selection);

        return array_values(array_map('intval', array_filter(
            $selected,
            fn ($id) => is_numeric($id),
        )));
    }

    /**
     * The selected ids, and the free text per group.
     *
     * @return array{0: list<string>, 1: array<int|string,string>}
     */
    private static function parseSelection(string $selection): array
    {
        $selected = [];
        $other = [];

        foreach (explode(',', $selection) as $entry) {
            if (! str_contains($entry, '-')) {
                continue;
            }

            [$value, $parent] = array_pad(explode('-', $entry, 2), 2, '');

            $selected[] = $value;

            if (str_starts_with($parent, 'other')) {
                $other[substr($parent, 5)] = $value;
            }
        }

        return [$selected, $other];
    }

    /**
     * The features as a two-level tree, parents in sort order.
     *
     * Top level is `parent_id` 0 or null: the old recursive helper compared
     * with `==` against null, which made both match.
     *
     * @param  Collection<int,object>  $keyFeatures
     * @return array<int,array{feature: string, children: array<int,array{feature: string}>}>
     */
    private static function tree(Collection $keyFeatures): array
    {
        $tree = [];

        foreach ($keyFeatures as $feature) {
            if (! in_array((int) ($feature->parent_id ?? 0), [0], true)) {
                continue;
            }

            $tree[(int) $feature->feature_id] = [
                'feature' => (string) $feature->feature,
                'children' => [],
            ];
        }

        foreach ($keyFeatures as $feature) {
            $parent = (int) ($feature->parent_id ?? 0);

            if ($parent !== 0 && isset($tree[$parent])) {
                $tree[$parent]['children'][(int) $feature->feature_id] = [
                    'feature' => (string) $feature->feature,
                ];
            }
        }

        return $tree;
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($value));
    }
}
