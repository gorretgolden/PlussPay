<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UiCopyTest extends TestCase
{
    /**
     * The interface is read on screens, not in documents, so it uses plain punctuation.
     * A hyphen inside a permission slug like view-transactions is not a dash.
     */
    public function test_views_contain_no_em_or_en_dashes(): void
    {
        $offenders = [];

        $views = dirname(__DIR__, 2).'/resources/views';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/.{0,40}[—–].{0,40}/u', file_get_contents($file->getPathname()), $hits);
            foreach ($hits[0] as $hit) {
                $offenders[] = substr($file->getPathname(), strlen($views) + 1).': '.$hit;
            }
        }

        $this->assertSame([], $offenders, "Use a comma, brackets or 'to' instead. Found:\n".implode("\n", $offenders));
    }

    /**
     * Windows has no flag glyphs, so a flag emoji draws as two bare country letters:
     * "Uganda" arrives on screen as "UG", which reads as a fault rather than a place.
     * Country names are written out in full instead.
     */
    public function test_views_contain_no_flag_emoji(): void
    {
        $offenders = [];

        $views = dirname(__DIR__, 2).'/resources/views';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/.{0,30}[\x{1F1E6}-\x{1F1FF}].{0,30}/u', file_get_contents($file->getPathname()), $hits);
            foreach ($hits[0] as $hit) {
                $offenders[] = substr($file->getPathname(), strlen($views) + 1).': '.$hit;
            }
        }

        $this->assertSame([], $offenders, "Write the country name instead. Found:\n".implode("\n", $offenders));
    }
}
