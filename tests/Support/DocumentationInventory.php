<?php

namespace Ahinest\LaravelPermissionManager\Tests\Support;

use PhpToken;

final class DocumentationInventory
{
    /** Identifica funciones nombradas y su PHPDoc propio sin cargar clases ni ejecutar migraciones. */
    public static function methods(string $source): array
    {
        $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        $methods = [];
        foreach ($tokens as $i => $token) {
            if ($token->id !== T_FUNCTION) {
                continue;
            }
            $next = $i + 1;
            while ($tokens[$next]->isIgnorable() || $tokens[$next]->text === '&') {
                $next++;
            }
            if ($tokens[$next]->id !== T_STRING) {
                continue;
            }
            $doc = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($tokens[$j]->id === T_DOC_COMMENT) {
                    $doc = $tokens[$j]->text;
                    break;
                }
                if ($tokens[$j]->id === T_WHITESPACE || in_array($tokens[$j]->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    continue;
                }
                // Los atributos pertenecen al método, pero su contenido no puede aportar PHPDoc.
                if ($tokens[$j]->text === ']') {
                    $depth = 1;
                    while (--$j >= 0) {
                        if ($tokens[$j]->text === ']') {
                            $depth++;
                        }
                        if ($tokens[$j]->text === '[' || $tokens[$j]->id === T_ATTRIBUTE) {
                            $depth--;
                        }
                        if ($depth === 0) {
                            break;
                        }
                    }

                    continue;
                }
                break;
            }
            $methods[] = ['name' => $tokens[$next]->text, 'line' => $token->line, 'doc' => $doc];
        }

        return $methods;
    }

    /** Devuelve los nombres de contrapartes ausentes, comprobando ambas direcciones. */
    public static function missingPairs(array $names): array
    {
        $missing = [];
        foreach ($names as $name) {
            $pair = str_ends_with($name, '.es.md') ? substr($name, 0, -6).'.md' : substr($name, 0, -3).'.es.md';
            if (! in_array($pair, $names, true)) {
                $missing[] = $pair;
            }
        }

        return array_values(array_unique($missing));
    }
}
