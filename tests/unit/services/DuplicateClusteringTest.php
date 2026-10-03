<?php

namespace justinholtweb\tidytagstests\unit\services;

use justinholtweb\tidytags\services\TitleMatcher;
use justinholtweb\tidytagstests\support\PluginTestCase;
use ReflectionMethod;

/**
 * Proves the indexed clustering returns exactly what comparing every pair
 * would. The index exists so 17,000-item sources can be scanned, and an index
 * that silently missed a pair would hide duplicates rather than fail loudly,
 * so this compares the two against seeded random vocabularies.
 */
class DuplicateClusteringTest extends PluginTestCase
{
    /**
     * Runs the detector's private clustering on records built from titles.
     *
     * @param string[] $titles
     * @param array<int, ?string> $differentiators
     * @return array<int, int[]> clusters as lists of record positions
     */
    private function indexed(array $titles, int $threshold, array $differentiators = []): array
    {
        $method = new ReflectionMethod($this->detector(), '_clusterRecords');
        $method->setAccessible(true);

        $clusters = $method->invoke($this->detector(), $this->records($titles, $differentiators), $threshold);

        return array_map(fn(array $cluster) => array_column($cluster, 'id'), $clusters);
    }

    /**
     * The pre-5.3.0 algorithm: compare each seed with every later item.
     *
     * @param string[] $titles
     * @param array<int, ?string> $differentiators
     * @return array<int, int[]>
     */
    private function bruteForce(array $titles, int $threshold, array $differentiators = []): array
    {
        $matcher = $this->matcher();
        $records = $this->records($titles, $differentiators);
        $compatible = function(?string $a, ?string $b): bool {
            if ($a === null || $a === '' || $b === null || $b === '') {
                return true;
            }
            return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        };

        $clusters = [];
        $assigned = [];
        $count = count($records);
        for ($i = 0; $i < $count; $i++) {
            if (isset($assigned[$i])) {
                continue;
            }
            $cluster = [$records[$i]['id']];
            $assigned[$i] = true;
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($assigned[$j])) {
                    continue;
                }
                if ($matcher->compare($records[$i]['title'], $records[$j]['title'], $threshold) === null) {
                    continue;
                }
                if (!$compatible($records[$i]['differentiator'], $records[$j]['differentiator'])) {
                    continue;
                }
                $cluster[] = $records[$j]['id'];
                $assigned[$j] = true;
            }
            if (count($cluster) > 1) {
                $clusters[] = $cluster;
            }
        }

        return $clusters;
    }

    /**
     * @param string[] $titles
     * @param array<int, ?string> $differentiators
     * @return array<int, array<string, mixed>>
     */
    private function records(array $titles, array $differentiators): array
    {
        $records = [];
        foreach (array_values($titles) as $i => $title) {
            $records[] = [
                'id' => $i,
                'title' => $title,
                'normalized' => $this->matcher()->normalize($title),
                'differentiator' => $differentiators[$i] ?? null,
                'sourceUid' => 'test',
            ];
        }
        return $records;
    }

    /**
     * A vocabulary dense with near-misses: short words, typo variants, affixes,
     * qualifiers, punctuation, accents and multibyte characters.
     *
     * @return string[]
     */
    private function vocabulary(int $seed, int $size): array
    {
        mt_srand($seed);
        $alphabet = str_split('abcdeilmnorstu');
        $extras = ['é', 'ü', 'ø', 'ß', '&', '-', ' ', '.'];
        $affixes = ['FC', 'AFC', 'SC', 'CF'];
        $qualifiers = ['Women', 'II', 'U21', 'Reserves'];

        $bases = [];
        for ($i = 0; $i < max(10, intdiv($size, 6)); $i++) {
            $word = '';
            $length = mt_rand(1, 14);
            for ($c = 0; $c < $length; $c++) {
                $word .= mt_rand(0, 12) === 0 ? $extras[array_rand($extras)] : $alphabet[array_rand($alphabet)];
            }
            $bases[] = ucfirst($word);
        }

        $titles = [];
        for ($i = 0; $i < $size; $i++) {
            $title = $bases[array_rand($bases)];
            $chars = mb_str_split($title) ?: [''];
            for ($edit = mt_rand(0, 3); $edit > 0; $edit--) {
                $at = mt_rand(0, count($chars));
                switch (mt_rand(0, 2)) {
                    case 0:
                        array_splice($chars, $at, 0, [$alphabet[array_rand($alphabet)]]);
                        break;
                    case 1:
                        array_splice($chars, min($at, max(0, count($chars) - 1)), 1);
                        break;
                    default:
                        $chars[min($at, max(0, count($chars) - 1))] = $alphabet[array_rand($alphabet)];
                }
            }
            $title = implode('', $chars);
            if (mt_rand(0, 4) === 0) {
                $title = mt_rand(0, 1) ? $affixes[array_rand($affixes)] . ' ' . $title : $title . ' ' . $affixes[array_rand($affixes)];
            }
            if (mt_rand(0, 6) === 0) {
                $title .= ' ' . $qualifiers[array_rand($qualifiers)];
            }
            $titles[] = $title;
        }

        return $titles;
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function fuzzyProvider(): array
    {
        $cases = [];
        foreach ([0, 1, 2, 3, 4, 6] as $threshold) {
            foreach ([11, 23] as $seed) {
                $cases["threshold $threshold, seed $seed"] = [$threshold, $seed];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider fuzzyProvider
     */
    public function testFuzzyIndexMatchesBruteForce(int $threshold, int $seed): void
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);
        $titles = $this->vocabulary($seed, 600);

        $expected = $this->bruteForce($titles, $threshold);
        self::assertNotEmpty($expected, 'The vocabulary should contain duplicates.');
        self::assertSame($expected, $this->indexed($titles, $threshold));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function seedProvider(): array
    {
        return ['seed 5' => [5], 'seed 17' => [17], 'seed 29' => [29]];
    }

    /**
     * @dataProvider seedProvider
     */
    public function testStrictIndexMatchesBruteForce(int $seed): void
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_STRICT]);
        $titles = $this->vocabulary($seed, 600);

        $expected = $this->bruteForce($titles, 2);
        self::assertNotEmpty($expected);
        self::assertSame($expected, $this->indexed($titles, 2));
    }

    /**
     * Differentiators are compared with the seed, not transitively, so the
     * order clusters form in matters. The index must not change it.
     */
    public function testDifferentiatorsClusterTheSameWayAsBruteForce(): void
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);
        $titles = $this->vocabulary(41, 400);
        mt_srand(41);
        $differentiators = array_map(fn() => [null, 'Football', 'Cricket', ''][mt_rand(0, 3)], $titles);

        self::assertSame(
            $this->bruteForce($titles, 2, $differentiators),
            $this->indexed($titles, 2, $differentiators),
        );
    }

    /**
     * The reason the index exists: a 17,000-title vocabulary has to cluster
     * fast enough for a page request. The old pairwise scan took minutes.
     */
    public function testSeventeenThousandTitlesClusterQuickly(): void
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);
        $titles = $this->vocabulary(7, 17000);

        $started = microtime(true);
        $clusters = $this->indexed($titles, 2);
        $elapsed = microtime(true) - $started;

        self::assertNotEmpty($clusters);
        self::assertLessThan(20, $elapsed, sprintf('Clustering took %.1fs.', $elapsed));
    }
}
