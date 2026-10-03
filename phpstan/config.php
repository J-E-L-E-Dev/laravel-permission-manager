<?php

// Older Larastan lacks the BelongsTo stub declared by newer MorphTo.stub.
$stubs = [__DIR__.'/rbac-models.stub'];
$morphTo = __DIR__.'/../vendor/larastan/larastan/stubs/common/MorphTo.stub';
if (! is_file($morphTo) || ! preg_match('/class\s+BelongsTo\b/', file_get_contents($morphTo))) {
    $stubs[] = __DIR__.'/legacy-belongs-to.stub';
}

return ['parameters' => ['stubFiles' => $stubs]];
