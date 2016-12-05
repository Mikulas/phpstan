<?php

// ok, result used only in assert
assert($a = foo());
assert($a != 'bar');

// not good, $b might not be initialized
assert($b = foo());
callWith($b);

// not good, but with reference
assert(preg_match('~\d~', $s, $matches));
callWith($matches);
