<?php

use Ahinest\LaravelPermissionManager\Tests\Support\DocumentationInventory;

it('documents every named production method with its own descriptive PHPDoc', function () {
    $root = dirname(__DIR__, 2);
    $missing = [];
    $inspected = 0;
    foreach (['src', 'database/migrations'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (DocumentationInventory::methods(file_get_contents($file->getPathname())) as $method) {
                $inspected++;
                $description = preg_replace('/^\s*\*?\s*@[^\n]*/m', '', $method['doc'] ?? '');
                $description = trim(str_replace(['/**', '*/', '*'], '', $description));
                if ($method['doc'] === null || $description === '') {
                    $missing[] = $file->getPathname().':'.$method['line'].' '.$method['name'];
                }
            }
        }
    }
    expect($inspected)->toBeGreaterThan(0);
    expect($missing)->toBe([], implode("\n", $missing));
});

it('distinguishes own method documentation from closures properties and attributes', function () {
    $source = <<<'PHP'
<?php
interface Contract {
    /** Verifica el contrato. */
    public function check(): void;
}
trait Shared {
    /** Devuelve la referencia compartida. */
    protected static function &reference() {}
}
/** Este comentario de clase no documenta su constructor. */
class Example {
    public function __construct() {}
    /** Documentación de una propiedad, no del método siguiente. */
    private $value;
    private function missing() {}
    /** Ejecuta la operación decorada. */
    #[Marker(options: ['nested' => [1, 2]])]
    final public function decorated() { $closure = function () {}; $arrow = fn () => 1; }
}
return new class {
    /** Instala el esquema de la fixture. */
    public function up(): void {}
    public function down(): void {}
};
PHP;
    $methods = DocumentationInventory::methods($source);
    expect(array_column($methods, 'name'))->toBe(['check', 'reference', '__construct', 'missing', 'decorated', 'up', 'down']);
    expect(array_column(array_filter($methods, fn ($method) => $method['doc'] === null), 'name'))->toBe(['__construct', 'missing', 'down']);
});

it('provides both languages for every public document and README', function () {
    $root = dirname(__DIR__, 2);
    $documents = array_map('basename', glob($root.'/docs/*.md'));
    expect($documents)->not->toBeEmpty();
    expect(DocumentationInventory::missingPairs($documents))->toBe([]);
    expect(is_file($root.'/README.md') && is_file($root.'/README.es.md'))->toBeTrue();
});

it('detects missing English and Spanish counterparts independently', function () {
    expect(DocumentationInventory::missingPairs(['api.md', 'security.es.md', 'status.md', 'status.es.md']))
        ->toBe(['api.es.md', 'security.md']);
});
