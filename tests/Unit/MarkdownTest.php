<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Requests\Headers;
use CurlCffi\Requests\Response;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private static function response(string $html): Response
    {
        $r = new Response();
        $r->content = $html;
        $r->headers = new Headers(['Content-Type' => 'text/html; charset=utf-8']);

        return $r;
    }

    public function testArticleBecomesMarkdown(): void
    {
        $words = str_repeat('Plenty of words so readability scores this block as the article. ', 4);
        $md = self::response(
            '<html><head><title>Caf&eacute; &amp; tea</title><style>p { color: red }</style></head><body>'
            . '<nav><a href="/">Home</a> <a href="/menu">Menu</a></nav>'
            . "<article><h2>Opening hours</h2><p>We open at <b>eight</b>. {$words}</p>"
            . "<p>See the <a href=\"https://example.com/menu\">menu</a>. {$words}</p><ul><li>coffee</li><li>tea</li></ul></article>"
            . '<script>track()</script></body></html>'
        )->markdown();

        self::assertStringStartsWith("Café & tea\n==========\n", $md, 'title as the first heading, entities decoded');
        self::assertStringContainsString("Opening hours\n-------------", $md);
        self::assertStringContainsString('We open at **eight**.', $md);
        self::assertStringContainsString('[menu](https://example.com/menu)', $md);
        self::assertStringContainsString("- coffee\n- tea", $md);
        self::assertStringNotContainsString('track()', $md);
        self::assertStringNotContainsString('color: red', $md);
    }

    public function testShortPageFallsBackToTheBody(): void
    {
        $md = self::response('<html><head><title>Tiny</title></head><body><p>Just <i>one</i> line.</p></body></html>')->markdown();
        self::assertSame("Tiny\n====\n\nJust *one* line.", $md);
    }
}
