<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/SegmentIntersection
 * @description Robust-enough planar segment intersection tests for ring validation
 *     (self-intersection and ring-crossing), with bounding-box pre-filtering.
 *
 * @input Rings as list<array{0:float,1:float}>
 * @output booleans
 */
final class SegmentIntersection
{
    /** @param list<array{0:float,1:float}> $ring closed ring */
    public static function ringSelfIntersects(array $ring): bool
    {
        $segments = self::segments($ring);
        $n = \count($segments);
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $adjacent = $j === $i + 1 || (0 === $i && $j === $n - 1);
                if ($adjacent) {
                    // Adjacent segments share one endpoint; they only intersect improperly if collinear-overlapping.
                    if (self::collinearOverlap($segments[$i], $segments[$j])) {
                        return true;
                    }
                    continue;
                }
                if (self::intersects($segments[$i], $segments[$j])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when the two rings properly cross or touch (holes may not touch shells in this app).
     *
     * @param list<array{0:float,1:float}> $a
     * @param list<array{0:float,1:float}> $b
     */
    public static function ringsCross(array $a, array $b): bool
    {
        foreach (self::segments($a) as $sa) {
            foreach (self::segments($b) as $sb) {
                if (self::intersects($sa, $sb)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Counts proper crossings (interiors intersect at a single point); shared edges/touches are ignored.
     *
     * @param list<array{0:float,1:float}> $a
     * @param list<array{0:float,1:float}> $b
     */
    public static function properCrossings(array $a, array $b): int
    {
        $count = 0;
        foreach (self::segments($a) as [$p1, $p2]) {
            foreach (self::segments($b) as [$p3, $p4]) {
                $d1 = self::orient($p3, $p4, $p1);
                $d2 = self::orient($p3, $p4, $p2);
                $d3 = self::orient($p1, $p2, $p3);
                $d4 = self::orient($p1, $p2, $p4);
                if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** @return list<array{0:array{0:float,1:float},1:array{0:float,1:float}}> */
    private static function segments(array $ring): array
    {
        $out = [];
        $n = \count($ring);
        for ($i = 0; $i < $n - 1; ++$i) {
            if ($ring[$i] !== $ring[$i + 1]) {
                $out[] = [$ring[$i], $ring[$i + 1]];
            }
        }

        return $out;
    }

    /**
     * @param array{0:array{0:float,1:float},1:array{0:float,1:float}} $s
     * @param array{0:array{0:float,1:float},1:array{0:float,1:float}} $t
     */
    public static function intersects(array $s, array $t): bool
    {
        [$p1, $p2] = $s;
        [$p3, $p4] = $t;
        if (max($p1[0], $p2[0]) < min($p3[0], $p4[0]) || max($p3[0], $p4[0]) < min($p1[0], $p2[0])
            || max($p1[1], $p2[1]) < min($p3[1], $p4[1]) || max($p3[1], $p4[1]) < min($p1[1], $p2[1])) {
            return false;
        }
        $d1 = self::orient($p3, $p4, $p1);
        $d2 = self::orient($p3, $p4, $p2);
        $d3 = self::orient($p1, $p2, $p3);
        $d4 = self::orient($p1, $p2, $p4);
        if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
            return true;
        }

        return (0.0 === $d1 && self::within($p3, $p4, $p1))
            || (0.0 === $d2 && self::within($p3, $p4, $p2))
            || (0.0 === $d3 && self::within($p1, $p2, $p3))
            || (0.0 === $d4 && self::within($p1, $p2, $p4));
    }

    private static function collinearOverlap(array $s, array $t): bool
    {
        [$p1, $p2] = $s;
        [$p3, $p4] = $t;
        if (0.0 !== self::orient($p1, $p2, $p3) || 0.0 !== self::orient($p1, $p2, $p4)) {
            return false;
        }
        // Shared endpoint is expected; overlap means the far endpoint folds back onto the other segment.
        $shared = $p2 === $p3 ? $p4 : ($p1 === $p4 ? $p3 : null);
        $other = $p2 === $p3 ? [$p1, $p2] : [$p3, $p4];

        return null !== $shared && self::within($other[0], $other[1], $shared) && $shared !== $other[1] && $shared !== $other[0];
    }

    private static function orient(array $a, array $b, array $c): float
    {
        $v = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);

        return abs($v) < 1e-18 ? 0.0 : $v;
    }

    private static function within(array $a, array $b, array $p): bool
    {
        return $p[0] >= min($a[0], $b[0]) && $p[0] <= max($a[0], $b[0]) && $p[1] >= min($a[1], $b[1]) && $p[1] <= max($a[1], $b[1]);
    }
}
