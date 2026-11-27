<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a blocked account.
 *
 * The two applications have to agree on this while they run side by side,
 * and the old one compares loosely: dektrium's User model asks
 * `$this->blocked_at != null`, which in PHP is false for 0. Its block()
 * writes `time()` and its unblock() writes null, so a stored 0 is not a
 * block — it is a column that was written once and never meant anything.
 *
 * Production carries such rows. A strict null check here refused those
 * accounts outright: they signed in on the old app and were turned away by
 * this one, with a message about deactivation and nothing in the data a
 * support person could point at. Clearing the zeros is not the fix, because
 * the next import brings them back.
 *
 * No database: the question is entirely about how the column is read.
 */
class UserBlockedTest extends TestCase
{
    private function userWith(mixed $blockedAt): User
    {
        return (new User)->forceFill(['blocked_at' => $blockedAt]);
    }

    public function test_a_null_is_not_blocked(): void
    {
        $this->assertFalse($this->userWith(null)->isBlocked());
    }

    /** The case that was wrong. */
    public function test_a_zero_is_not_blocked(): void
    {
        $this->assertFalse($this->userWith(0)->isBlocked(),
            'The old application reads 0 as not blocked, so this one must too.');
    }

    /** A zero that arrived from the driver as a string is still a zero. */
    public function test_a_zero_string_is_not_blocked(): void
    {
        $this->assertFalse($this->userWith('0')->isBlocked());
    }

    public function test_a_timestamp_is_blocked(): void
    {
        $this->assertTrue($this->userWith(1763990000)->isBlocked(),
            'A positive timestamp is what block() writes, in both applications.');
    }

    /** The smallest value block() could plausibly have written. */
    public function test_any_positive_value_is_blocked(): void
    {
        $this->assertTrue($this->userWith(1)->isBlocked());
    }
}
