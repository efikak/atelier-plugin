<?php

declare(strict_types=1);

namespace WPQuizStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPQuizStudio\Security\EmbedPolicy;

final class EmbedPolicyTest extends TestCase
{
    public function testExactAndSubdomainMatchingDoesNotAcceptLookalikeDomains(): void
    {
        $policy = new EmbedPolicy();
        self::assertTrue($policy->hostAllowed('example.gr', ['example.gr'], true));
        self::assertTrue($policy->hostAllowed('news.example.gr', ['example.gr'], true));
        self::assertFalse($policy->hostAllowed('evil-example.gr', ['example.gr'], true));
        self::assertFalse($policy->hostAllowed('example.gr.evil.com', ['example.gr'], true));
    }
}
