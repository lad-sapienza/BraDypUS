<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * The fast search must not LIKE the INTEGER chrono_* columns of the fuzzy-date
 * plugin: PostgreSQL has no `integer ILIKE text`, and apps activated before the
 * field definitions carried a db_type have none for chrono_from / chrono_to.
 *
 * The SQL is checked, not run: SQLite would accept the bad statement.
 */
class FastSearchChronoColumnsTest extends BdusTestCase
{
    /** Config where `items` also lists chrono fields, with no db_type (old apps). */
    private function cfgWithChronoFields(): \Config\Config
    {
        return new class(static::$cfg) extends \Config\Config {
            private const EXTRA = [
                'chrono_from' => 'Cronologia da', 'chrono_to' => 'Cronologia a',
                'chrono_label' => 'Etichetta', 'chrono_certainty' => 'Certezza', 'chrono_period' => 'Periodo',
            ];
            public function __construct(private $inner) {}
            public function get(string $key = '*', string $filter_key = null, string $filter_val = null)
            {
                $v = $this->inner->get($key, $filter_key, $filter_val);
                if ($key === 'tables.items.fields.*.label') {
                    return $v + self::EXTRA;          // names are listed, db_type is not
                }
                if ($key === 'tables.items.fields.*.name') {
                    return $v + array_combine(array_keys(self::EXTRA), array_keys(self::EXTRA));
                }
                return $v;
            }
        };
    }

    private function sql(): string
    {
        $q = new \SQL\QueryFromRequest(
            static::$db, $this->cfgWithChronoFields(),
            ['tb' => 'items', 'type' => 'fast', 'string' => 'x'], false
        );
        // Only the WHERE: the columns are selected whatever is searched.
        return explode(' WHERE ', $q->getQuery(), 2)[1];
    }

    public function testIntegerChronoColumnsAreLeftOutOfTheLikeSearch(): void
    {
        $sql = $this->sql();

        $this->assertStringContainsString('items.name', $sql);
        $this->assertStringNotContainsString('items.chrono_from', $sql);
        $this->assertStringNotContainsString('items.chrono_to', $sql);
        $this->assertStringNotContainsString('items.chrono_certainty', $sql);
    }

    public function testTextChronoColumnsStaySearchable(): void
    {
        $sql = $this->sql();

        $this->assertStringContainsString('items.chrono_label', $sql);
        $this->assertStringContainsString('items.chrono_period', $sql);
    }

    public function testNewActivationsDeclareTheIntegerType(): void
    {
        foreach (['chrono_from', 'chrono_to', 'chrono_certainty'] as $col) {
            $this->assertSame('INTEGER', \Config\FuzzyDate::COLUMNS[$col]);
        }
        $src = file_get_contents(__DIR__ . '/../../lib/Config/FuzzyDate.php');
        foreach (['chrono_from', 'chrono_to'] as $col) {
            $this->assertMatchesRegularExpression("/'{$col}'\\s*=> \\['name' => '{$col}'.*'db_type' => 'INTEGER'/", $src);
        }
    }
}
