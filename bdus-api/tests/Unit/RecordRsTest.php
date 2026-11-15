<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Record\Rs;

/** The relation codes of the Harris matrix: names, inverses. */
class RecordRsTest extends TestCase
{
    public function testEveryCodeHasANameAndAnInverseThatComesBack(): void
    {
        $this->assertSame(range(1, 10), array_keys(Rs::RELATIONS));
        foreach (array_keys(Rs::RELATIONS) as $code) {
            $this->assertTrue(Rs::isRelation($code));
            $this->assertSame($code, Rs::inverse(Rs::inverse($code)), "inverse of the inverse of {$code}");
        }
    }

    public function testInversePairsAndSymmetricRelations(): void
    {
        // is covered by ↔ covers, is cut by ↔ cuts, carries ↔ leans against, is filled by ↔ fills
        $this->assertSame([5, 6, 7, 8], array_map([Rs::class, 'inverse'], [1, 2, 3, 4]));
        $this->assertSame([1, 2, 3, 4], array_map([Rs::class, 'inverse'], [5, 6, 7, 8]));
        // the same as, is bound to: the same reading from both sides
        $this->assertSame(9, Rs::inverse(9));
        $this->assertSame(10, Rs::inverse(10));
    }

    public function testOtherNumbersAreNotRelations(): void
    {
        foreach ([0, 11, -1, 100] as $code) {
            $this->assertFalse(Rs::isRelation($code));
        }
    }
}
