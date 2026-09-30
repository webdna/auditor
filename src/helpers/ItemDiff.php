<?php

namespace webdna\auditor\helpers;

use Closure;

/**
 * Compares two lists of items — an order's line items before and after a save — as what was added,
 * what was removed, and what changed on the items in both (BR-23).
 *
 * An item is `{id, label, values}`. Items are paired by id; the rest, when a pairing key is given,
 * are paired by that key in order, so a row the owner deleted and wrote again with a new id (as
 * Commerce does with an order's adjustments) is a change rather than a removal and an addition.
 *
 * Values are compared with object keys in any order and `null`, `''` and `[]` equal.
 *
 * @since 1.0.0
 */
final class ItemDiff
{
    /**
     * @param list<array{id: int, label: string, values: array<string, mixed>}> $old
     * @param list<array{id: int, label: string, values: array<string, mixed>}> $new
     * @param (Closure(array{id: int, label: string, values: array<string, mixed>}): string)|null $pairBy
     * @return array{
     *     added: list<array{id: int, label: string, values: array<string, mixed>}>,
     *     removed: list<array{id: int, label: string, values: array<string, mixed>}>,
     *     changed: list<array{id: int, label: string, changes: array<string, array{old: mixed, new: mixed}>}>,
     * }|null Null when nothing changed
     */
    public static function compare(array $old, array $new, ?Closure $pairBy = null): ?array
    {
        $unmatchedOld = [];
        foreach ($old as $item) {
            $unmatchedOld[$item['id']] = $item;
        }

        $pairs = [];
        $unmatchedNew = [];
        foreach ($new as $item) {
            if (isset($unmatchedOld[$item['id']])) {
                $pairs[] = [$unmatchedOld[$item['id']], $item];
                unset($unmatchedOld[$item['id']]);
            } else {
                $unmatchedNew[] = $item;
            }
        }

        if ($pairBy !== null && $unmatchedOld !== [] && $unmatchedNew !== []) {
            $byKey = [];
            foreach ($unmatchedOld as $id => $item) {
                $byKey[$pairBy($item)][] = $id;
            }
            foreach ($unmatchedNew as $i => $item) {
                $key = $pairBy($item);
                $id = isset($byKey[$key]) ? array_shift($byKey[$key]) : null;
                if ($id !== null) {
                    $pairs[] = [$unmatchedOld[$id], $item];
                    unset($unmatchedOld[$id], $unmatchedNew[$i]);
                }
            }
        }

        $changed = [];
        foreach ($pairs as [$before, $after]) {
            $changes = [];
            foreach (array_keys($before['values'] + $after['values']) as $name) {
                $was = $before['values'][$name] ?? null;
                $is = $after['values'][$name] ?? null;
                if (self::canonical($was) !== self::canonical($is)) {
                    $changes[$name] = ['old' => $was, 'new' => $is];
                }
            }
            if ($changes !== []) {
                $changed[] = ['id' => $after['id'], 'label' => $after['label'], 'changes' => $changes];
            }
        }

        $diff = [
            'added' => array_values($unmatchedNew),
            'removed' => array_values($unmatchedOld),
            'changed' => $changed,
        ];

        return $diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === [] ? null : $diff;
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int)$value;
        }
        if (is_array($value)) {
            $out = array_map(self::canonical(...), $value);
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }

        return $value;
    }
}
