<?php

namespace Brigada\Hq\Support;

final class Release
{
    /** The short sha of HEAD, read from .git without running git; null when there is no .git folder. */
    public static function fromGit(string $basePath): ?string
    {
        try {
            $head = @file_get_contents($basePath.'/.git/HEAD');
            if ($head === false) {
                return null;
            }
            $head = trim($head);
            if (preg_match('/^[0-9a-f]{40}$/', $head)) {
                return substr($head, 0, 12);
            }
            if (! str_starts_with($head, 'ref: ')) {
                return null;
            }
            $ref = trim(substr($head, 5));
            $sha = @file_get_contents($basePath.'/.git/'.$ref);
            if ($sha !== false && preg_match('/^[0-9a-f]{40}$/', trim($sha))) {
                return substr(trim($sha), 0, 12);
            }
            $packed = @file_get_contents($basePath.'/.git/packed-refs');
            if ($packed !== false && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $m)) {
                return substr($m[1], 0, 12);
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
